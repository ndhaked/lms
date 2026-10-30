<?php

namespace Tests\Feature;

use App\Actions\Billing\ApproveSubscriptionPayment;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\ReviewSubscriptionPayment;
use App\Actions\Billing\SubmitSubscriptionPayment;
use App\Actions\RegisterLibrary;
use App\Exceptions\BusinessRuleException;
use App\Livewire\LeadForm;
use App\Models\Lead;
use App\Models\Library;
use App\Models\ReferralCoin;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\Entitlements;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Library $a;      // the referrer
    protected Library $b;      // the referred library
    protected User $ownerA;
    protected User $ownerB;
    protected SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->admin = $this->admin->fresh();
        $this->plan = SubscriptionPlan::create(['name' => 'Standard', 'price_monthly' => 1000, 'price_quarterly' => 2700, 'price_annual' => 10000, 'trial_days' => 0, 'max_students' => 50, 'max_seats' => 50, 'max_staff' => 5, 'modules' => [], 'grace_days' => 7]);

        $this->actingAs($this->admin);
        $this->a = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner A', 'email' => 'a@ref.test']);
        $this->b = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'Owner B', 'email' => 'b@ref.test']);
        $this->b->forceFill(['referred_by_id' => $this->a->id])->save();
        $this->ownerA = User::where('email', 'a@ref.test')->first();
        $this->ownerB = User::where('email', 'b@ref.test')->first();

        app(CreateSubscription::class)($this->a, $this->plan, 'monthly', false);
        app(CreateSubscription::class)($this->b, $this->plan, 'monthly', false);
    }

    protected function tearDown(): void
    {
        BillingSettings::flush();
        Entitlements::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function pay(Library $lib, User $owner, string $ref, string|int $amount = 1000, int $coins = 0)
    {
        Tenancy::set($lib);
        $this->actingAs($owner);

        return app(SubmitSubscriptionPayment::class)($lib, $this->plan, 'monthly', $ref, $amount, 'upi', today(), null, [UploadedFile::fake()->image('p.png')], $coins);
    }

    protected function approve($payment): void
    {
        $this->actingAs($this->admin);
        app(ApproveSubscriptionPayment::class)($payment);
    }

    public function test_referrer_earns_coins_once_when_the_referred_library_is_first_approved(): void
    {
        $this->approve($this->pay($this->b, $this->ownerB, 'B1'));
        $this->assertSame(100, ReferralCoin::balance($this->a->id));

        $this->approve($this->pay($this->b, $this->ownerB, 'B2'));
        $this->assertSame(100, ReferralCoin::balance($this->a->id), 'the reward is paid only once per referred library');
    }

    public function test_coins_take_money_off_a_payment_and_come_back_if_it_is_rejected(): void
    {
        ReferralCoin::create(['library_id' => $this->a->id, 'coins' => 100, 'kind' => 'earned', 'description' => 'test']);
        $this->assertSame(100, ReferralCoin::maxRedeemable($this->a->id, 1000.0));

        try {
            $this->pay($this->a, $this->ownerA, 'WRONG', 1000, 100);   // must pay 900 now
            $this->fail('the full price should be refused once coins are used');
        } catch (BusinessRuleException) {
        }

        $p = $this->pay($this->a, $this->ownerA, 'A1', '900.00', 100);
        $this->assertSame(100, $p->coins_used);
        $this->assertSame(0, ReferralCoin::balance($this->a->id));

        $this->actingAs($this->admin);
        app(ReviewSubscriptionPayment::class)->reject($p, 'bad proof');
        $this->assertSame(100, ReferralCoin::balance($this->a->id));
    }

    public function test_cannot_use_more_coins_than_owned_or_allowed(): void
    {
        ReferralCoin::create(['library_id' => $this->a->id, 'coins' => 100, 'kind' => 'earned', 'description' => 'test']);
        $this->expectException(BusinessRuleException::class);
        $this->pay($this->a, $this->ownerA, 'TOO', 850, 150);
    }

    public function test_refer_page_shows_the_link_and_balance_and_menu_group(): void
    {
        $this->approve($this->pay($this->a, $this->ownerA, 'ACTIVATE'));   // an active library, so every page is open
        ReferralCoin::create(['library_id' => $this->a->id, 'coins' => 100, 'kind' => 'earned', 'description' => 'Beta subscribed', 'referred_library_id' => $this->b->id]);
        Tenancy::set($this->a);
        $this->actingAs($this->ownerA);

        $this->get("/app/{$this->a->slug}/refer")->assertOk()->assertSee('?ref='.$this->a->code, false)->assertSee('100 Coins')->assertSee('Beta')->assertSee('Support &amp; More', false);
        $this->get("/app/{$this->a->slug}/help")->assertOk()->assertSee('Need help?');
        $this->get("/app/{$this->a->slug}/contact")->assertOk()->assertSee('Contact us');
        $this->get("/app/{$this->a->slug}/qr-code")->assertOk()->assertSee('<svg', false);
    }

    public function test_a_lead_arriving_through_a_referral_link_keeps_the_code(): void
    {
        $this->actingAs(User::factory()->create());   // any visitor; the form itself is public

        Livewire::withQueryParams(['ref' => $this->a->code])->test(LeadForm::class)
            ->set('name', 'Visitor')->set('email', 'v@example.test')->set('phone', '9876543210')->set('library_name', 'New Lib')
            ->call('submit')->assertHasNoErrors();

        $this->assertSame($this->a->code, Lead::first()->referral_code);
    }
}
