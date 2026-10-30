<?php

namespace Tests\Feature;

use App\Actions\Billing\CreateSubscription;
use App\Actions\RegisterLibrary;
use App\Models\Library;
use App\Models\ReferralCoin;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\Entitlements;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Library $lib;
    protected User $owner;
    protected SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->admin = $this->admin->fresh();
        $this->plan = SubscriptionPlan::create(['name' => 'Standard', 'price_monthly' => 1000, 'price_quarterly' => 2700, 'price_annual' => 10000, 'trial_days' => 0, 'max_students' => 50, 'max_seats' => 50, 'max_staff' => 5, 'modules' => ['attendance'], 'grace_days' => 7]);

        $this->actingAs($this->admin);
        $this->lib = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@co.test']);
        $this->owner = User::where('email', 'o@co.test')->first();
        app(CreateSubscription::class)($this->lib, $this->plan, 'monthly', false);

        Tenancy::set($this->lib);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        BillingSettings::flush();
        Entitlements::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function checkout(string $cycle = 'quarterly')
    {
        return Livewire::withQueryParams(['plan' => $this->plan->id, 'cycle' => $cycle])->test('pages::app.subscription.checkout');
    }

    public function test_choose_plan_goes_to_the_checkout_page_first(): void
    {
        Livewire::test('pages::app.subscription.plans')->call('choose', $this->plan->id, 'quarterly')
            ->assertRedirect(route('app.subscription.checkout', ['library' => $this->lib->slug, 'plan' => $this->plan->id, 'cycle' => 'quarterly']));
    }

    public function test_checkout_shows_the_order_price_breakdown_and_both_payment_options(): void
    {
        $this->get("/app/{$this->lib->slug}/subscription/checkout?plan={$this->plan->id}&cycle=quarterly")->assertOk()
            ->assertSee('Continue buying')->assertSee('Standard | Quarterly | 3 Months | ₹2,700')
            ->assertSee('Plan discount')->assertSee('300.00')          // 3 x 1000 - 2700
            ->assertSee('Total amount')->assertSee('2,700.00')
            ->assertSee('Pay by Cash')->assertSee('Razorpay')->assertSee('Coming soon');
    }

    public function test_online_payment_only_says_coming_soon_and_creates_nothing(): void
    {
        $this->checkout()->set('method', 'online')->call('proceed')->assertSet('onlineNotice', true)->assertNoRedirect();

        $this->assertSame(0, SubscriptionPayment::count());
    }

    public function test_pay_by_cash_opens_the_payment_popup_on_the_same_page(): void
    {
        $this->checkout()->call('proceed')->assertNoRedirect()->assertSet('showPay', true)->assertSee('Submit subscription payment')->assertSee('2,700.00');
    }

    public function test_submitting_the_popup_records_a_pending_payment_for_the_chosen_plan(): void
    {
        $this->checkout()->call('proceed')
            ->set('reference', 'UTR-CHK-1')->set('payMethod', 'upi')->set('proofs', [\Illuminate\Http\UploadedFile::fake()->image('proof.png')])
            ->call('savePay')->assertHasNoErrors()->assertRedirect(route('app.subscription.index', ['library' => $this->lib->slug]));

        $p = SubscriptionPayment::firstOrFail();
        $this->assertSame([$this->plan->id, 'quarterly', '2700.00', 'pending', 'UTR-CHK-1'], [$p->subscription_plan_id, $p->billing_cycle, $p->amount, $p->status, $p->reference]);
    }

    public function test_the_popup_needs_a_reference_and_proof(): void
    {
        $this->checkout()->call('proceed')->call('savePay')->assertHasErrors(['reference']);
        $this->assertSame(0, SubscriptionPayment::count());
    }

    public function test_referral_coins_lower_the_total_and_are_used_on_the_payment(): void
    {
        ReferralCoin::create(['library_id' => $this->lib->id, 'coins' => 100, 'kind' => 'earned', 'description' => 'test']);

        $page = $this->checkout()->set('useCoins', true);
        $page->assertSee('Referral coins (100)')->assertSee('2,600.00');

        $page->call('proceed')->assertSee('2,600.00')->set('reference', 'UTR-COIN')->set('proofs', [\Illuminate\Http\UploadedFile::fake()->image('p.png')])->call('savePay')->assertHasNoErrors();

        $p = SubscriptionPayment::firstOrFail();
        $this->assertSame(['2600.00', 100], [$p->amount, $p->coins_used]);
        $this->assertSame(0, ReferralCoin::balance($this->lib->id));
    }
    public function test_bad_plan_or_cycle_and_missing_permission_are_refused(): void
    {
        $this->get("/app/{$this->lib->slug}/subscription/checkout?plan=9999&cycle=monthly")->assertNotFound();
        $this->get("/app/{$this->lib->slug}/subscription/checkout?plan={$this->plan->id}&cycle=weekly")->assertNotFound();

        $desk = User::factory()->create();
        $this->lib->users()->attach($desk->id, ['is_active' => true]);
        Tenancy::run($this->lib, fn () => $desk->assignRole(\App\Support\LibraryRoles::SUPERVISOR));
        $this->actingAs($desk->fresh());
        $this->get("/app/{$this->lib->slug}/subscription/checkout?plan={$this->plan->id}&cycle=monthly")->assertForbidden();
    }
}
