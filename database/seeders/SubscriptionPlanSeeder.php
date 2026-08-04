<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter Plan',
                'slug' => 'starter',
                'price_monthly' => 29.00,
                'price_yearly' => 290.00,
                'max_users' => 5,
                'features' => ['bookings', 'customers', 'basic_reports'],
                'is_active' => true,
            ],
            [
                'name' => 'Pro Business',
                'slug' => 'pro',
                'price_monthly' => 79.00,
                'price_yearly' => 790.00,
                'max_users' => 20,
                'features' => ['bookings', 'customers', 'employees', 'services', 'invoices', 'expenses', 'audit_logs'],
                'is_active' => true,
            ],
            [
                'name' => 'Enterprise Suite',
                'slug' => 'enterprise',
                'price_monthly' => 199.00,
                'price_yearly' => 1990.00,
                'max_users' => 100,
                'features' => ['all_features', 'dedicated_support', 'custom_domain'],
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
