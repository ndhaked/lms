<?php

namespace Tests\Feature;

use App\Actions\Billing\ApproveSubscriptionPayment;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\ReviewSubscriptionPayment;
use App\Actions\Billing\SubmitSubscriptionPayment;
use App\Actions\RegisterLibrary;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Student;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\SupportRequest;
use App\Models\User;
use App\Reports\Platform\PlatformReportRegistry;
use App\Support\BillingSettings;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $root;      // unrestricted platform admin
    protected Library $library;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->root = User::factory()->create();
        $this->root->forceFill(['is_super_admin' => true])->save();
        $this->root = $this->root->fresh();
        $this->actingAs($this->root);

        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'owner@alpha.test']);
        $this->owner = User::where('email', 'owner@alpha.test')->first();
        Tenancy::set($this->library);
    }

    protected function tearDown(): void
    {
        BillingSettings::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function admin(array $permissions, bool $active = true): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true, 'platform_permissions' => $permissions, 'is_active' => $active])->save();

        return $u->fresh();
    }

    // ===================== admin users =====================

    public function test_admin_users_page_needs_the_admins_permission(): void
    {
        $this->get('/admin/admins')->assertOk();
        $this->actingAs($this->admin(['plans.manage']))->get('/admin/admins')->assertForbidden();
        $this->actingAs($this->owner)->get('/admin/admins')->assertForbidden();
    }

    public function test_adding_an_administrator_sends_a_setup_link_and_stores_permissions(): void
    {
        Notification::fake();

        Livewire::test('pages::admin.admins.index')->call('create')->call('save')->assertHasErrors(['name', 'email']);
        Livewire::test('pages::admin.admins.index')->call('create')->set('name', 'Ann')->set('email', 'ann@platform.test')->call('save')->assertHasErrors('permissions');

        Livewire::test('pages::admin.admins.index')->call('create')
            ->set('name', 'Ann')->set('email', 'ann@platform.test')->set('permissions', ['payments.approve', 'reports.view'])->call('save')->assertHasNoErrors();

        $ann = User::where('email', 'ann@platform.test')->first();
        $this->assertTrue($ann->is_super_admin);
        $this->assertSame(['payments.approve', 'reports.view'], $ann->platform_permissions);
        $this->assertTrue($ann->hasPlatformPermission('payments.approve'));
        $this->assertFalse($ann->hasPlatformPermission('plans.manage'));
        Notification::assertSentTo($ann, ResetPassword::class);
        $this->assertNotNull(AuditLog::where('action', 'platform_admin.created')->first());
    }

    public function test_full_access_can_be_granted_only_by_a_full_access_admin(): void
    {
        Livewire::test('pages::admin.admins.index')->call('create')
            ->set('name', 'Boss')->set('email', 'boss@platform.test')->set('fullAccess', true)->call('save')->assertHasNoErrors();
        $this->assertNull(User::where('email', 'boss@platform.test')->first()->platform_permissions);

        $limited = $this->admin(['admins.manage', 'reports.view']);
        $this->actingAs($limited);
        Livewire::test('pages::admin.admins.index')->call('create')
            ->set('name', 'Sneaky')->set('email', 'sneaky@platform.test')->set('fullAccess', true)->call('save')->assertHasErrors('permissions');
        $this->assertNull(User::where('email', 'sneaky@platform.test')->first());
    }

    public function test_an_admin_cannot_grant_permissions_they_do_not_hold(): void
    {
        $limited = $this->admin(['admins.manage', 'reports.view']);
        $this->actingAs($limited);

        Livewire::test('pages::admin.admins.index')->call('create')
            ->set('name', 'X')->set('email', 'x@platform.test')->set('permissions', ['reports.view', 'payments.approve'])->call('save')->assertHasErrors('permissions');
        $this->assertNull(User::where('email', 'x@platform.test')->first());

        Livewire::test('pages::admin.admins.index')->call('create')
            ->set('name', 'X')->set('email', 'x@platform.test')->set('permissions', ['reports.view'])->call('save')->assertHasNoErrors();
        $this->assertSame(['reports.view'], User::where('email', 'x@platform.test')->first()->platform_permissions);
    }

    public function test_a_limited_admin_cannot_edit_or_disable_a_full_access_admin(): void
    {
        $limited = $this->admin(['admins.manage']);
        $this->actingAs($limited);

        Livewire::test('pages::admin.admins.index')->call('edit', $this->root->id)->set('fullAccess', false)->set('permissions', ['admins.manage'])->call('save')->assertHasErrors('permissions');
        Livewire::test('pages::admin.admins.index')->call('toggle', $this->root->id)->assertDispatched('notify');

        $this->assertNull($this->root->fresh()->platform_permissions);
        $this->assertTrue($this->root->fresh()->is_active);
    }

    public function test_the_last_admin_manager_and_yourself_are_protected(): void
    {
        Livewire::test('pages::admin.admins.index')->call('toggle', $this->root->id)->assertDispatched('notify');
        $this->assertTrue($this->root->fresh()->is_active, 'cannot disable yourself');

        // root is the only one who can manage admins: removing that from them (or disabling) is refused
        $second = $this->admin(['plans.manage']);
        Livewire::test('pages::admin.admins.index')->call('edit', $this->root->id)->set('fullAccess', false)->set('permissions', ['plans.manage'])->call('save')->assertHasErrors('permissions');
        $this->assertNull($this->root->fresh()->platform_permissions);

        // once another active admin can manage admins, root may drop the permission
        $this->admin(['admins.manage']);
        Livewire::test('pages::admin.admins.index')->call('edit', $this->root->id)->set('fullAccess', false)->set('permissions', ['plans.manage'])->call('save')->assertHasNoErrors();
        $this->assertSame(['plans.manage'], $this->root->fresh()->platform_permissions);
    }

    public function test_disabled_admins_cannot_sign_in_or_use_the_panel(): void
    {
        $other = $this->admin(['reports.view']);
        Livewire::test('pages::admin.admins.index')->call('toggle', $other->id);

        $this->assertFalse($other->fresh()->is_active);
        $this->actingAs($other->fresh())->get('/admin')->assertForbidden();

        auth()->logout();
        Livewire::test('pages::auth.login')->set('email', $other->email)->set('password', 'password')->call('login')->assertHasErrors('email');
    }

    public function test_admin_actions_cannot_target_library_users(): void
    {
        foreach (['edit', 'toggle', 'resetAccess', 'showActivity'] as $method) {
            Livewire::test('pages::admin.admins.index')->call($method, $this->owner->id)->assertNotFound();
        }
    }

    public function test_reset_access_for_an_administrator(): void
    {
        Notification::fake();
        $other = $this->admin(['reports.view']);

        Livewire::test('pages::admin.admins.index')->call('resetAccess', $other->id);

        Notification::assertSentTo($other, ResetPassword::class);
        $this->assertNotNull(AuditLog::where('action', 'platform_admin.access_reset_sent')->first());
    }

    public function test_library_roles_never_carry_platform_permissions(): void
    {
        $this->assertFalse($this->owner->hasPlatformPermission('payments.approve'));
        $this->assertFalse($this->owner->hasPlatformPermission('libraries.manage'));
    }

    // ===================== platform settings =====================

    public function test_settings_page_requires_permission_and_saves_each_section(): void
    {
        $this->get('/admin/settings')->assertOk()->assertSee('Platform settings');
        $this->actingAs($this->admin(['plans.manage']))->get('/admin/settings')->assertForbidden();
        $this->actingAs($this->root);

        Livewire::test('pages::admin.settings.index')->set('default_timezone', 'Nowhere/Land')->call('saveGeneral')->assertHasErrors('default_timezone');
        Livewire::test('pages::admin.settings.index')->set('app_name', 'LibraryHub')->set('support_email', 'help@hub.test')->set('default_timezone', 'Asia/Tokyo')->call('saveGeneral')->assertHasNoErrors();
        $this->assertSame('LibraryHub', BillingSettings::get('app_name'));
        $this->assertSame('Asia/Tokyo', BillingSettings::get('default_timezone'));

        Livewire::test('pages::admin.settings.index')->set('renewal_reminder_days', '0')->call('saveSubscription')->assertHasErrors('renewal_reminder_days');
        Livewire::test('pages::admin.settings.index')->set('default_trial_days', '30')->set('default_grace_days', '10')->set('renewal_reminder_days', '5')->call('saveSubscription')->assertHasNoErrors();
        $this->assertSame(30, BillingSettings::get('default_trial_days'));

        Livewire::test('pages::admin.settings.index')->set('payment_methods', [])->call('savePayments')->assertHasErrors('payment_methods');
        Livewire::test('pages::admin.settings.index')->set('payment_methods', ['upi', 'cash'])->set('payment_instructions', 'UPI: lib@upi')->set('proof_required', false)->call('savePayments')->assertHasNoErrors();
        $this->assertSame(['upi', 'cash'], BillingSettings::get('payment_methods'));
        $this->assertFalse(BillingSettings::get('proof_required'));

        Livewire::test('pages::admin.settings.index')->set('upload_limit_kb', '50')->call('saveSystem')->assertHasErrors('upload_limit_kb');
        Livewire::test('pages::admin.settings.index')->set('upload_limit_kb', '2048')->set('maintenance_notice', 'Down Sunday 2am')->call('saveSystem')->assertHasNoErrors();

        $this->assertGreaterThanOrEqual(4, AuditLog::where('action', 'platform_settings.updated')->count());
    }

    public function test_settings_take_effect_in_the_product(): void
    {
        BillingSettings::set(['app_name' => 'LibraryHub', 'maintenance_notice' => 'Planned maintenance tonight', 'default_timezone' => 'Asia/Tokyo']);

        auth()->logout();
        $this->get('/login')->assertSee('LibraryHub');

        $this->actingAs($this->owner)->get("/app/{$this->library->slug}")->assertSee('Planned maintenance tonight');
        $this->actingAs($this->root)->get('/admin')->assertSee('Planned maintenance tonight');
        $this->get('/admin/libraries/create')->assertSee('Asia/Tokyo');
    }

    // ===================== platform reports =====================

    protected function seedBilling(): array
    {
        $plan = SubscriptionPlan::create(['name' => 'Std', 'price_monthly' => 1000, 'trial_days' => 5]);
        app(CreateSubscription::class)($this->library, $plan, 'monthly', false);
        $this->actingAs($this->owner);
        $paid = app(SubmitSubscriptionPayment::class)($this->library, $plan, 'monthly', 'UTRPAID', 1000, 'upi', today(), null, [UploadedFile::fake()->image('p.png')]);
        $this->actingAs($this->root);
        app(ApproveSubscriptionPayment::class)($paid);

        // a second library with a pending and a rejected payment
        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        app(CreateSubscription::class)($beta, $plan, 'monthly', true);
        $this->actingAs(User::where('email', 'b@beta.test')->first());
        Tenancy::set($beta);
        $rejected = app(SubmitSubscriptionPayment::class)($beta, $plan, 'monthly', 'UTRBAD', 1000, 'upi', today(), null, [UploadedFile::fake()->image('p.png')]);
        $this->actingAs($this->root);
        app(ReviewSubscriptionPayment::class)->reject($rejected, 'Not received');
        $this->actingAs(User::where('email', 'b@beta.test')->first());
        app(SubmitSubscriptionPayment::class)($beta, $plan, 'monthly', 'UTRWAIT', 1000, 'upi', today(), null, [UploadedFile::fake()->image('p.png')]);
        $this->actingAs($this->root);

        return [$plan, $beta];
    }

    public function test_every_platform_report_runs_with_matching_columns(): void
    {
        $this->seedBilling();

        foreach (PlatformReportRegistry::all() as $report) {
            $f = $report->defaultFilters();
            $rows = $report->query($f) ? $report->query($f)->get()->map(fn ($m) => $report->row($m)) : $report->collection($f);

            foreach ($rows as $row) {
                $this->assertCount(count($report->headers()), $row, "{$report->key()} row width");
            }
            $this->assertIsArray($report->summary($f));
        }
    }

    public function test_revenue_report_counts_approved_payments_only_and_never_student_fees(): void
    {
        $this->seedBilling();
        // a student fee collected by the library must never show up as platform revenue
        Student::create(['name' => 'Fee Payer', 'phone' => '9000000099']);

        $csv = $this->get('/admin/reports/subscription-revenue/export/csv')->streamedContent();

        $this->assertStringContainsString('UTRPAID', $csv);
        $this->assertStringNotContainsString('UTRBAD', $csv);
        $this->assertStringNotContainsString('UTRWAIT', $csv);
        $this->assertStringNotContainsString('Fee Payer', $csv);
        $this->assertStringContainsString('INV-', $csv);

        $pending = $this->get('/admin/reports/pending-payments/export/csv')->streamedContent();
        $this->assertStringContainsString('UTRWAIT', $pending);
        $this->assertStringNotContainsString('UTRPAID', $pending);

        $rejected = $this->get('/admin/reports/rejected-payments/export/csv')->streamedContent();
        $this->assertStringContainsString('Not received', $rejected);
    }

    public function test_platform_report_permissions_exports_and_audit(): void
    {
        $this->seedBilling();

        $this->actingAs($this->admin(['plans.manage']));
        $this->get('/admin/reports')->assertForbidden();
        $this->get('/admin/reports/subscription-revenue/export/csv')->assertForbidden();

        $this->actingAs($this->owner);   // a library owner is not platform staff
        $this->get('/admin/reports')->assertForbidden();

        $this->actingAs($this->admin(['reports.view']));
        $this->get('/admin/reports')->assertOk()->assertSee('Subscription revenue');
        $this->get('/admin/reports/subscription-revenue/export/xlsx')->assertOk();
        $this->get('/admin/reports/nope/export/csv')->assertNotFound();
        $this->get('/admin/reports/subscription-revenue/export/pdf')->assertNotFound();

        $this->assertNotNull(AuditLog::where('action', 'platform_report.exported')->where('properties->report', 'subscription-revenue')->first());
    }

    public function test_platform_reports_page_renders_every_report(): void
    {
        $this->seedBilling();

        foreach (PlatformReportRegistry::all()->keys() as $key) {
            $this->get("/admin/reports?report={$key}")->assertOk();
        }
        $this->get('/admin/reports?report=library-registrations')->assertSee('Alpha')->assertSee('Beta');
        $this->get('/admin/reports?report=plan-usage')->assertSee('Std');
    }

    // ===================== support requests =====================

    public function test_library_sends_support_request_and_platform_replies(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::app.subscription.index')->call('openSupport')->call('sendSupport')->assertHasErrors(['subject', 'supportMessage']);
        Livewire::test('pages::app.subscription.index')->call('openSupport')->set('subject', 'Invoice question')->set('supportMessage', 'Please resend my invoice')->call('sendSupport')->assertHasNoErrors();

        $req = SupportRequest::first();
        $this->assertSame($this->library->id, $req->library_id);
        $this->assertSame('open', $req->status);
        $this->assertSame(1, $this->root->notifications()->where('data->kind', 'support.request')->count(), 'platform staff are told');

        $this->actingAs($this->root);
        $this->get('/admin/support')->assertOk()->assertSee('Invoice question');
        Livewire::test('pages::admin.support.index')->call('open', $req->id)->call('respond')->assertHasErrors('response');
        Livewire::test('pages::admin.support.index')->call('open', $req->id)->set('response', 'Sent to your email')->call('respond')->assertHasNoErrors();

        $this->assertSame('resolved', $req->fresh()->status);
        $this->assertSame(1, $this->owner->notifications()->where('data->kind', 'support.reply')->count());

        // the library sees the answer, and only its own requests
        $this->actingAs($this->owner);
        $this->get("/app/{$this->library->slug}/subscription")->assertSee('Sent to your email');
    }

    public function test_support_requests_are_private_to_each_library_and_the_page_is_permissioned(): void
    {
        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        SupportRequest::create(['library_id' => $beta->id, 'user_id' => User::where('email', 'b@beta.test')->first()->id, 'subject' => 'Beta secret', 'message' => 'x']);

        $this->actingAs($this->owner)->get("/app/{$this->library->slug}/subscription")->assertDontSee('Beta secret');

        $this->actingAs($this->owner)->get('/admin/support')->assertForbidden();
        $this->actingAs($this->admin(['reports.view']))->get('/admin/support')->assertForbidden();
        $this->actingAs($this->root)->get('/admin/support')->assertOk()->assertSee('Beta secret');
    }

    public function test_sidebar_shows_only_what_the_platform_admin_may_open(): void
    {
        $this->actingAs($this->admin(['reports.view']));
        $html = $this->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('Platform Reports', $html);
        $this->assertStringNotContainsString('Subscription Plans', $html);
        $this->assertStringNotContainsString('Payment Approvals', $html);
        $this->assertStringNotContainsString('Admin Users', $html);
    }
}
