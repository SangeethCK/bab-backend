<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Chair;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class ChairService
{
    /**
     * Get live visual grid status of all chairs for POS dashboard.
     */
    public function getLiveDashboard(?string $dateStr = null): array
    {
        $date = $dateStr ? Carbon::parse($dateStr) : Carbon::today();
        $now = Carbon::now();

        $chairs = Chair::with(['employee:id,first_name,last_name,designation,phone,status'])
            ->orderBy('sort_order', 'asc')
            ->orderBy('chair_number', 'asc')
            ->get();

        // Get all non-cancelled bookings for the selected date
        $bookings = Booking::with([
            'customer:id,customer_code,name,mobile',
            'service:id,name,duration_minutes,price',
            'employee:id,first_name,last_name',
        ])
            ->whereDate('start_time', $date->toDateString())
            ->where('status', '!=', 'cancelled')
            ->orderBy('start_time', 'asc')
            ->get();

        $tiles = [];
        $summary = [
            'total_chairs' => $chairs->count(),
            'available' => 0,
            'occupied' => 0,
            'checked_in' => 0,
            'maintenance' => 0,
            'unassigned' => 0,
        ];

        foreach ($chairs as $chair) {
            // Find bookings associated with this chair (direct chair_id match, or employee fallback if chair_id null)
            $chairBookings = $bookings->filter(function (Booking $b) use ($chair) {
                if ($b->chair_id) {
                    return $b->chair_id === $chair->id;
                }
                return $chair->employee_id && $b->employee_id === $chair->employee_id;
            });

            // Find active booking running right now
            $activeBooking = $chairBookings->first(function (Booking $b) use ($now) {
                if (in_array($b->status, ['in_progress', 'checked_in'])) {
                    return true;
                }
                return $b->status === 'scheduled' && $now->between($b->start_time, $b->end_time);
            });

            // Find next upcoming booking today
            $nextBooking = $chairBookings->first(function (Booking $b) use ($now, $activeBooking) {
                if ($activeBooking && $b->id === $activeBooking->id) {
                    return false;
                }
                return in_array($b->status, ['scheduled', 'checked_in']) && $b->start_time->greaterThanOrEqualTo($now);
            });

            // Calculate live status of the chair tile
            if ($chair->status === 'maintenance') {
                $liveStatus = 'maintenance';
                $summary['maintenance']++;
            } elseif (!$chair->is_active || $chair->status === 'inactive') {
                $liveStatus = 'inactive';
            } elseif ($activeBooking) {
                if ($activeBooking->status === 'in_progress') {
                    $liveStatus = 'occupied';
                    $summary['occupied']++;
                } elseif ($activeBooking->status === 'checked_in') {
                    $liveStatus = 'checked_in';
                    $summary['checked_in']++;
                } else {
                    $liveStatus = 'occupied';
                    $summary['occupied']++;
                }
            } elseif (!$chair->employee_id) {
                $liveStatus = 'unassigned';
                $summary['unassigned']++;
            } else {
                $liveStatus = 'available';
                $summary['available']++;
            }

            // Completed bookings count today on this chair
            $completedToday = $chairBookings->where('status', 'completed')->count();
            $upcomingToday = $chairBookings->whereIn('status', ['scheduled', 'checked_in'])->count();

            $activeBookingPayload = null;
            if ($activeBooking) {
                $startTime = Carbon::parse($activeBooking->start_time);
                $endTime = Carbon::parse($activeBooking->end_time);
                $elapsedMinutes = max(0, (int) $startTime->diffInMinutes($now, false));
                $remainingMinutes = max(0, (int) $now->diffInMinutes($endTime, false));

                $activeBookingPayload = [
                    'id' => $activeBooking->id,
                    'booking_code' => $activeBooking->booking_code,
                    'status' => $activeBooking->status,
                    'customer' => $activeBooking->customer ? [
                        'id' => $activeBooking->customer->id,
                        'name' => $activeBooking->customer->name,
                        'mobile' => $activeBooking->customer->mobile,
                    ] : null,
                    'service' => $activeBooking->service ? [
                        'id' => $activeBooking->service->id,
                        'name' => $activeBooking->service->name,
                        'duration_minutes' => $activeBooking->service->duration_minutes,
                        'price' => $activeBooking->service->price,
                    ] : null,
                    'start_time' => $startTime->toIso8601String(),
                    'end_time' => $endTime->toIso8601String(),
                    'elapsed_minutes' => $elapsedMinutes,
                    'remaining_minutes' => $remainingMinutes,
                ];
            }

            $nextBookingPayload = null;
            if ($nextBooking) {
                $nextBookingPayload = [
                    'id' => $nextBooking->id,
                    'booking_code' => $nextBooking->booking_code,
                    'status' => $nextBooking->status,
                    'customer_name' => $nextBooking->customer?->name,
                    'service_name' => $nextBooking->service?->name,
                    'start_time' => Carbon::parse($nextBooking->start_time)->toIso8601String(),
                    'end_time' => Carbon::parse($nextBooking->end_time)->toIso8601String(),
                ];
            }

            $statusConfig = match ($liveStatus) {
                'occupied' => [
                    'label' => 'Occupied',
                    'color' => '#EF4444',       // Red
                    'border_color' => '#DC2626',
                    'bg_color' => '#FEE2E2',
                ],
                'available' => [
                    'label' => 'Vacant',
                    'color' => '#10B981',       // Green
                    'border_color' => '#059669',
                    'bg_color' => '#D1FAE5',
                ],
                'checked_in' => [
                    'label' => 'Checked In',
                    'color' => '#F59E0B',       // Amber
                    'border_color' => '#D97706',
                    'bg_color' => '#FEF3C7',
                ],
                'maintenance' => [
                    'label' => 'Maintenance',
                    'color' => '#6B7280',       // Gray
                    'border_color' => '#4B5563',
                    'bg_color' => '#F3F4F6',
                ],
                'unassigned' => [
                    'label' => 'No Barber',
                    'color' => '#8B5CF6',       // Purple
                    'border_color' => '#7C3AED',
                    'bg_color' => '#EDE9FE',
                ],
                default => [
                    'label' => 'Inactive',
                    'color' => '#9CA3AF',
                    'border_color' => '#6B7280',
                    'bg_color' => '#F9FAFB',
                ],
            };

            $tiles[] = [
                'id' => $chair->id,
                'name' => $chair->name,
                'chair_number' => $chair->chair_number,
                'status' => $liveStatus,
                'status_label' => $statusConfig['label'],
                'status_color' => $statusConfig['color'],
                'border_color' => $statusConfig['border_color'],
                'bg_color' => $statusConfig['bg_color'],
                'configured_status' => $chair->status,
                'sort_order' => $chair->sort_order,
                'is_active' => $chair->is_active,
                'employee' => $chair->employee ? [
                    'id' => $chair->employee->id,
                    'name' => trim($chair->employee->first_name . ' ' . $chair->employee->last_name),
                    'designation' => $chair->employee->designation,
                    'status' => $chair->employee->status,
                ] : null,
                'active_booking' => $activeBookingPayload,
                'next_booking' => $nextBookingPayload,
                'stats_today' => [
                    'completed' => $completedToday,
                    'upcoming' => $upcomingToday,
                ],
            ];
        }

        return [
            'date' => $date->toDateString(),
            'timestamp' => $now->toIso8601String(),
            'summary' => $summary,
            'chairs' => $tiles,
        ];
    }

    /**
     * Assign or unassign employee to a chair.
     */
    public function assignEmployee(Chair $chair, ?int $employeeId): Chair
    {
        if ($employeeId !== null) {
            $employee = Employee::findOrFail($employeeId);
            $chair->employee_id = $employee->id;
        } else {
            $chair->employee_id = null;
        }

        $chair->save();
        return $chair->load('employee');
    }
}
