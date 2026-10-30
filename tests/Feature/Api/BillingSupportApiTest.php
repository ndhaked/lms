<?php

namespace Tests\Feature\Api;

use App\Models\CreditPurchase;
use App\Models\MessageLog;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\SupportRequest;
use App\Support\BillingSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BillingSupportApiTest extends ApiTestCase
{
    protected function givePlan(): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create(['name' => 'Standard', 'price_monthly' => 999, 'price_quarterly' => 2799, 'price_annual' => 9999, 'max_students' => 500, 'max_seats' => 250, 'max_staff' => 10, 'modules' => ['attendance', 'reports', 'exports', 'expenses'], 'grace_days' => 7, 'status' => 'active']);
        $sub = new Subscription(['library_id' => $this->library->id]);
        $sub->forceFill(['library_id' => $this->library->id, 'status' => Subscription::ACTIVE, 'starts_on' => today(), 'expires_on' => today()->addMonth()]);
        $sub->applyPlan($plan, 'monthly');
        $sub->save();

        return $plan;
    }

    public function test_subscription_plans_quote_and_cash_payment_with_proof(): void
    {
        Storage::fake('local');
        $plan = $this->givePlan();
        $t = $this->ownerToken();

        $this->as($t)->getJson($this->lib('subscription'))->assertOk()->assertJsonPath('data.subscription.plan', 'Standard')->assertJsonStructure(['data' => ['modules', 'credits', 'payments', 'invoices', 'pay_options']]);
        $this->as($t)->getJson($this->lib('subscription/plans'))->assertOk()->assertJsonPath('data.plans.0.prices.monthly', 999)->assertJsonPath('data.plans.0.current', true);
        $this->as($t)->getJson($this->lib('subscription/plans?type=sms'))->assertOk()->assertJsonStructure(['data' => ['rate', 'packs']]);
        $this->as($t)->postJson($this->lib('subscription/quote'), ['plan_id' => $plan->id, 'cycle' => 'quarterly'])->assertOk()->assertJsonPath('data.total', 2799);

        $method = BillingSettings::get('payment_methods')[0];
        $this->as($t)->postJson($this->lib('subscription/checkout/cash'), ['plan_id' => $plan->id, 'cycle' => 'monthly', 'method' => $method, 'reference' => 'UTR55', 'paid_on' => today()->toDateString()])
            ->assertStatus(422);   // proof required by default
        $this->as($t)->post($this->lib('subscription/checkout/cash'), ['plan_id' => $plan->id, 'cycle' => 'monthly', 'method' => $method, 'reference' => 'UTR55', 'paid_on' => today()->toDateString(), 'note' => 'Paid at office',
            'proofs' => [UploadedFile::fake()->image('proof.png')]])->assertCreated()->assertJsonPath('data.amount', 999);
        $this->assertSame(1, SubscriptionPayment::where('library_id', $this->library->id)->count());
    }

    public function test_online_checkout_is_refused_while_razorpay_is_off_and_bad_signatures_are_rejected(): void
    {
        $plan = $this->givePlan();
        $t = $this->ownerToken();
        $this->as($t)->postJson($this->lib('subscription/checkout/online'), ['plan_id' => $plan->id, 'cycle' => 'monthly'])->assertStatus(422)->assertJsonPath('message', 'Online payment is not available right now.');
        $this->as($t)->postJson($this->lib('subscription/checkout/verify'), ['razorpay_payment_id' => 'pay_X', 'razorpay_order_id' => 'order_X', 'razorpay_signature' => 'bad'])->assertStatus(422)->assertJsonPath('status', 'unverified');
    }

    public function test_credit_packs_by_cash_one_pending_at_a_time(): void
    {
        Storage::fake('local');
        $t = $this->ownerToken();
        $pack = (array) BillingSettings::get('credit_rates')['sms']['packs'];
        $body = ['channel' => 'sms', 'credits' => $pack[0], 'method' => BillingSettings::get('payment_methods')[0], 'reference' => 'R1', 'paid_on' => today()->toDateString(), 'proofs' => [UploadedFile::fake()->image('p.jpg')]];
        $this->as($t)->post($this->lib('subscription/credits/cash'), $body)->assertCreated();
        $this->as($t)->post($this->lib('subscription/credits/cash'), $body)->assertStatus(422);
        $this->as($t)->post($this->lib('subscription/credits/cash'), ['credits' => 7] + $body)->assertStatus(422);
        $this->assertSame(1, CreditPurchase::count());
    }

    public function test_staff_without_billing_rights_cannot_pay_or_see_billing(): void
    {
        $this->givePlan();
        $this->staff($this->roleName('RECEPTIONIST'), 'desk@alpha.test');
        $desk = $this->login('desk@alpha.test');
        $this->as($desk)->getJson($this->lib('subscription'))->assertForbidden();
        $this->as($desk)->postJson($this->lib('subscription/checkout/cash'), [])->assertForbidden();
    }

    public function test_restricted_subscription_only_leaves_billing_and_help_open(): void
    {
        $this->givePlan();
        DB::table('subscriptions')->update(['status' => Subscription::SUSPENDED]);
        $t = $this->ownerToken();
        $this->as($t)->getJson($this->lib('students'))->assertStatus(402)->assertJsonPath('code', 'subscription_restricted');
        $this->as($t)->getJson($this->lib('subscription'))->assertOk();
        $this->as($t)->getJson($this->lib('help'))->assertOk();
        $this->as($t)->getJson($this->lib())->assertOk();
    }

    public function test_refer_help_contact_and_qr(): void
    {
        $t = $this->ownerToken();
        $this->as($t)->getJson($this->lib('refer'))->assertOk()->assertJsonPath('data.code', $this->library->code)->assertJsonPath('data.reward_coins', 100);
        $en = $this->as($t)->getJson($this->lib('help'))->assertOk()->json('data.faqs.0.question');
        $hi = $this->as($t)->getJson($this->lib('help?lang=hi'))->assertOk()->json('data.faqs.0.question');
        $this->assertNotSame($en, $hi);
        $this->as($t)->postJson($this->lib('help/requests'), ['subject' => 'Help', 'body' => 'Seats page'])->assertCreated();
        $this->assertSame(1, SupportRequest::count());
        $this->as($t)->getJson($this->lib('contact'))->assertOk()->assertJsonPath('data.phone', BillingSettings::get('support_phone'));
        $this->as($t)->getJson($this->lib('qr?source=referral'))->assertOk()->assertJsonPath('data.text', url('/').'?ref='.$this->library->code);
        $this->as($t)->getJson($this->lib('qr?source=custom'))->assertStatus(422);
    }

    public function test_messages_counters_history_toggles_and_whatsapp_templates(): void
    {
        $s = $this->student();
        $this->inLibrary(fn () => MessageLog::create(['student_id' => $s->id, 'channel' => 'sms', 'mobile' => '9999999999', 'count' => 1, 'message' => 'Hi', 'status' => 'failed', 'error' => 'No credits']));
        $t = $this->ownerToken();
        $this->as($t)->getJson($this->lib('messages/counters'))->assertOk()->assertJsonCount(2, 'data');
        $this->as($t)->getJson($this->lib('messages?channel=sms'))->assertOk()->assertJsonPath('data.0.status_label', 'Not sent')->assertJsonPath('data.0.reason', 'No credits')->assertJsonPath('totals.failed', 1);

        $this->as($t)->putJson($this->lib('message-templates/automatic'), ['event' => 'payment', 'channel' => 'whatsapp', 'enabled' => true])->assertOk();
        $auto = collect($this->as($t)->getJson($this->lib('message-templates'))->assertOk()->json('data.automatic'))->firstWhere('event', 'payment');
        $this->assertTrue($auto['whatsapp']);

        $id = $this->as($t)->postJson($this->lib('message-templates/whatsapp'), ['name' => 'Exam', 'message' => 'Hi {name}, all the best!'])->assertCreated()->json('data.id');
        $this->as($t)->putJson($this->lib("message-templates/whatsapp/{$id}"), ['name' => 'Exam', 'message' => 'Good luck {name}'])->assertOk();
        $this->as($t)->deleteJson($this->lib("message-templates/whatsapp/{$id}"))->assertOk();

        $this->staff($this->roleName('ACCOUNTANT'), 'acc@alpha.test');
        $acc = $this->login('acc@alpha.test');
        $this->as($acc)->putJson($this->lib('message-templates/automatic'), ['event' => 'payment', 'channel' => 'sms', 'enabled' => true])->assertForbidden();
    }
}
