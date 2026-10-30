<?php

namespace Tests\Feature;

use App\Actions\Billing\ApproveSubscriptionPayment;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\SubmitSubscriptionPayment;
use App\Actions\RegisterLibrary;
use App\Models\Library;
use App\Models\Student;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\LibraryInsights;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InsightsPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Library $lib;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->admin = $this->admin->fresh();
        $plan = SubscriptionPlan::create(['name' => 'Standard', 'price_monthly' => 1000, 'price_quarterly' => 2700, 'price_annual' => 10000, 'trial_days' => 0, 'max_students' => 50, 'max_seats' => 50, 'max_staff' => 5, 'modules' => ['attendance', 'reports'], 'grace_days' => 7]);

        $this->actingAs($this->admin);
        $this->lib = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@ins.test']);
        $this->owner = User::where('email', 'o@ins.test')->first();
        app(CreateSubscription::class)($this->lib, $plan, 'monthly', false);

        Tenancy::set($this->lib);
        $this->actingAs($this->owner);
        $p = app(SubmitSubscriptionPayment::class)($this->lib, $plan, 'monthly', 'ACT1', 1000, 'upi', today(), null, [UploadedFile::fake()->image('p.png')]);
        $this->actingAs($this->admin);
        app(ApproveSubscriptionPayment::class)($p);
        $this->actingAs($this->owner);
        LibraryInsights::flush();
    }

    protected function tearDown(): void
    {
        LibraryInsights::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    public function test_dashboard_and_insights_are_separate_pages_linked_to_each_other(): void
    {
        $this->get("/app/{$this->lib->slug}")->assertOk()->assertSee('View Insights')->assertSee('Dashboard insights')->assertSee('All report')->assertSee('Live members')
            ->assertSee("/app/{$this->lib->slug}/insights", false)->assertDontSee('Library health at a glance');

        $this->get("/app/{$this->lib->slug}/insights")->assertOk()->assertSee('Library health at a glance')->assertSee('Member health')->assertSee('Money flow')
            ->assertSee('← Dashboard', false)->assertSee("/app/{$this->lib->slug}", false);
    }

    public function test_the_numbers_come_from_this_librarys_own_data(): void
    {
        Student::create(['name' => 'A', 'phone' => '9000000001', 'registered_on' => today(), 'status' => 'active']);
        Student::create(['name' => 'B', 'phone' => '9000000002', 'registered_on' => today(), 'status' => 'active']);
        LibraryInsights::flush();

        $i = LibraryInsights::all();
        $this->assertSame(2, $i['members']['total']);
        $this->assertSame(0, $i['members']['live']);          // nobody has a membership yet
        $this->assertSame(0.0, $i['money']['today'] - 0.0);
        $this->assertSame(['₹0', '₹1.5K', '₹2.5L'], [LibraryInsights::short(0), LibraryInsights::short(1500), LibraryInsights::short(250000)]);
    }

    public function test_insights_follow_what_the_person_may_see(): void
    {
        $desk = User::factory()->create();
        $this->lib->users()->attach($desk->id, ['is_active' => true]);
        Tenancy::run($this->lib, fn () => $desk->assignRole(\App\Support\LibraryRoles::SUPERVISOR));
        $desk = $desk->fresh();

        $this->actingAs($desk);
        $this->get("/app/{$this->lib->slug}/insights")->assertOk()->assertSee('Attendance this week')->assertDontSee('Money flow')->assertDontSee('Member health');
    }

    public function test_a_login_with_no_staff_permission_is_refused(): void
    {
        $nobody = User::factory()->create();
        $this->lib->users()->attach($nobody->id, ['is_active' => true]);

        $this->actingAs($nobody);
        $this->get("/app/{$this->lib->slug}/insights")->assertForbidden();
    }
}
