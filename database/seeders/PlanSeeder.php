<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/** Starter plans. Idempotent: existing plans (by name) are left alone so edits are never overwritten. */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $all = array_keys(SubscriptionPlan::MODULES);

        $plans = [
            ['name' => 'Starter', 'description' => 'One small study room.', 'price_monthly' => 499, 'price_quarterly' => 1399, 'price_annual' => 4999,
                'trial_days' => 14, 'max_students' => 100, 'max_seats' => 50, 'max_staff' => 3, 'modules' => ['attendance'], 'grace_days' => 7],
            ['name' => 'Standard', 'description' => 'A growing library with a small team.', 'price_monthly' => 999, 'price_quarterly' => 2799, 'price_annual' => 9999,
                'trial_days' => 14, 'max_students' => 500, 'max_seats' => 250, 'max_staff' => 10, 'modules' => $all, 'grace_days' => 7],
            ['name' => 'Premium', 'description' => 'Large multi-floor libraries.', 'price_monthly' => 1999, 'price_quarterly' => 5499, 'price_annual' => 19999,
                'trial_days' => 14, 'max_students' => null, 'max_seats' => null, 'max_staff' => null, 'modules' => $all, 'grace_days' => 10],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::firstOrCreate(['name' => $plan['name']], $plan);
        }
    }
}
