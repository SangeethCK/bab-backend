<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use ValidationException;

class BookingService
{
    /**
     * Calculate available time slots for a given service and date.
     */
    public function calculateAvailableSlots(int $serviceId, string $date, ?int $employeeId = null): array
    {
        $tenantId = TenantContext::getTenantId();
        $service = Service::findOrFail($serviceId);

        // Find qualified employees
        $employeesQuery = Employee::where('status', 'active')
            ->whereHas('services', function ($q) use ($serviceId) {
                $q->where('service_id', $serviceId);
            });

        if ($employeeId) {
            $employeesQuery->where('id', $employeeId);
        }

        $employees = $employeesQuery->get();

        if ($employees->isEmpty()) {
            return [];
        }

        $availableSlots = [];

        foreach ($employees as $employee) {
            $workStartStr = $employee->work_start_time ?? '08:00:00';
            $workEndStr = $employee->work_end_time ?? '21:00:00';

            $empStart = Carbon::parse("{$date} {$workStartStr}");
            $empEnd = Carbon::parse("{$date} {$workEndStr}");

            $pivot = $employee->services()->where('service_id', $serviceId)->first()?->pivot;
            $duration = $pivot?->custom_duration_minutes ?? $service->duration_minutes;
            $price = $pivot?->custom_price ?? $service->price;

            $currentSlot = $empStart->copy();

            while ($currentSlot->copy()->addMinutes($duration)->lte($empEnd)) {
                $slotStart = $currentSlot->copy();
                $slotEnd = $currentSlot->copy()->addMinutes($duration);

                // Check active bookings count against employee max_concurrent_bookings capacity
                $maxCapacity = $employee->max_concurrent_bookings ?? 1;
                $overlappingCount = Booking::where('employee_id', $employee->id)
                    ->whereIn('status', ['scheduled', 'checked_in', 'in_progress'])
                    ->where(function ($q) use ($slotStart, $slotEnd) {
                        $q->where('start_time', '<', $slotEnd)
                          ->where('end_time', '>', $slotStart);
                    })
                    ->count();

                $isAvailable = $overlappingCount < $maxCapacity;

                $availableSlots[] = [
                    'employee_id' => $employee->id,
                    'employee_name' => trim("{$employee->first_name} {$employee->last_name}"),
                    'start_time' => $slotStart->toIso8601String(),
                    'end_time' => $slotEnd->toIso8601String(),
                    'duration_minutes' => $duration,
                    'price' => (float) $price,
                    'available' => $isAvailable,
                    'status' => $isAvailable ? 'available' : 'booked',
                    'active_count' => $overlappingCount,
                    'max_capacity' => $maxCapacity,
                ];

                $currentSlot->addMinutes(30); // 30-minute interval step
            }
        }

        return $availableSlots;
    }

    /**
     * Create a new booking with database-level double-booking prevention and shift check.
     */
    public function createBooking(array $data): Booking
    {
        return DB::transaction(function () use ($data) {
            $tenantId = TenantContext::getTenantId();
            $service = Service::findOrFail($data['service_id']);
            $employee = Employee::findOrFail($data['employee_id']);

            $pivot = $employee->services()->where('service_id', $service->id)->first()?->pivot;
            $duration = $pivot?->custom_duration_minutes ?? $service->duration_minutes;
            $totalPrice = $data['total_price'] ?? ($pivot?->custom_price ?? $service->price);

            $startTime = Carbon::parse($data['start_time']);
            $endTime = $startTime->copy()->addMinutes($duration);

            // Employee Working Hours / Shift Check (timezone-aware)
            $tz = $startTime->getTimezone();
            $dateStr = $startTime->format('Y-m-d');
            $workStartStr = $employee->work_start_time ?? '08:00:00';
            $workEndStr = $employee->work_end_time ?? '21:00:00';

            $shiftStart = Carbon::parse("{$dateStr} {$workStartStr}", $tz);
            $shiftEnd = Carbon::parse("{$dateStr} {$workEndStr}", $tz);

            if ($startTime->lt($shiftStart) || $endTime->gt($shiftEnd)) {
                $formattedWorkStart = Carbon::parse($workStartStr)->format('H:i');
                $formattedWorkEnd = Carbon::parse($workEndStr)->format('H:i');
                throw new \InvalidArgumentException("Requested booking time is outside employee working hours ({$formattedWorkStart} - {$formattedWorkEnd}).");
            }

            // Capacity Check: Pessimistic Lock & Max Concurrent Capacity Validation
            $maxCapacity = $employee->max_concurrent_bookings ?? 1;
            $overlappingCount = Booking::where('employee_id', $employee->id)
                ->whereIn('status', ['scheduled', 'checked_in', 'in_progress'])
                ->where(function ($q) use ($startTime, $endTime) {
                    $q->where('start_time', '<', $endTime)
                      ->where('end_time', '>', $startTime);
                })
                ->lockForUpdate()
                ->count();

            if ($overlappingCount >= $maxCapacity) {
                throw new \InvalidArgumentException("Employee {$employee->first_name} has reached maximum concurrent booking capacity ({$maxCapacity}) for this time slot.");
            }

            $bookingCode = Booking::generateNextBookingCode($tenantId);

            $booking = Booking::create([
                'tenant_id' => $tenantId,
                'booking_code' => $bookingCode,
                'customer_id' => $data['customer_id'],
                'employee_id' => $employee->id,
                'service_id' => $service->id,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'status' => 'scheduled',
                'total_price' => $totalPrice,
                'notes' => $data['notes'] ?? null,
            ]);

            AuditLogger::log(
                action: 'booking_created',
                auditable: $booking,
                newValues: $booking->toArray()
            );

            return $booking->load('customer', 'employee', 'service');
        });
    }

