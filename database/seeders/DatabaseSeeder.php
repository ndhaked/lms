<?php

namespace Database\Seeders;

use App\Actions\ProvisionLibraryRoles;
use App\Actions\RegisterLibrary;
use App\Models\Library;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        ProvisionLibraryRoles::syncPermissions();
        $this->call(PlanSeeder::class);

        $admin = User::firstOrNew(['email' => 'admin@example.com']);
        $admin->fill(['name' => 'Super Admin', 'password' => 'password'])->save();
        $admin->forceFill(['is_super_admin' => true])->save();

        if (! Library::where('slug', 'demo-library')->exists()) {
            app(RegisterLibrary::class)(
                ['name' => 'Demo Library', 'slug' => 'demo-library', 'email' => 'demo@example.com'],
                ['name' => 'Demo Owner', 'email' => 'owner@example.com', 'password' => 'password'],
            );
        }

        // The demo library gets 60 days of free access so it is usable out of the box.
        $demo = Library::where('slug', 'demo-library')->first();
        if ($demo && ! \App\Models\Subscription::where('library_id', $demo->id)->exists()) {
            auth()->login($admin);
            app(\App\Actions\Billing\GrantFreePeriod::class)($demo, \App\Models\SubscriptionPlan::where('name', 'Standard')->firstOrFail(), 60, 'Demo data');
            auth()->logout();
        }
        // A second library with NO plan, to see the restricted state and the access-request flow.
        if (! Library::where('slug', 'no-access-library')->exists()) {
            app(RegisterLibrary::class)(
                ['name' => 'No Access Library', 'slug' => 'no-access-library', 'email' => 'noaccess@example.com', 'status' => 'active'],
                ['name' => 'No Access Owner', 'email' => 'noaccess@example.com', 'password' => 'password'],
            );
        }

        $this->call(DemoBooksSeeder::class);
    }
}
