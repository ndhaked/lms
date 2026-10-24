<?php

namespace Tests\Feature;

use App\Actions\Billing\ApproveSubscriptionPayment;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\ManageLibraryModule;
use App\Actions\Billing\SubmitSubscriptionPayment;
use App\Actions\RegisterLibrary;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\LibraryModule;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\Entitlements;
use App\Support\Pricing;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;
    protected User $owner;
    protected User $root;
    protected SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-19 10:00:00');

        $this->root = User::factory()->create();
        $this->root->forceFill(['is_super_admin' => true])->save();
        $this->root = $this->root->fresh();
        $this->actingAs($this->root);

        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@alpha.test']);
        $this->owner = User::where('email', 'o@alpha.test')->first();
        $this->plan = SubscriptionPlan::create(['name' => 'Std', 'price_monthly' => 1000, 'price_quarterly' => 2700, 'trial_days' => 14, 'modules' => ['attendance', 'reports']]);
        app(CreateSubscription::class)($this->library, $this->plan, 'monthly', true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        BillingSettings::flush();
        Entitlements::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function grant(string $module = 'books', string $type = 'addon', ?array $prices = null, $expires = null): LibraryModule
    {
        return app(ManageLibraryModule::class)->grant($this->library, $module, $type, 'Agreed with owner', $prices, $expires);
    }

    public function test_a_module_not_in_the_plan_is_off_until_super_admin_enables_it(): void
    {
        $this->assertFalse(Entitlements::hasModule('books', $this->library->id));
        $this->assertNull(Entitlements::moduleSource('books', $this->library->id));
        $this->assertSame('plan', Entitlements::moduleSource('attendance', $this->library->id));

        $this->grant('books');

        $this->assertTrue(Entitlements::hasModule('books', $this->library->id));
        $this->assertSame('addon', Entitlements::moduleSource('books', $this->library->id));
        $this->assertFalse(Entitlements::hasModule('ebooks', $this->library->id), 'only that module');
    }

    public function test_free_module_with_and_without_an_end_date(): void
    {
        $this->grant('books', 'complimentary', null, Carbon::parse('2026-11-18'));
        $this->assertSame('complimentary', Entitlements::moduleSource('books', $this->library->id));

        Carbon::setTestNow('2026-11-18 23:00:00');
        Entitlements::flush();
        $this->assertTrue(Entitlements::hasModule('books', $this->library->id), 'still usable on the last day');

        Carbon::setTestNow('2026-11-19 00:30:00');
        Entitlements::flush();
        $this->assertFalse(Entitlements::hasModule('books', $this->library->id), 'ends after the date');

        $this->grant('ebooks', 'complimentary');   // no end date
        Carbon::setTestNow('2030-01-01');
        Entitlements::flush();
        $this->assertTrue(Entitlements::hasModule('ebooks', $this->library->id));
    }

    public function test_revoking_switches_the_module_off_and_is_audited(): void
    {
        $this->grant('books');
        app(ManageLibraryModule::class)->revoke($this->library, 'books', 'Stopped paying');

        $this->assertFalse(Entitlements::hasModule('books', $this->library->id));
        $this->assertSame('revoked', LibraryModule::first()->status);
        $this->assertNotNull(AuditLog::where('action', 'library_module.revoked')->first());

        $this->grant('books');   // can be switched on again
        $this->assertTrue(Entitlements::hasModule('books', $this->library->id));
        $this->assertSame(1, LibraryModule::count());
    }

    public function test_validation_and_plan_overlap(): void
    {
        $bad = [
            fn () => app(ManageLibraryModule::class)->grant($this->library, 'nope', 'addon', 'r', ['monthly' => 10]),
            fn () => app(ManageLibraryModule::class)->grant($this->library, 'books', 'free', 'r'),
            fn () => app(ManageLibraryModule::class)->grant($this->library, 'books', 'addon', ' '),
            fn () => app(ManageLibraryModule::class)->grant($this->library, 'books', 'addon', 'r', ['monthly' => 0]),
            fn () => app(ManageLibraryModule::class)->grant($this->library, 'books', 'addon', 'r', ['monthly' => 100, 'annual' => -5]),
            fn () => app(ManageLibraryModule::class)->grant($this->library, 'books', 'complimentary', 'r', null, Carbon::parse('2026-01-01')),
            fn () => app(ManageLibraryModule::class)->grant($this->library, 'attendance', 'addon', 'r', ['monthly' => 100]),   // already in plan
            fn () => app(ManageLibraryModule::class)->revoke($this->library, 'books', 'r'),                                   // nothing to revoke
        ];
        foreach ($bad as $i => $try) {
            try {
                $try();
                $this->fail("case {$i} was accepted");
            } catch (BusinessRuleException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, LibraryModule::count());
    }

    public function test_default_prices_come_from_platform_settings(): void
    {
        $row = $this->grant('books');   // no explicit prices
        $this->assertSame('299.00', (string) $row->price_monthly);
        $this->assertSame('799.00', (string) $row->price_quarterly);
        $this->assertSame('2999.00', (string) $row->price_annual);

        // quarterly/annual fall back to months x monthly
        $custom = $this->grant('ebooks', 'addon', ['monthly' => '100']);
        $this->assertSame('300.00', $custom->priceFor('quarterly'));
        $this->assertSame('1200.00', $custom->priceFor('annual'));
    }

    public function test_only_staff_with_the_subscription_permission_can_change_modules(): void
    {
        $limited = User::factory()->create();
        $limited->forceFill(['is_super_admin' => true, 'platform_permissions' => ['reports.view']])->save();
        $this->actingAs($limited->fresh());

        Livewire::test('pages::admin.libraries.show', ['library' => (string) $this->library->id])->call('openModule', 'books', 'grant')->assertForbidden();

        $this->actingAs($this->owner);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->grant('books');
    }

    // ===================== billing =====================

    public function test_add_ons_are_added_to_what_the_library_must_pay(): void
    {
        $this->grant('books', 'addon', ['monthly' => '300', 'quarterly' => '800']);
        $this->grant('ebooks', 'complimentary');   // free: never charged

        $monthly = Pricing::quote($this->library->id, $this->plan, 'monthly');
        $this->assertSame('1300.00', $monthly['total']);
        $this->assertCount(2, $monthly['lines']);
        $this->assertSame('books', $monthly['lines'][1]['module']);

        $this->assertSame('3500.00', Pricing::quote($this->library->id, $this->plan, 'quarterly')['total']);   // 2700 + 800

        // a plan that already includes the module does not charge it again
        $withBooks = SubscriptionPlan::create(['name' => 'Big', 'price_monthly' => 2000, 'modules' => ['books']]);
        $this->assertSame('2000.00', Pricing::quote($this->library->id, $withBooks, 'monthly')['total']);
    }

    public function test_payment_must_match_the_total_and_the_invoice_lists_each_line(): void
    {
        $this->grant('books', 'addon', ['monthly' => '300']);
        $this->actingAs($this->owner);
        Tenancy::set($this->library);
        $file = fn () => [UploadedFile::fake()->image('p.png')];

        try {
            app(SubmitSubscriptionPayment::class)($this->library, $this->plan, 'monthly', 'UTR-A', 1000, 'upi', today(), null, $file());
            $this->fail('plan-only amount accepted although an add-on is billed');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('1300.00', $e->getMessage());
            $this->assertStringContainsString('add-on', $e->getMessage());
        }

        $payment = app(SubmitSubscriptionPayment::class)($this->library, $this->plan, 'monthly', 'UTR-B', 1300, 'upi', today(), null, $file());
        $this->assertCount(2, $payment->breakdown);

        $this->actingAs($this->root);
        app(ApproveSubscriptionPayment::class)($payment);

        $invoice = SubscriptionInvoice::first();
        $this->assertSame('1300.00', (string) $invoice->amount);
        $this->assertCount(2, $invoice->lines);
        $this->assertStringContainsString('add-ons', $invoice->description);

        $this->actingAs($this->owner);
        $this->get(route('app.subscription.invoice', ['library' => $this->library->slug, 'invoice' => $invoice->id]))
            ->assertOk()->assertSee('Books &amp; circulation add-on', false)->assertSee('300.00');
    }

    public function test_the_library_sees_where_each_module_comes_from_and_can_request_one(): void
    {
        $this->grant('books', 'addon', ['monthly' => '300']);
        $this->actingAs($this->owner);
        Tenancy::set($this->library);

        $this->get("/app/{$this->library->slug}/subscription")
            ->assertOk()->assertSee('In your plan')->assertSee('Add-on')->assertSee('Not enabled');

        Livewire::test('pages::app.subscription.index')->call('requestModule', 'ebooks')
            ->assertSet('subject', 'Please enable E-books')->call('sendSupport')->assertHasNoErrors();
        $this->assertSame(1, \App\Models\SupportRequest::where('library_id', $this->library->id)->count());
    }

    public function test_grants_are_private_to_their_library(): void
    {
        $this->grant('books');
        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        app(CreateSubscription::class)($other, $this->plan, 'monthly', true);
        Entitlements::flush();

        $this->assertTrue(Entitlements::hasModule('books', $this->library->id));
        $this->assertFalse(Entitlements::hasModule('books', $other->id));
        $this->assertSame('1000.00', Pricing::quote($other->id, $this->plan, 'monthly')['total']);
    }

    // ===================== admin screens =====================

    public function test_super_admin_manages_modules_from_the_library_page(): void
    {
        $page = Livewire::test('pages::admin.libraries.show', ['library' => (string) $this->library->id]);

        $page->call('setTab', 'modules')->assertSee('Books & circulation')->assertSee('In plan');
        $page->call('openModule', 'books', 'grant')->assertSet('modMonthly', '299')
            ->set('modReason', 'Pilot')->call('saveModule')->assertHasNoErrors();
        $this->assertSame('addon', Entitlements::moduleSource('books', $this->library->id));

        $page->call('openModule', 'books', 'grant')->set('modType', 'complimentary')->set('modExpires', '2026-12-31')->set('modReason', 'Gift')->call('saveModule')->assertHasNoErrors();
        $this->assertSame('complimentary', Entitlements::moduleSource('books', $this->library->id));

        $page->call('openModule', 'books', 'revoke')->call('saveModule')->assertHasErrors('modReason');
        $page->call('openModule', 'books', 'revoke')->set('modReason', 'Ended')->call('saveModule')->assertHasNoErrors();
        $this->assertNull(Entitlements::moduleSource('books', $this->library->id));
    }

    public function test_standard_add_on_prices_are_set_in_platform_settings(): void
    {
        Livewire::test('pages::admin.settings.index')
            ->set('modulePrices.books.monthly', '-1')->call('saveModules')->assertHasErrors('modulePrices.books.monthly');

        Livewire::test('pages::admin.settings.index')
            ->set('modulePrices.books.monthly', '350')->set('modulePrices.books.quarterly', '')->set('modulePrices.books.annual', '3500')->call('saveModules')->assertHasNoErrors();

        $this->assertSame('350.00', BillingSettings::get('module_prices')['books']['monthly']);
        $this->assertArrayNotHasKey('quarterly', BillingSettings::get('module_prices')['books']);

        $row = $this->grant('books');
        $this->assertSame('350.00', (string) $row->price_monthly);
        $this->assertSame('1050.00', $row->priceFor('quarterly'));
    }

    public function test_plans_can_include_the_new_modules(): void
    {
        $this->assertArrayHasKey('books', SubscriptionPlan::MODULES);
        $this->assertArrayHasKey('ebooks', SubscriptionPlan::MODULES);

        $big = SubscriptionPlan::create(['name' => 'Big', 'price_monthly' => 2000, 'modules' => ['books', 'ebooks']]);
        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        app(CreateSubscription::class)($other, $big, 'monthly', false);
        Entitlements::flush();

        $this->assertSame('plan', Entitlements::moduleSource('books', $other->id));
        $this->assertSame('plan', Entitlements::moduleSource('ebooks', $other->id));
    }
}
