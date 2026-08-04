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
}
