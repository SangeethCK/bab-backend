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

        $businessStart = Carbon::parse("{$date} 09:00:00");
        $businessEnd = Carbon::parse("{$date} 18:00:00");

        $availableSlots = [];

        foreach ($employees as $employee) {
            $pivot = $employee->services()->where('service_id', $serviceId)->first()?->pivot;
            $duration = $pivot?->custom_duration_minutes ?? $service->duration_minutes;
            $price = $pivot?->custom_price ?? $service->price;

            $currentSlot = $businessStart->copy();

            while ($currentSlot->copy()->addMinutes($duration)->lte($businessEnd)) {
                $slotStart = $currentSlot->copy();
                $slotEnd = $currentSlot->copy()->addMinutes($duration);

                // Check for overlapping active bookings for this employee
                $hasOverlap = Booking::where('employee_id', $employee->id)
                    ->whereIn('status', ['scheduled', 'checked_in', 'in_progress'])
                    ->where(function ($q) use ($slotStart, $slotEnd) {
                        $q->where('start_time', '<', $slotEnd)
                          ->where('end_time', '>', $slotStart);
                    })
                    ->exists();

                if (!$hasOverlap) {
                    $availableSlots[] = [
                        'employee_id' => $employee->id,
                        'employee_name' => trim("{$employee->first_name} {$employee->last_name}"),
                        'start_time' => $slotStart->toIso8601String(),
                        'end_time' => $slotEnd->toIso8601String(),
                        'duration_minutes' => $duration,
                        'price' => (float) $price,
                    ];
                }

                $currentSlot->addMinutes(30); // 30-minute interval step
            }
        }

        return $availableSlots;
    }

    /**
     * Create a new booking with database-level double-booking prevention.
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

            // Double Booking Prevention: Pessimistic Lock & Overlap Check
            $overlappingBooking = Booking::where('employee_id', $employee->id)
                ->whereIn('status', ['scheduled', 'checked_in', 'in_progress'])
                ->where(function ($q) use ($startTime, $endTime) {
                    $q->where('start_time', '<', $endTime)
                      ->where('end_time', '>', $startTime);
                })
                ->lockForUpdate()
                ->first();

            if ($overlappingBooking) {
                throw new \InvalidArgumentException("Double booking conflict: Employee {$employee->first_name} is already booked from {$overlappingBooking->start_time->format('H:i')} to {$overlappingBooking->end_time->format('H:i')}.");
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

            // Double Booking check excluding current booking ID
            $overlappingBooking = Booking::where('employee_id', $employeeId)
                ->where('id', '!=', $booking->id)
                ->whereIn('status', ['scheduled', 'checked_in', 'in_progress'])
                ->where(function ($q) use ($newStart, $newEnd) {
                    $q->where('start_time', '<', $newEnd)
                      ->where('end_time', '>', $newStart);
                })
                ->lockForUpdate()
                ->first();

            if ($overlappingBooking) {
                throw new \InvalidArgumentException("Double booking conflict: Target slot overlaps with existing booking {$overlappingBooking->booking_code}.");
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
        $allowedTransitions = [
            'scheduled' => ['checked_in', 'cancelled'],
            'checked_in' => ['in_progress', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];

        if (!in_array($newStatus, $allowedTransitions[$booking->status] ?? [])) {
            throw new \InvalidArgumentException("Invalid status transition from '{$booking->status}' to '{$newStatus}'.");
        }

        $oldValues = $booking->toArray();

        $updateData = ['status' => $newStatus];
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
