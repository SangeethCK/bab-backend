<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingConflictsFeatureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Customer $customer;
    private Employee $employee;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Elite Barber', 'slug' => 'elite', 'status' => 'active']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Barber Owner',
            'email' => 'owner@elite.com',
            'password' => bcrypt('password123'),
        ]);

        TenantContext::setTenant($this->tenant);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'customer_code' => 'CUST-0001',
            'name' => 'Alex Turner',
            'mobile' => '123456789',
        ]);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Sam',
            'last_name' => 'Stylist',
            'email' => 'sam@elite.com',
            'phone' => '987654321',
            'status' => 'active',
        ]);

        $this->service = Service::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Premium Cut & Beard',
            'duration_minutes' => 60,
            'price' => 50.00,
        ]);

        // Attach service to employee
        $this->employee->services()->attach($this->service->id);
    }

    public function test_cannot_create_overlapping_booking_for_same_employee(): void
    {
        $startTime = Carbon::parse('2026-09-01 10:00:00');

        // Create first booking 10:00 - 11:00
        $firstBooking = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/bookings', [
                'customer_id' => $this->customer->id,
                'employee_id' => $this->employee->id,
                'service_id' => $this->service->id,
                'start_time' => $startTime->toIso8601String(),
            ]);

        $firstBooking->assertStatus(201);

        // Attempt overlapping booking for same employee 10:30 - 11:30
        $overlappingStart = Carbon::parse('2026-09-01 10:30:00');
        $conflictResponse = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/bookings', [
                'customer_id' => $this->customer->id,
                'employee_id' => $this->employee->id,
                'service_id' => $this->service->id,
                'start_time' => $overlappingStart->toIso8601String(),
            ]);

        $conflictResponse->assertStatus(409);
        $conflictResponse->assertJsonFragment(['success' => false]);
    }

    public function test_reschedule_to_conflicting_slot_is_rejected(): void
    {
        $slot1 = Carbon::parse('2026-09-02 14:00:00');
        $slot2 = Carbon::parse('2026-09-02 15:00:00');

        // Booking 1: 14:00 - 15:00
        $booking1 = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/bookings', [
                'customer_id' => $this->customer->id,
                'employee_id' => $this->employee->id,
                'service_id' => $this->service->id,
                'start_time' => $slot1->toIso8601String(),
            ])->json('data.id');

        // Booking 2: 15:00 - 16:00
        $booking2 = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/bookings', [
                'customer_id' => $this->customer->id,
                'employee_id' => $this->employee->id,
                'service_id' => $this->service->id,
                'start_time' => $slot2->toIso8601String(),
            ])->json('data.id');

        // Try rescheduling Booking 2 to 14:30 (overlaps with Booking 1: 14:00-15:00)
        $rescheduleResponse = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->patchJson("/api/v1/bookings/{$booking2}/reschedule", [
                'start_time' => Carbon::parse('2026-09-02 14:30:00')->toIso8601String(),
            ]);

        $rescheduleResponse->assertStatus(409);
    }
}
