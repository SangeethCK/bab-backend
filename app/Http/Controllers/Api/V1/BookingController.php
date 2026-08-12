<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use ApiResponse;

    protected BookingService $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    /**
     * Listing of bookings with filters (date range, status, employee, customer).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Booking::with('customer', 'employee', 'service');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->input('employee_id'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->filled('date')) {
            $date = $request->input('date');
            $query->whereDate('start_time', $date);
        }

        $bookings = $query->orderBy('start_time', 'asc')
            ->paginate($request->get('per_page', 20));

        return $this->successResponse($bookings, 'Bookings retrieved successfully.');
    }

    /**
     * Store a new booking with double booking prevention.
     */
    public function store(Request $request): JsonResponse
    {
        $customerId = $request->input('customer_id');

        // Auto-resolve or create Customer record for guest or authenticated user
        if (!$customerId || !\App\Models\Customer::where('id', $customerId)->exists()) {
            $user = $request->user();
            if ($user) {
                $customer = \App\Models\Customer::where('email', $user->email)
                    ->orWhere('mobile', $user->phone)
                    ->first();
            } else {
                $phone = $request->input('customer_phone') ?? $request->input('phone') ?? '9876543210';
                $name = $request->input('customer_name') ?? $request->input('name') ?? 'Valued Customer';
                $email = $request->input('customer_email') ?? $request->input('email');
                $tenantId = \App\Services\TenantContext::getTenantId();

                $customer = \App\Models\Customer::where('mobile', $phone)->first();
                if (!$customer) {
                    $customer = \App\Models\Customer::create([
                        'tenant_id' => $tenantId,
                        'customer_code' => \App\Models\Customer::generateNextCustomerCode($tenantId),
                        'name' => $name,
                        'email' => $email,
                        'mobile' => $phone,
                    ]);
                }
            }

            if (isset($customer) && $customer) {
                $request->merge(['customer_id' => $customer->id]);
            }
        }

        // Auto-format start_time from booking_date and booking_time if missing
        if (!$request->has('start_time') && $request->has('booking_date') && $request->has('booking_time')) {
            $timeStr = $request->input('booking_time');
            if (strlen($timeStr) === 5) $timeStr .= ':00';
            $request->merge(['start_time' => $request->input('booking_date') . ' ' . $timeStr]);
        }

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'employee_id' => 'required|exists:employees,id',
            'service_id' => 'required|exists:services,id',
            'start_time' => 'required|date',
            'total_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        try {
            $booking = $this->bookingService->createBooking($validated);
            return $this->successResponse($booking, 'Booking created successfully.', 201);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 409);
        }
    }

    /**
     * Display single booking details.
     */
    public function show(Booking $booking): JsonResponse
    {
        return $this->successResponse($booking->load('customer', 'employee', 'service'), 'Booking details retrieved.');
    }

    /**
     * Reschedule an existing booking.
     */
    public function reschedule(Request $request, Booking $booking): JsonResponse
    {
        $validated = $request->validate([
            'start_time' => 'required|date',
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        try {
            $updatedBooking = $this->bookingService->rescheduleBooking(
                $booking,
                $validated['start_time'],
                $validated['employee_id'] ?? null
            );
            return $this->successResponse($updatedBooking, 'Booking rescheduled successfully.');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 409);
        }
    }

    /**
     * Update booking status along the lifecycle state flow.
     */
    public function updateStatus(Request $request, Booking $booking): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:scheduled,checked_in,in_progress,completed,complete,cancelled',
            'cancellation_reason' => 'nullable|string',
        ]);

        try {
            $updatedBooking = $this->bookingService->updateStatus(
                $booking,
                $validated['status'],
                $validated['cancellation_reason'] ?? null
            );
            return $this->successResponse($updatedBooking, "Booking status updated to '{$validated['status']}'.");
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Cancel booking.
     */
    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $validated = $request->validate([
            'cancellation_reason' => 'nullable|string',
        ]);

        try {
            $cancelledBooking = $this->bookingService->updateStatus(
                $booking,
                'cancelled',
                $validated['cancellation_reason'] ?? null
            );
            return $this->successResponse($cancelledBooking, 'Booking cancelled successfully.');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Calculate available time slots for a service and date.
     */
    public function availableSlots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'date' => 'required|date_format:Y-m-d',
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $slots = $this->bookingService->calculateAvailableSlots(
            (int) $validated['service_id'],
            $validated['date'],
            isset($validated['employee_id']) ? (int) $validated['employee_id'] : null
        );

        return $this->successResponse([
            'date' => $validated['date'],
            'total_available' => count($slots),
            'slots' => $slots,
        ], 'Available slots calculated.');
    }

    /**
     * Calendar aggregate endpoint for daily, weekly, and monthly views.
     */
    public function calendar(Request $request): JsonResponse
    {
        $view = $request->get('view', 'daily');
        $query = Booking::with('customer:id,name,mobile', 'employee:id,first_name,last_name', 'service:id,name,duration_minutes');

        if ($view === 'daily') {
            $date = $request->get('date', now()->format('Y-m-d'));
            $query->whereDate('start_time', $date);
        } elseif ($view === 'weekly') {
            $startDate = $request->get('start_date', now()->startOfWeek()->format('Y-m-d'));
            $endDate = $request->get('end_date', now()->endOfWeek()->format('Y-m-d'));
            $query->whereBetween('start_time', ["{$startDate} 00:00:00", "{$endDate} 23:59:59"]);
        } elseif ($view === 'monthly') {
            $year = $request->get('year', now()->year);
            $month = $request->get('month', now()->month);
            $query->whereYear('start_time', $year)->whereMonth('start_time', $month);
        }

        $bookings = $query->orderBy('start_time', 'asc')->get();

        return $this->successResponse([
            'view' => $view,
            'count' => $bookings->count(),
            'bookings' => $bookings,
        ], 'Calendar bookings retrieved.');
    }
}
