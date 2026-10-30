<?php

namespace Tests\Feature;

use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\StartOnlinePayment;
use App\Actions\RegisterLibrary;
use App\Exceptions\BusinessRuleException;
use App\Models\CreditPurchase;
use App\Models\Library;
use App\Models\OnlinePayment;
use App\Models\ReferralCoin;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\Entitlements;
use App\Support\Razorpay;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class RazorpayTest extends TestCase
{
    use RefreshDatabase;

    protected const SECRET = 'test_secret_value';
    protected const HOOK = 'whsec_test_value';

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
        $this->plan = SubscriptionPlan::create(['name' => 'Standard', 'price_monthly' => 1000, 'price_quarterly' => 2700, 'price_annual' => 10000, 'trial_days' => 0, 'max_students' => 50, 'max_seats' => 50, 'max_staff' => 5, 'modules' => [], 'grace_days' => 7]);

        $this->actingAs($this->admin);
        $this->lib = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@rz.test']);
        $this->owner = User::where('email', 'o@rz.test')->first();
        app(CreateSubscription::class)($this->lib, $this->plan, 'monthly', false);
        BillingSettings::set(['razorpay_enabled' => true, 'razorpay_key_id' => 'rzp_test_abcdefgh1234', 'razorpay_key_secret' => Razorpay::encrypt(self::SECRET), 'razorpay_webhook_secret' => Razorpay::encrypt(self::HOOK)]);
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

    protected function fakeOrders(string $id = 'order_TESTORDER001'): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => fn ($req) => Http::response(['id' => $id, 'amount' => $req['amount'], 'currency' => 'INR'], 200)]);
    }

    /** What Razorpay would say about a payment. */
    protected function rzpPayment(OnlinePayment $o, array $over = []): array
    {
        return array_merge(['id' => 'pay_TESTPAY00001', 'order_id' => $o->razorpay_order_id, 'amount' => $o->paise(), 'currency' => 'INR', 'status' => 'captured'], $over);
    }

    protected function fakePayment(array $payment): void
    {
        Http::fake(["api.razorpay.com/v1/payments/{$payment['id']}" => Http::response($payment, 200), 'api.razorpay.com/v1/payments/*/capture' => Http::response(['status' => 'captured'], 200)]);
    }

    protected function sig(string $order, string $pay): string
    {
        return hash_hmac('sha256', "{$order}|{$pay}", self::SECRET);
    }

    protected function startPlan(string $cycle = 'quarterly', bool $coins = false): OnlinePayment
    {
        $this->fakeOrders();

        return app(StartOnlinePayment::class)->subscription($this->lib, $this->owner, $this->plan, $cycle, $coins)['payment'];
    }

    protected function checkout()
    {
        return Livewire::withQueryParams(['plan' => $this->plan->id, 'cycle' => 'quarterly'])->test('pages::app.subscription.checkout');
    }

    // ---------------------------------------------------------------- settings

    public function test_super_admin_saves_keys_encrypted_and_they_are_never_shown_back(): void
    {
        $this->actingAs($this->admin);
        BillingSettings::set(['razorpay_enabled' => false, 'razorpay_key_id' => '', 'razorpay_key_secret' => '', 'razorpay_webhook_secret' => '']);

        $page = Livewire::test('pages::admin.settings.index')->set('tab', 'razorpay');
        $page->set('rzp_enabled', true)->call('saveRazorpay')->assertHasErrors('rzp_key_id');                                  // needs keys first
        $page->set('rzp_key_id', 'not-a-key')->call('saveRazorpay')->assertHasErrors('rzp_key_id');
        $page->set('rzp_key_id', 'rzp_test_zkh9ScLL0mIHFK')->set('rzp_key_secret', 'PLAIN-SECRET-XYZ')->set('rzp_webhook_secret', 'PLAIN-HOOK')->call('saveRazorpay')->assertHasNoErrors();
        BillingSettings::flush();

        $raw = json_encode(\Illuminate\Support\Facades\DB::table('subscription_settings')->get()->all());
        $this->assertStringNotContainsString('PLAIN-SECRET-XYZ', $raw);
        $this->assertStringNotContainsString('PLAIN-HOOK', $raw);
        $this->assertSame(['PLAIN-SECRET-XYZ', 'PLAIN-HOOK', 'test', true], [Razorpay::secret(), Razorpay::webhookSecret(), Razorpay::mode(), Razorpay::enabled()]);

        $fresh = Livewire::test('pages::admin.settings.index')->set('tab', 'razorpay');
        $this->assertSame('', $fresh->get('rzp_key_secret'));
        $fresh->call('saveRazorpay')->assertHasNoErrors();                                                                       // blank keeps the saved secrets
        $this->assertSame('PLAIN-SECRET-XYZ', Razorpay::secret());
    }

    public function test_library_owners_cannot_open_the_razorpay_settings(): void
    {
        Livewire::test('pages::admin.settings.index')->assertForbidden();
    }

    // ---------------------------------------------------------------- starting a payment

    public function test_the_amount_is_fixed_on_the_server_and_sent_to_razorpay_in_paise(): void
    {
        $o = $this->startPlan('quarterly');

        $this->assertSame(['2700.00', 270000, 'created', 'order_TESTORDER001'], [$o->amount, $o->paise(), $o->status, $o->razorpay_order_id]);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.razorpay.com/v1/orders' && $r['amount'] === 270000 && $r['currency'] === 'INR'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_abcdefgh1234:'.self::SECRET)));
    }

    public function test_referral_coins_lower_the_amount_and_nothing_is_spent_until_it_is_paid(): void
    {
        ReferralCoin::create(['library_id' => $this->lib->id, 'coins' => 100, 'kind' => 'earned', 'description' => 'test']);

        $o = $this->startPlan('quarterly', true);

        $this->assertSame(['2600.00', 100], [$o->amount, $o->coins_used]);
        $this->assertSame(100, ReferralCoin::balance($this->lib->id));
    }

    public function test_it_refuses_when_switched_off_for_unknown_packs_and_for_other_roles(): void
    {
        BillingSettings::set(['razorpay_enabled' => false]);
        try {
            app(StartOnlinePayment::class)->subscription($this->lib, $this->owner, $this->plan, 'monthly', false);
            $this->fail('should be refused while Razorpay is off');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('not available', $e->getMessage());
        }
        BillingSettings::set(['razorpay_enabled' => true]);

        $this->expectException(BusinessRuleException::class);
        app(StartOnlinePayment::class)->credits($this->lib, $this->owner, 'sms', 123);
    }

    public function test_an_order_razorpay_returns_with_a_different_amount_is_thrown_away(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_TESTORDER001', 'amount' => 100, 'currency' => 'INR'], 200)]);

        try {
            app(StartOnlinePayment::class)->subscription($this->lib, $this->owner, $this->plan, 'monthly', false);
            $this->fail('a tampered order must be refused');
        } catch (BusinessRuleException) {
        }

        $this->assertSame(0, OnlinePayment::count());
    }

    public function test_the_checkout_page_opens_razorpay_only_when_it_is_on_and_says_coming_soon_otherwise(): void
    {
        $this->fakeOrders();
        $this->checkout()->set('method', 'online')->call('proceed')->assertDispatched('razorpay-open')->assertSet('onlineBusy', true);
        $this->assertSame(1, OnlinePayment::count());

        BillingSettings::set(['razorpay_enabled' => false]);
        $this->checkout()->set('method', 'online')->call('proceed')->assertNotDispatched('razorpay-open')->assertSet('onlineNotice', true);
        $this->assertSame(1, OnlinePayment::count());
    }

    // ---------------------------------------------------------------- browser comes back from checkout

    public function test_a_verified_payment_activates_the_plan_and_issues_an_invoice(): void
    {
        $o = $this->startPlan('quarterly');
        $this->fakePayment($this->rzpPayment($o));

        $this->checkout()->call('verifyOnline', 'pay_TESTPAY00001', $o->razorpay_order_id, $this->sig($o->razorpay_order_id, 'pay_TESTPAY00001'))
            ->assertSet('onlineError', '')->assertRedirect(route('app.subscription.index', ['library' => $this->lib->slug]));

        $o->refresh();
        $this->assertSame(['paid', 'pay_TESTPAY00001'], [$o->status, $o->razorpay_payment_id]);
        $p = SubscriptionPayment::firstOrFail();
        $this->assertSame(['approved', 'razorpay', '2700.00', 'pay_TESTPAY00001'], [$p->status, $p->method, $p->amount, $p->reference]);
        $this->assertNotNull($p->invoice);
        $s = Subscription::currentFor($this->lib->id);
        $this->assertSame(['active', 'quarterly'], [$s->status, $s->billing_cycle]);
        $this->assertTrue($s->expires_on->gt(today()->addDays(80)));
    }

    public function test_a_bad_signature_grants_nothing(): void
    {
        $o = $this->startPlan();
        $this->fakePayment($this->rzpPayment($o));

        $this->checkout()->call('verifyOnline', 'pay_TESTPAY00001', $o->razorpay_order_id, 'forged-signature')->assertSet('onlineError', fn ($e) => str_contains($e, 'could not verify'));

        $this->assertSame(['created', 0], [$o->fresh()->status, SubscriptionPayment::count()]);
    }

    public function test_a_valid_signature_for_a_different_amount_or_order_is_refused(): void
    {
        $o = $this->startPlan('quarterly');
        $pay = $this->rzpPayment($o, ['amount' => 100]);                                  // paid ₹1, not ₹2,700
        $this->fakePayment($pay);

        $this->checkout()->call('verifyOnline', 'pay_TESTPAY00001', $o->razorpay_order_id, $this->sig($o->razorpay_order_id, 'pay_TESTPAY00001'));

        $o->refresh();
        $this->assertSame(['failed', 0], [$o->status, SubscriptionPayment::count()]);
        $this->assertStringContainsString('Amount', $o->failure_reason);
        $this->assertSame('pending', Subscription::currentFor($this->lib->id)->status);
    }

    public function test_paying_twice_or_replaying_the_same_payment_only_counts_once(): void
    {
        $o = $this->startPlan();
        $this->fakePayment($this->rzpPayment($o));
        $sig = $this->sig($o->razorpay_order_id, 'pay_TESTPAY00001');

        $this->checkout()->call('verifyOnline', 'pay_TESTPAY00001', $o->razorpay_order_id, $sig);
        $this->checkout()->call('verifyOnline', 'pay_TESTPAY00001', $o->razorpay_order_id, $sig);

        $this->assertSame(1, SubscriptionPayment::count());
        $this->assertSame(1, \App\Models\SubscriptionInvoice::count());
    }

    public function test_another_librarys_order_cannot_be_completed_from_this_one(): void
    {
        $this->actingAs($this->admin);                                                   // platform staff set up the other library
        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@rz.test']);
        app(CreateSubscription::class)($other, $this->plan, 'monthly', false);
        $this->actingAs($this->owner);
        $theirs = OnlinePayment::create(['library_id' => $other->id, 'purpose' => 'subscription', 'subscription_plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'amount' => '1000.00', 'razorpay_order_id' => 'order_BETAORDER001']);
        Tenancy::set($this->lib);                                                         // back to Alpha, the library signed in
        $this->fakePayment($this->rzpPayment($theirs));

        $this->checkout()->call('verifyOnline', 'pay_TESTPAY00001', $theirs->razorpay_order_id, $this->sig($theirs->razorpay_order_id, 'pay_TESTPAY00001'));

        $this->assertSame('created', $theirs->fresh()->status);
        $this->assertSame(0, SubscriptionPayment::count());
    }

    public function test_an_authorized_payment_is_captured_then_fulfilled(): void
    {
        $o = $this->startPlan();
        $this->fakePayment($this->rzpPayment($o, ['status' => 'authorized']));

        $this->checkout()->call('verifyOnline', 'pay_TESTPAY00001', $o->razorpay_order_id, $this->sig($o->razorpay_order_id, 'pay_TESTPAY00001'));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/capture') && $r['amount'] === $o->paise());
        $this->assertSame('paid', $o->fresh()->status);
    }

    public function test_coins_are_spent_when_the_payment_is_confirmed(): void
    {
        ReferralCoin::create(['library_id' => $this->lib->id, 'coins' => 100, 'kind' => 'earned', 'description' => 'test']);
        $o = $this->startPlan('quarterly', true);
        $this->fakePayment($this->rzpPayment($o));

        $this->checkout()->call('verifyOnline', 'pay_TESTPAY00001', $o->razorpay_order_id, $this->sig($o->razorpay_order_id, 'pay_TESTPAY00001'));

        $this->assertSame([0, 100], [ReferralCoin::balance($this->lib->id), SubscriptionPayment::firstOrFail()->coins_used]);
    }

    // ---------------------------------------------------------------- credits

    public function test_credits_paid_online_are_added_straight_away(): void
    {
        $this->fakeOrders('order_CREDITS00001');
        $o = app(StartOnlinePayment::class)->credits($this->lib, $this->owner, 'sms', 1000)['payment'];
        $this->assertSame('210.00', $o->amount);
        $this->fakePayment($this->rzpPayment($o));

        Livewire::withQueryParams(['channel' => 'sms', 'credits' => 1000])->test('pages::app.subscription.credits-checkout')
            ->call('verifyOnline', 'pay_TESTPAY00001', $o->razorpay_order_id, $this->sig($o->razorpay_order_id, 'pay_TESTPAY00001'))->assertSet('onlineError', '');

        $this->assertSame(1000, $this->lib->fresh()->sms_credits);
        $p = CreditPurchase::firstOrFail();
        $this->assertSame(['approved', 'razorpay'], [$p->status, $p->method]);
    }

    // ---------------------------------------------------------------- webhook

    protected function hook(array $body, ?string $secret = self::HOOK, array $headers = [])
    {
        $raw = json_encode($body);

        return $this->call('POST', '/webhooks/razorpay', [], [], [], $this->transformHeadersToServerVars(array_merge(['X-Razorpay-Signature' => hash_hmac('sha256', $raw, $secret ?? ''), 'X-Razorpay-Event-Id' => 'evt_'.bin2hex(random_bytes(6)), 'Content-Type' => 'application/json'], $headers)), $raw);
    }

    protected function capturedEvent(OnlinePayment $o, array $payment = []): array
    {
        return ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => array_merge($this->rzpPayment($o), $payment)]]];
    }

    public function test_the_webhook_activates_a_plan_when_the_browser_never_came_back(): void
    {
        $o = $this->startPlan();
        $this->fakePayment($this->rzpPayment($o));
        $this->actingAs(User::factory()->create());                                       // nobody is signed in as the library

        $this->hook($this->capturedEvent($o))->assertOk();

        $this->assertSame(['paid', 1], [$o->fresh()->status, SubscriptionPayment::count()]);
        $this->assertSame('active', Subscription::currentFor($this->lib->id)->status);
    }

    public function test_a_webhook_with_a_wrong_or_missing_signature_is_rejected_and_changes_nothing(): void
    {
        $o = $this->startPlan();
        $this->fakePayment($this->rzpPayment($o));

        $this->hook($this->capturedEvent($o), 'the-wrong-secret')->assertStatus(400);
        $this->hook($this->capturedEvent($o), '')->assertStatus(400);
        $this->call('POST', '/webhooks/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->capturedEvent($o)))->assertStatus(400);

        $this->assertSame(['created', 0], [$o->fresh()->status, SubscriptionPayment::count()]);
    }

    public function test_the_webhook_is_refused_until_a_webhook_secret_is_saved(): void
    {
        BillingSettings::set(['razorpay_webhook_secret' => '']);
        $o = $this->startPlan();

        $this->hook($this->capturedEvent($o), '')->assertStatus(503);
    }

    public function test_the_webhook_does_not_believe_the_amount_in_its_own_body(): void
    {
        $o = $this->startPlan('quarterly');
        $this->fakePayment($this->rzpPayment($o, ['amount' => 100]));                      // Razorpay itself says ₹1 was paid

        $this->hook($this->capturedEvent($o, ['amount' => $o->paise()]))->assertOk();      // the signed body claims the full amount

        $this->assertSame(['failed', 0], [$o->fresh()->status, SubscriptionPayment::count()]);
    }

    public function test_a_repeated_webhook_and_a_browser_return_for_the_same_payment_count_once(): void
    {
        $o = $this->startPlan();
        $this->fakePayment($this->rzpPayment($o));
        $event = $this->capturedEvent($o);

        $this->hook($event, self::HOOK, ['X-Razorpay-Event-Id' => 'evt_same'])->assertOk();
        $this->hook($event, self::HOOK, ['X-Razorpay-Event-Id' => 'evt_same'])->assertOk();
        $this->hook($event)->assertOk();                                                    // a new event id, same payment
        $this->checkout()->call('verifyOnline', 'pay_TESTPAY00001', $o->razorpay_order_id, $this->sig($o->razorpay_order_id, 'pay_TESTPAY00001'));

        $this->assertSame([1, 1], [SubscriptionPayment::count(), \App\Models\SubscriptionInvoice::count()]);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('razorpay_events')->where('event_id', 'evt_same')->count());
    }

    public function test_a_failed_payment_is_noted_and_an_unknown_order_is_ignored(): void
    {
        $o = $this->startPlan();

        $this->hook(['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => ['id' => 'pay_FAILED000001', 'order_id' => $o->razorpay_order_id, 'error_description' => 'Card declined']]]])->assertOk();
        $this->assertSame(['created', 'Card declined'], [$o->fresh()->status, $o->fresh()->failure_reason]);

        $this->hook(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_UNKNOWN00001', 'order_id' => 'order_NOTOURS00001']]]])->assertOk();
        $this->assertSame(0, SubscriptionPayment::count());
    }

    public function test_when_razorpay_is_unreachable_the_webhook_asks_for_a_retry(): void
    {
        $o = $this->startPlan();
        Http::fake(['api.razorpay.com/*' => Http::response('down', 503)]);

        $this->hook($this->capturedEvent($o))->assertStatus(500);

        $this->assertSame('created', $o->fresh()->status);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('razorpay_events')->count(), 'a failed delivery is not marked as handled, so the retry is processed');
    }

    public function test_the_admin_online_payments_page_lists_orders_and_is_staff_only(): void
    {
        $o = $this->startPlan();
        $this->actingAs($this->admin);

        $this->get('/admin/online-payments')->assertOk()->assertSee('Online payments')->assertSee('Alpha')->assertSee($o->razorpay_order_id)->assertSee('Waiting for payment');
        $this->get('/admin')->assertSee('Online Payments');

        $this->actingAs($this->owner);
        $this->get('/admin/online-payments')->assertForbidden();
    }}
