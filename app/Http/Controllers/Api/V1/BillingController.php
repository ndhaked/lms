<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\FulfilOnlinePayment;
use App\Actions\Billing\StartOnlinePayment;
use App\Actions\Billing\SubmitSubscriptionPayment;
use App\Exceptions\BusinessRuleException;
use App\Http\Resources\Api\V1\Present;
use App\Models\CreditPurchase;
use App\Models\OnlinePayment;
use App\Models\ReferralCoin;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Support\BillingSettings;
use App\Support\Entitlements;
use App\Support\Notifier;
use App\Support\Pdf;
use App\Support\PdfDocs;
use App\Support\Pricing;
use App\Support\Razorpay;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The library's own subscription with the platform: plans and prices, checkout by Razorpay or by cash with proof,
 * SMS / WhatsApp credit packs, invoices. Online payments are created and checked on the server only:
 * the app opens Razorpay Checkout with the order we return and sends back Razorpay's answer, whose signature
 * is verified and whose payment is fetched from Razorpay before anything is granted (the webhook also confirms it).
 */
class BillingController extends Controller
{
    public function subscription(): JsonResponse
    {
        $this->authorizePermission('billing.view');
        $library = $this->library();
        $s = Entitlements::subscription();

        return $this->ok([
            'subscription' => $s ? [
                'plan' => $s->plan_name, 'plan_id' => $s->subscription_plan_id, 'cycle' => $s->billing_cycle, 'price' => Present::money($s->price),
                'status' => $s->effectiveStatus(), 'state' => Entitlements::state(), 'covered_until' => Present::d($s->coveredUntil()),
                'grace_ends_on' => Present::d($s->graceEndsOn()), 'days_left' => $s->daysLeft(), 'complimentary' => (bool) $s->is_complimentary,
                'limits' => ['students' => $s->max_students, 'seats' => $s->max_seats, 'staff' => $s->max_staff],
                'usage' => ['students' => Entitlements::usage('students'), 'seats' => Entitlements::usage('seats'), 'staff' => Entitlements::usage('staff')],
            ] : null,
            'modules' => collect(SubscriptionPlan::MODULES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l, 'enabled' => Entitlements::hasModule($k), 'source' => Entitlements::moduleSource($k)])->values()->all(),
            'credits' => ['sms' => (int) $library->sms_credits, 'whatsapp' => (int) $library->whatsapp_credits],
            'coins' => ReferralCoin::balance($library->id),
            'payments' => SubscriptionPayment::where('library_id', $library->id)->with('plan:id,name')->latest('id')->limit(20)->get()->map(fn ($p) => [
                'id' => $p->id, 'kind' => 'subscription', 'description' => ($p->plan?->name ?? 'Plan').' · '.(SubscriptionPlan::CYCLES[$p->billing_cycle] ?? $p->billing_cycle),
                'amount' => Present::money($p->amount), 'method' => $p->method, 'method_label' => SubscriptionPayment::METHODS[$p->method] ?? $p->method,
                'status' => $p->status, 'status_label' => SubscriptionPayment::STATUSES[$p->status] ?? $p->status, 'paid_on' => Present::d($p->paid_on), 'review_note' => $p->review_note,
            ])->concat(CreditPurchase::where('library_id', $library->id)->latest('id')->limit(20)->get()->map(fn ($c) => [
                'id' => $c->id, 'kind' => 'credits', 'description' => number_format($c->credits).' '.CreditPurchase::CHANNELS[$c->channel].' credits',
                'amount' => Present::money($c->amount), 'method' => $c->method, 'method_label' => SubscriptionPayment::METHODS[$c->method] ?? $c->method,
                'status' => $c->status, 'status_label' => CreditPurchase::STATUSES[$c->status] ?? $c->status, 'paid_on' => Present::d($c->paid_on), 'review_note' => $c->review_note,
            ]))->sortByDesc('paid_on')->values()->all(),
            'invoices' => SubscriptionInvoice::where('library_id', $library->id)->latest('issued_on')->latest('id')->limit(20)->get()->map(fn ($i) => [
                'id' => $i->id, 'invoice_no' => $i->invoice_no, 'amount' => Present::money($i->amount), 'status' => $i->status, 'issued_on' => Present::d($i->issued_on),
                'period' => [Present::d($i->period_starts_on), Present::d($i->period_ends_on)], 'description' => $i->description,
            ])->all(),
            'pay_options' => $this->payOptions(),
        ]);
    }

    /** Plans and prices. type=library (subscription plans), sms or whatsapp (credit packs). */
    public function plans(Request $request): JsonResponse
    {
        $this->authorizePermission('billing.view');
        $type = $request->query('type', 'library');

        if (in_array($type, ['sms', 'whatsapp'], true)) {
            $r = (array) (BillingSettings::get('credit_rates')[$type] ?? []);
            $rate = (float) ($r['rate'] ?? 0);

            return $this->ok(['type' => $type, 'rate' => $rate, 'mrp' => (float) ($r['mrp'] ?? 0), 'min' => (int) ($r['min'] ?? 0),
                'packs' => collect((array) ($r['packs'] ?? []))->map(fn ($n) => ['credits' => (int) $n, 'amount' => round($n * $rate, 2)])->values()->all(),
                'balance' => (int) $this->library()->{$type.'_credits'}]);
        }

        $current = Entitlements::subscription();

        return $this->ok(['type' => 'library', 'plans' => SubscriptionPlan::where('status', 'active')->orderBy('price_monthly')->get()->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'description' => $p->description, 'current' => $current?->subscription_plan_id === $p->id,
            'prices' => collect($p->cycles())->mapWithKeys(fn ($c) => [$c => Present::money($p->priceFor($c))])->all(),
            'limits' => ['students' => $p->max_students, 'seats' => $p->max_seats, 'staff' => $p->max_staff],
            'modules' => collect((array) $p->modules)->map(fn ($m) => ['key' => $m, 'label' => SubscriptionPlan::MODULES[$m] ?? $m])->values()->all(),
            'trial_days' => (int) $p->trial_days,
        ])->all(), 'support' => ['phone' => BillingSettings::get('support_phone'), 'email' => BillingSettings::get('support_email')]]);
    }

    /** Price breakdown for a plan and cycle, with how many referral coins can be used. */
    public function quote(Request $request): JsonResponse
    {
        $this->authorizePermission('billing.view');
        [$plan, $cycle] = $this->planAndCycle($request);

        return $this->ok($this->breakdown($plan, $cycle, $request->boolean('use_coins')));
    }

    protected function planAndCycle(Request $request): array
    {
        $data = $request->validate(['plan_id' => ['required', 'integer'], 'cycle' => ['required', Rule::in(array_keys(SubscriptionPlan::CYCLES))]]);
        $plan = SubscriptionPlan::where('status', 'active')->find($data['plan_id']);
        abort_unless($plan && in_array($data['cycle'], $plan->cycles(), true), 422, 'That plan is not available for that billing period.');

        return [$plan, $data['cycle']];
    }

    protected function breakdown(SubscriptionPlan $plan, string $cycle, bool $useCoins): array
    {
        $q = Pricing::quote(Tenancy::currentId(), $plan, $cycle);
        $max = BillingSettings::get('referral_enabled') ? ReferralCoin::maxRedeemable(Tenancy::currentId(), (float) $q['total']) : 0;
        $coins = $useCoins ? $max : 0;
        $off = round($coins * ReferralCoin::coinValue(), 2);

        return [
            'plan' => ['id' => $plan->id, 'name' => $plan->name], 'cycle' => $cycle,
            'lines' => collect($q['lines'])->map(fn ($l) => ['label' => $l['label'], 'amount' => Present::money($l['amount'])])->all(),
            'subtotal' => Present::money($q['total']),
            'coins' => ['available' => ReferralCoin::balance(Tenancy::currentId()), 'max_usable' => $max, 'used' => $coins, 'discount' => $off],
            'total' => Present::money(max(0, (float) $q['total'] - $off)),
        ];
    }

    protected function payOptions(): array
    {
        return [
            'online' => Razorpay::enabled(),
            'cash_methods' => collect((array) BillingSettings::get('payment_methods'))->map(fn ($m) => ['key' => $m, 'label' => SubscriptionPayment::MANUAL_METHODS[$m] ?? $m])->values()->all(),
            'instructions' => (string) BillingSettings::get('payment_instructions'),
            'proof_required' => (bool) BillingSettings::get('proof_required'),
            'upload_limit_kb' => (int) BillingSettings::get('upload_limit_kb'),
        ];
    }

    // ---------- Razorpay ----------

    /** Start an online payment for a plan: returns the options to open Razorpay Checkout with. */
    public function onlineSubscription(Request $request): JsonResponse
    {
        $this->authorizePermission('billing.manage');
        [$plan, $cycle] = $this->planAndCycle($request);
        ['options' => $options] = app(StartOnlinePayment::class)->subscription($this->library(), $request->user(), $plan, $cycle, $request->boolean('use_coins'));

        return $this->ok(['razorpay' => $options], 201);
    }

    /** Start an online payment for an SMS / WhatsApp credit pack. */
    public function onlineCredits(Request $request): JsonResponse
    {
        $this->authorizePermission('billing.manage');
        $data = $request->validate(['channel' => ['required', Rule::in(array_keys(CreditPurchase::CHANNELS))], 'credits' => ['required', 'integer', 'min:1']]);
        ['options' => $options] = app(StartOnlinePayment::class)->credits($this->library(), $request->user(), $data['channel'], (int) $data['credits']);

        return $this->ok(['razorpay' => $options], 201);
    }

    /** Razorpay Checkout's success answer. Verified (signature + payment fetched from Razorpay) before anything is granted. */
    public function verifyOnline(Request $request): JsonResponse
    {
        $this->authorizePermission('billing.manage');
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string', 'max:64'],
            'razorpay_order_id' => ['required', 'string', 'max:64'],
            'razorpay_signature' => ['required', 'string', 'max:256'],
        ]);
        $order = Razorpay::validOrderId($data['razorpay_order_id']) && Razorpay::validPaymentId($data['razorpay_payment_id'])
            ? OnlinePayment::where('razorpay_order_id', $data['razorpay_order_id'])->where('library_id', Tenancy::currentId())->first() : null;

        if (! $order || ! Razorpay::checkoutSignatureValid($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature'])) {
            return response()->json(['message' => 'We could not verify that payment. If money was deducted it will be matched automatically, or contact support with your Razorpay payment id.', 'status' => 'unverified'], 422);
        }

        try {
            $order = app(FulfilOnlinePayment::class)($order, Razorpay::fetchPayment($data['razorpay_payment_id']));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Your payment was received, but we could not confirm it just now. It will be activated automatically within a few minutes.', 'status' => 'processing'], 202);
        }

        return match ($order->status) {
            'paid' => $this->message('Payment received. '.$order->describe().' is now active.', ['status' => 'paid', 'order' => $order->razorpay_order_id]),
            'failed' => response()->json(['message' => 'The payment could not be matched to your order. Please contact support with payment id '.$data['razorpay_payment_id'].'.', 'status' => 'failed'], 422),
            default => response()->json(['message' => 'Your payment is still being processed. It will be activated automatically as soon as it clears.', 'status' => 'processing'], 202),
        };
    }

    // ---------- pay by cash / bank / UPI with proof ----------

    public function cashSubscription(Request $request): JsonResponse
    {
        $this->authorizePermission('billing.manage');
        [$plan, $cycle] = $this->planAndCycle($request);
        $data = $this->cashData($request, false);
        $quote = $this->breakdown($plan, $cycle, $request->boolean('use_coins'));

        $payment = app(SubmitSubscriptionPayment::class)(
            $this->library(), $plan, $cycle, $data['reference'], number_format($quote['total'], 2, '.', ''), $data['method'],
            Carbon::parse($data['paid_on']), $data['note'] ?? null, $request->file('proofs', []), $quote['coins']['used'],
        );

        return $this->message('Payment submitted. We will review it shortly and activate your plan.', ['id' => $payment->id, 'status' => $payment->status, 'amount' => Present::money($payment->amount)], 201);
    }

    public function cashCredits(Request $request): JsonResponse
    {
        $this->authorizePermission('billing.manage');
        $this->writable();
        $channel = $request->validate(['channel' => ['required', Rule::in(array_keys(CreditPurchase::CHANNELS))], 'credits' => ['required', 'integer']]);
        $rate = (array) (BillingSettings::get('credit_rates')[$channel['channel']] ?? []);
        abort_unless(in_array((int) $channel['credits'], (array) ($rate['packs'] ?? []), true), 422, 'That credit pack is not available.');
        $data = $this->cashData($request, (bool) BillingSettings::get('proof_required'));

        if (CreditPurchase::where('library_id', Tenancy::currentId())->where('channel', $channel['channel'])->where('status', 'pending')->exists()) {
            throw new BusinessRuleException('You already have a payment waiting for review.');
        }

        $purchase = CreditPurchase::create([
            'library_id' => Tenancy::currentId(), 'user_id' => auth()->id(), 'channel' => $channel['channel'], 'credits' => (int) $channel['credits'],
            'rate' => (string) $rate['rate'], 'amount' => round($channel['credits'] * (float) $rate['rate'], 2), 'method' => $data['method'],
            'reference' => $data['reference'], 'paid_on' => $data['paid_on'], 'note' => $data['note'] ?? null,
        ]);
        foreach ($request->file('proofs', []) as $file) {
            $purchase->proofs()->create([
                'path' => $file->store('credit-proofs/'.Tenancy::currentId(), 'local'),
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'mime' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(),
            ]);
        }
        Notifier::platform('payments.approve', 'credits.submitted', 'Credit payment: '.number_format($purchase->credits).' '.CreditPurchase::CHANNELS[$purchase->channel], $this->library()->name, route('admin.credits.index'));

        return $this->message('Payment submitted. Credits are added once it is approved.', ['id' => $purchase->id, 'status' => $purchase->status, 'amount' => Present::money($purchase->amount)], 201);
    }

    protected function cashData(Request $request, bool $proofRequired): array
    {
        $kb = (int) BillingSettings::get('upload_limit_kb');

        return $request->validate([
            'method' => ['required', Rule::in((array) BillingSettings::get('payment_methods'))],
            'reference' => ['required', 'string', 'max:100'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'proofs' => [$proofRequired ? 'required' : 'nullable', 'array', 'max:5'],
            'proofs.*' => ['file', 'mimes:jpg,jpeg,png,pdf', "max:{$kb}"],
        ], ['proofs.required' => 'Attach proof of payment (a screenshot or receipt).'], ['paid_on' => 'payment date']);
    }

    public function invoice(int $invoice)
    {
        $this->authorizePermission('billing.view');
        $i = SubscriptionInvoice::where('library_id', Tenancy::currentId())->findOrFail($invoice);

        return Pdf::download('pdf.document', PdfDocs::invoice($i, BillingSettings::all()), "invoice-{$i->invoice_no}.pdf");
    }
}
