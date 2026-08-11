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

class BookingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Customer $customer;
    protected Employee $employee;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Elite Salon', 'slug' => 'elite-salon']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Receptionist Admin',
            'email' => 'reception@elitesalon.com',
            'password' => bcrypt('password'),
        ]);

        TenantContext::setTenant($this->tenant);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'customer_code' => 'CUST-0001',
            'name' => 'Michael Scott',
            'mobile' => '555000111',
        ]);

        $this->service = Service::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Executive Haircut',
            'duration_minutes' => 30,
            'price' => 45.00,
        ]);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Dwight',
            'last_name' => 'Schrute',
            'phone' => '555222333',
        ]);

        $this->employee->services()->attach($this->service->id);
    }

    public function test_available_slots_calculation(): void
    {
        $targetDate = now()->addDay()->format('Y-m-d');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/bookings/available-slots?service_id={$this->service->id}&date={$targetDate}");

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['date', 'total_available', 'slots']]);
        $this->assertGreaterThan(0, $response->json('data.total_available'));
    }

    public function test_create_booking_successfully(): void
    {
        $startTime = now()->addDay()->setHour(10)->setMinute(0)->setSecond(0)->toIso8601String();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'customer_id' => $this->customer->id,
                'employee_id' => $this->employee->id,
                'service_id' => $this->service->id,
                'start_time' => $startTime,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'scheduled');
        $response->assertJsonPath('data.booking_code', 'BKG-00001');
    }

    public function test_double_booking_prevention_returns_409_conflict(): void
    {
        $startTime = now()->addDay()->setHour(11)->setMinute(0)->setSecond(0)->toIso8601String();

        // 1. Create initial booking
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'customer_id' => $this->customer->id,
                'employee_id' => $this->employee->id,
                'service_id' => $this->service->id,
                'start_time' => $startTime,
            ]);

        // 2. Attempt overlapping booking for same employee
        $overlappingStart = now()->addDay()->setHour(11)->setMinute(15)->setSecond(0)->toIso8601String();

        $conflictResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'customer_id' => $this->customer->id,
                'employee_id' => $this->employee->id,
                'service_id' => $this->service->id,
                'start_time' => $overlappingStart,
            ]);

        $conflictResponse->assertStatus(409);
        $conflictResponse->assertJsonPath('success', false);
    }

    public function test_booking_lifecycle_status_flow(): void
    {
        $startTime = now()->addDay()->setHour(14)->setMinute(0)->setSecond(0);
        $booking = Booking::create([
            'tenant_id' => $this->tenant->id,
            'booking_code' => 'BKG-00002',
            'customer_id' => $this->customer->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'start_time' => $startTime,
            'end_time' => $startTime->copy()->addMinutes(30),
            'status' => 'scheduled',
            'total_price' => 45.00,
        ]);

        // 1. scheduled -> checked_in
        $res1 = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/bookings/{$booking->id}/status", ['status' => 'checked_in']);
        $res1->assertStatus(200);
        $res1->assertJsonPath('data.status', 'checked_in');

        // 2. checked_in -> in_progress
        $res2 = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/bookings/{$booking->id}/status", ['status' => 'in_progress']);
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.status', 'in_progress');

        // 3. in_progress -> completed
        $res3 = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/bookings/{$booking->id}/status", ['status' => 'completed']);
        $res3->assertStatus(200);
        $res3->assertJsonPath('data.status', 'completed');
    }

    public function test_reschedule_booking(): void
    {
        $startTime = now()->addDay()->setHour(15)->setMinute(0)->setSecond(0);
        $booking = Booking::create([
            'tenant_id' => $this->tenant->id,
            'booking_code' => 'BKG-00003',
            'customer_id' => $this->customer->id,
            'employee_id' => $this->employee->id,
            'service_id' => $this->service->id,
            'start_time' => $startTime,
            'end_time' => $startTime->copy()->addMinutes(30),
            'status' => 'scheduled',
            'total_price' => 45.00,
        ]);

        $newTime = now()->addDays(2)->setHour(16)->setMinute(0)->setSecond(0)->toIso8601String();

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/bookings/{$booking->id}/reschedule", [
                'start_time' => $newTime,
            ]);

        $response->assertStatus(200);
        $this->assertEquals(
            Carbon::parse($newTime)->timestamp,
            Carbon::parse($response->json('data.start_time'))->timestamp
        );
    }

    public function test_calendar_views(): void
    {
        $targetDate = now()->format('Y-m-d');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/bookings/calendar?view=daily&date={$targetDate}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.view', 'daily');
    }

    public function test_booking_outside_employee_working_hours_fails(): void
    {
        // Employee default hours: 09:00:00 - 18:00:00
        // Attempt to book at 07:00 AM (outside shift)
        $earlyStartTime = now()->addDay()->setHour(7)->setMinute(0)->setSecond(0)->toIso8601String();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'customer_id' => $this->customer->id,
                'employee_id' => $this->employee->id,
                'service_id' => $this->service->id,
                'start_time' => $earlyStartTime,
            ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('outside employee working hours', $response->json('message'));
    }

    public function test_custom_employee_working_hours_slots_and_booking_validation(): void
    {
        // Set custom shift: 10:00 to 14:00
        $this->employee->update([
            'work_start_time' => '10:00:00',
            'work_end_time' => '14:00:00',
        ]);

        $targetDate = now()->addDay()->format('Y-m-d');

        // Check available slots
        $slotsResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/bookings/available-slots?service_id={$this->service->id}&date={$targetDate}&employee_id={$this->employee->id}");

        $slotsResponse->assertStatus(200);
        $slots = $slotsResponse->json('data.slots');

        // First slot should start at 10:00
        $this->assertNotEmpty($slots);
        $firstSlotStart = Carbon::parse($slots[0]['start_time'])->format('H:i');
        $this->assertEquals('10:00', $firstSlotStart);

        // Last slot end time should be <= 14:00
        $lastSlotEnd = Carbon::parse(end($slots)['end_time'])->format('H:i');
        $this->assertLessThanOrEqual('14:00', $lastSlotEnd);

        // Attempting to book at 14:00 (which finishes at 14:30, after shift end 14:00) should fail
        $lateStartTime = Carbon::parse("{$targetDate} 14:00:00")->toIso8601String();
        $failResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'customer_id' => $this->customer->id,
                'employee_id' => $this->employee->id,
                'service_id' => $this->service->id,
                'start_time' => $lateStartTime,
            ]);

        $failResponse->assertStatus(409);

        // Booking at 10:00 (finishes at 10:30, inside shift) should succeed
        $validStartTime = Carbon::parse("{$targetDate} 10:00:00")->toIso8601String();
        $successResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'customer_id' => $this->customer->id,
                'employee_id' => $this->employee->id,
                'service_id' => $this->service->id,
                'start_time' => $validStartTime,
            ]);

        $successResponse->assertStatus(201);
    }

    public function test_max_concurrent_bookings_capacity(): void
    {
        // Set barber max capacity to 2 clients per slot
        $this->employee->update(['max_concurrent_bookings' => 2]);

        $startTime = now()->addDay()->setHour(10)->setMinute(0)->setSecond(0)->toIso8601String();

        // 1st concurrent booking
        $res1 = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'customer_id' => $this->customer->id,
                'employee_id' => $this->employee->id,
                'service_id' => $this->service->id,
                'start_time' => $startTime,
            ]);
        $res1->assertStatus(201);

        // 2nd concurrent booking (allowed because capacity = 2)
        $res2 = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'customer_id' => $this->customer->id,
                'employee_id' => $this->employee->id,
                'service_id' => $this->service->id,
                'start_time' => $startTime,
            ]);
        $res2->assertStatus(201);

        // 3rd concurrent booking (exceeds capacity -> 409 Conflict)
        $res3 = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'customer_id' => $this->customer->id,
                'employee_id' => $this->employee->id,
                'service_id' => $this->service->id,
                'start_time' => $startTime,
            ]);
        $res3->assertStatus(409);
        $this->assertStringContainsString('reached maximum concurrent booking capacity', $res3->json('message'));
    }
}