    /**
     * Reschedule an existing booking.
     */
    public function rescheduleBooking(Booking $booking, string $newStartTimeStr, ?int $newEmployeeId = null): Booking
    {
        return DB::transaction(function () use ($booking, $newStartTimeStr, $newEmployeeId) {
            if (in_array($booking->status, ['completed', 'cancelled'])) {
                throw new \InvalidArgumentException("Cannot reschedule a {$booking->status} booking.");
            }

            $employeeId = $newEmployeeId ?? $booking->employee_id;
            $employee = Employee::findOrFail($employeeId);
            $service = Service::findOrFail($booking->service_id);

            $pivot = $employee->services()->where('service_id', $service->id)->first()?->pivot;
            $duration = $pivot?->custom_duration_minutes ?? $service->duration_minutes;

            $newStart = Carbon::parse($newStartTimeStr);
            $newEnd = $newStart->copy()->addMinutes($duration);

            // Employee Working Hours / Shift Check (timezone-aware)
            $tz = $newStart->getTimezone();
            $dateStr = $newStart->format('Y-m-d');
            $workStartStr = $employee->work_start_time ?? '08:00:00';
            $workEndStr = $employee->work_end_time ?? '21:00:00';

            $shiftStart = Carbon::parse("{$dateStr} {$workStartStr}", $tz);
            $shiftEnd = Carbon::parse("{$dateStr} {$workEndStr}", $tz);

            if ($newStart->lt($shiftStart) || $newEnd->gt($shiftEnd)) {
                $formattedWorkStart = Carbon::parse($workStartStr)->format('H:i');
                $formattedWorkEnd = Carbon::parse($workEndStr)->format('H:i');
                throw new \InvalidArgumentException("Target booking time is outside employee working hours ({$formattedWorkStart} - {$formattedWorkEnd}).");
            }

            // Concurrent capacity check excluding current booking ID
            $maxCapacity = $employee->max_concurrent_bookings ?? 1;
            $overlappingCount = Booking::where('employee_id', $employeeId)
                ->where('id', '!=', $booking->id)
                ->whereIn('status', ['scheduled', 'checked_in', 'in_progress'])
                ->where(function ($q) use ($newStart, $newEnd) {
                    $q->where('start_time', '<', $newEnd)
                      ->where('end_time', '>', $newStart);
                })
                ->lockForUpdate()
                ->count();

            if ($overlappingCount >= $maxCapacity) {
                throw new \InvalidArgumentException("Target time slot has reached maximum concurrent capacity ({$maxCapacity}) for this employee.");
            }

            $oldValues = $booking->toArray();

            $booking->update([
                'employee_id' => $employeeId,
                'start_time' => $newStart,
                'end_time' => $newEnd,
            ]);

            AuditLogger::log(
                action: 'booking_rescheduled',
                auditable: $booking,
                oldValues: $oldValues,
                newValues: $booking->toArray()
            );

            return $booking->load('customer', 'employee', 'service');
        });
    }

    /**
     * Transition booking status according to lifecycle state machine.
     */
    public function updateStatus(Booking $booking, string $newStatus, ?string $cancellationReason = null): Booking
    {
        if ($newStatus === 'complete') {
            $newStatus = 'completed';
        }

        $allowedTransitions = [
            'scheduled' => ['checked_in', 'in_progress', 'completed', 'cancelled'],
            'checked_in' => ['in_progress', 'completed', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
            'completed' => ['scheduled', 'in_progress'], // Admin correction support
            'cancelled' => ['scheduled'],
        ];

        if (!in_array($newStatus, $allowedTransitions[$booking->status] ?? [])) {
            throw new \InvalidArgumentException("Invalid status transition from '{$booking->status}' to '{$newStatus}'.");
        }

        $oldValues = $booking->toArray();

        $updateData = ['status' => $newStatus];
        if ($newStatus === 'checked_in' || $newStatus === 'in_progress') {
            $now = now();
            $duration = $booking->service?->duration_minutes ?? 30;
            $updateData['start_time'] = $now;
            $updateData['end_time'] = $now->copy()->addMinutes($duration);
        }
        if ($newStatus === 'cancelled' && $cancellationReason) {
            $updateData['cancellation_reason'] = $cancellationReason;
        }

        $booking->update($updateData);

        AuditLogger::log(
            action: "booking_status_{$newStatus}",
            auditable: $booking,
            oldValues: $oldValues,
            newValues: $booking->toArray()
        );

        return $booking->load('customer', 'employee', 'service');
    }
}
