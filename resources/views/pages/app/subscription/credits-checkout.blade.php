<?php

use App\Models\CreditPurchase;
use App\Support\BillingSettings;
use App\Support\Notifier;
use App\Support\Tenancy;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Same steps as a plan purchase, for SMS and WhatsApp credits: review the order, pick how to pay, then submit the payment details. */
new #[Layout('layouts::panel')] class extends Component
{
    use WithFileUploads;

    public string $channel = 'sms';
    public int $credits = 0;
    public string $method = 'cash';          // cash (reviewed by us) | online (Razorpay, not live yet)
    public bool $onlineNotice = false;

    public bool $showPay = false;
    public string $payMethod = 'bank_transfer';
    public string $reference = '';
    public string $paidOn = '';
    public string $note = '';
    public array $proofs = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('billing.manage'), 403);

        $channel = (string) request('channel');
        $credits = (int) request('credits');
        abort_unless(isset(CreditPurchase::CHANNELS[$channel]) && in_array($credits, (array) $this->rate($channel)['packs'], true), 404);

        $this->channel = $channel;
        $this->credits = $credits;
    }

    protected function rate(string $channel): array
    {
        return (array) (BillingSettings::get('credit_rates')[$channel] ?? BillingSettings::DEFAULTS['credit_rates']['sms']);
    }

    public function updatedMethod(): void
    {
        $this->onlineNotice = false;
    }

    protected function startOnline(): array
    {
        return app(\App\Actions\Billing\StartOnlinePayment::class)->credits(\App\Support\Tenancy::library(), auth()->user(), $this->channel, $this->credits);
    }

    // ---- Razorpay (online) ----
    public string $onlineError = '';
    public bool $onlineBusy = false;

    protected function onlineStart(): void
    {
        $this->onlineError = '';
        try {
            ['options' => $options] = $this->startOnline();
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->onlineError = $e->getMessage();

            return;
        }

        $this->onlineBusy = true;
        $this->dispatch('razorpay-open', options: $options, wireId: $this->getId());
    }

    public function onlineDismissed(): void
    {
        $this->onlineBusy = false;
    }

    public function onlineFailed(string $reason): void
    {
        $this->onlineBusy = false;
        $this->onlineError = mb_substr(strip_tags($reason), 0, 200).'. You have not been charged. Please try again, or choose Pay by Cash.';
    }

    /** Razorpay's answer after checkout. The signature is checked, then Razorpay is asked about the payment before anything is granted. */
    public function verifyOnline(string $paymentId, string $orderId, string $signature)
    {
        abort_unless(auth()->user()->can('billing.manage'), 403);
        $this->onlineBusy = false;

        $order = \App\Support\Razorpay::validOrderId($orderId) && \App\Support\Razorpay::validPaymentId($paymentId)
            ? \App\Models\OnlinePayment::where('razorpay_order_id', $orderId)->where('library_id', \App\Support\Tenancy::currentId())->first()
            : null;

        if (! $order || ! \App\Support\Razorpay::checkoutSignatureValid($orderId, $paymentId, $signature)) {
            $this->onlineError = 'We could not verify that payment. If money was deducted it will be matched automatically, or contact support with your Razorpay payment id.';

            return;
        }

        try {
            $order = app(\App\Actions\Billing\FulfilOnlinePayment::class)($order, \App\Support\Razorpay::fetchPayment($paymentId));
        } catch (\Throwable $e) {
            report($e);
            $this->onlineError = 'Your payment was received, but we could not confirm it just now. It will be activated automatically within a few minutes.';

            return;
        }

        if ($order->status !== 'paid') {
            $this->onlineError = $order->status === 'failed' ? 'The payment could not be matched to your order. Please contact support with payment id '.$paymentId.'.' : 'Your payment is still being processed. It will be activated automatically as soon as it clears.';

            return;
        }

        session()->flash('notice', 'Payment received. '.$order->describe().' is now active.');

        return $this->redirectRoute($order->purpose === 'subscription' ? 'app.subscription.index' : 'app.subscription.plans', array_filter(['library' => \App\Support\Tenancy::slug(), 'tab' => $order->channel]));
    }

    public function proceed(): void
    {
        abort_unless(auth()->user()->can('billing.manage'), 403);

        if ($this->method === 'online') {
            if (\App\Support\Razorpay::enabled()) {
                $this->onlineStart();
            } else {
                $this->onlineNotice = true;
                $this->dispatch('notify', message: 'Online payment with Razorpay is coming soon. Please choose Pay by Cash for now.');
            }

            return;
        }

        $this->reset(['reference', 'note', 'proofs']);
        $this->payMethod = (string) (BillingSettings::get('payment_methods')[0] ?? 'bank_transfer');
        $this->paidOn = today()->toDateString();
        $this->resetErrorBag();
        $this->showPay = true;
    }

    public function closePay(): void
    {
        $this->showPay = false;
    }

    public function savePay()
    {
        abort_unless(auth()->user()->can('billing.manage'), 403);
        abort_unless(in_array($this->credits, (array) $this->rate($this->channel)['packs'], true), 404);

        $kb = (int) BillingSettings::get('upload_limit_kb');
        $this->validate([
            'payMethod' => ['required', 'string'],
            'reference' => ['required', 'string', 'max:100'],
            'paidOn' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'proofs' => [BillingSettings::get('proof_required') ? 'required' : 'nullable', 'array', 'max:5'],
            'proofs.*' => ['file', 'mimes:jpg,jpeg,png,pdf', "max:{$kb}"],
        ], ['proofs.required' => 'Attach proof of payment (a screenshot or receipt).'], ['paidOn' => 'payment date', 'payMethod' => 'method', 'proofs.*' => 'proof file']);

        if (CreditPurchase::where('library_id', Tenancy::currentId())->where('channel', $this->channel)->where('status', 'pending')->exists()) {
            $this->addError('reference', 'You already have a payment waiting for review.');

            return;
        }

        $rate = (string) $this->rate($this->channel)['rate'];
        $purchase = CreditPurchase::create([
            'library_id' => Tenancy::currentId(), 'user_id' => auth()->id(), 'channel' => $this->channel, 'credits' => $this->credits,
            'rate' => $rate, 'amount' => round($this->credits * (float) $rate, 2), 'method' => $this->payMethod, 'reference' => $this->reference, 'paid_on' => $this->paidOn,
            'note' => $this->note ?: null,
        ]);

        foreach ($this->proofs as $file) {
            $purchase->proofs()->create([
                'path' => $file->store('credit-proofs/'.Tenancy::currentId(), 'local'),   // private disk
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
            ]);
        }

        Notifier::platform('payments.approve', 'credits.submitted', 'Credit payment: '.number_format($purchase->credits).' '.CreditPurchase::CHANNELS[$this->channel], Tenancy::library()->name, route('admin.credits.index'));

        session()->flash('notice', 'Payment submitted. Credits are added once it is approved.');

        return $this->redirectRoute('app.subscription.plans', ['library' => Tenancy::slug(), 'tab' => $this->channel]);
    }
}
?>

<div class="space-y-4 rounded-3xl border border-gray-200 bg-white p-4 shadow-[0_10px_40px_rgba(16,32,26,0.14)] sm:p-5">
    <x-slot:header><x-ui.page-title title="Credits payment" subtitle="Review your order and choose how to pay" /></x-slot:header>

    @php
        $label = \App\Models\CreditPurchase::CHANNELS[$channel];
        $r = (array) (\App\Support\BillingSettings::get('credit_rates')[$channel] ?? []);
        $rate = (float) $r['rate'];
        $mrp = max($rate, (float) $r['mrp']);
        $total = round($credits * $rate, 2);
        $list = round($credits * $mrp, 2);
        $off = round($list - $total, 2);
        $rs = fn ($v) => '₹'.number_format((float) $v, 2);
        $slug = \App\Support\Tenancy::slug();
        $pending = \App\Models\CreditPurchase::where('library_id', \App\Support\Tenancy::currentId())->where('channel', $channel)->where('status', 'pending')->exists();
    @endphp

    <div class="rounded-2xl bg-gradient-to-r from-slate-900 via-teal-900 to-slate-800 p-5 text-white shadow">
        <div class="flex items-center gap-4">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/15"><x-lm.icon name="chat" class="h-6 w-6" /></span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-white/70">Continue buying</p>
                <p class="text-lg font-extrabold">{{ $label }} Credits | {{ number_format($credits) }} credits | ₹{{ number_format($total) }}</p>
                <p class="mt-0.5 text-xs text-white/80">Credits purchase for {{ number_format($credits) }} credits. They have no separate validity; use them while your plan remains active.</p>
            </div>
        </div>
    </div>

    @if ($pending)
        <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">You already have a {{ $label }} credit payment waiting for review. You can buy more once it is approved.</p>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="space-y-4">
            <div class="flex flex-wrap gap-2 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                @foreach (['Secure payments', 'Data privacy', '100% authentic'] as $b)
                    <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-600"><x-lm.icon name="shield" class="h-3.5 w-3.5" />{{ $b }}</span>
                @endforeach
            </div>
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <label class="flex cursor-pointer items-center gap-3 p-4 {{ $method === 'cash' ? 'bg-brand-50/50' : '' }}">
                    <input type="radio" wire:model.live="method" value="cash" class="h-5 w-5 border-gray-300 text-brand-600 focus:ring-brand-500">
                    <span><span class="block text-sm font-extrabold text-gray-900">Pay by Cash</span><span class="block text-xs text-gray-500">Cash, bank transfer or UPI paid to us directly. You send the details and we approve it.</span></span>
                </label>
                <label class="flex cursor-pointer items-center gap-3 border-t border-gray-100 p-4 {{ $method === 'online' ? 'bg-brand-50/50' : '' }}">
                    <input type="radio" wire:model.live="method" value="online" class="h-5 w-5 border-gray-300 text-brand-600 focus:ring-brand-500">
                    <span class="flex-1"><span class="block text-sm font-extrabold text-gray-900">UPI / Debit / Credit Cards / NetBanking</span><span class="block text-xs text-gray-500">Most secure &amp; trusted payment gateway</span></span>
                    @if (\App\Support\Razorpay::enabled())<span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-bold text-emerald-800">Secure</span>@else<span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-bold text-amber-800">Coming soon</span>@endif
                </label>
                <p class="border-t border-gray-100 bg-gray-50 px-4 py-2 text-center text-[11px] text-gray-500">Powered by <span class="font-bold italic text-blue-700">Razorpay</span></p>
            </section>
            @if ($onlineError)
                <p class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $onlineError }}</p>
            @endif
            @if ($onlineNotice)
                <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Online payment with Razorpay is coming soon. Please choose <strong>Pay by Cash</strong> for now, then we confirm your payment.</p>
            @endif
        </div>

        <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="text-base font-extrabold text-gray-900">Price details</h3>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-gray-600">Package amount ({{ number_format($credits) }} × ₹{{ rtrim(rtrim(number_format($mrp, 2), '0'), '.') }})</dt><dd class="font-medium text-gray-900">{{ $rs($list) }}</dd></div>
                @if ($off > 0)<div class="flex justify-between"><dt class="text-gray-600">Discount (₹{{ rtrim(rtrim(number_format($rate, 2), '0'), '.') }} per credit)</dt><dd class="font-medium text-emerald-600">- {{ $rs($off) }}</dd></div>@endif
                <div class="flex justify-between border-t border-gray-100 pt-3 text-base"><dt class="font-extrabold text-gray-900">Total amount</dt><dd class="font-extrabold text-gray-900">{{ $rs($total) }}</dd></div>
            </dl>
            @if ($off > 0)<p class="mt-3 rounded-lg bg-emerald-50 py-2 text-center text-sm font-medium text-emerald-700">You'll save {{ $rs($off) }} on this order!</p>@endif
        </section>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
            @if ($off > 0)<p class="text-xs text-gray-400 line-through">{{ $rs($list) }}</p>@endif
            <p class="text-2xl font-extrabold text-gray-900">{{ $rs($total) }}</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('app.subscription.plans', ['library' => $slug, 'tab' => $channel]) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-800">← Change pack</a>
            <button type="button" wire:click="proceed" wire:loading.attr="disabled" @disabled($pending || $onlineBusy) class="rounded-xl bg-gradient-to-r from-teal-800 to-slate-900 px-8 py-3 text-sm font-extrabold uppercase tracking-wide text-white shadow hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50">Continue to payment</button>
        </div>
    </div>
    <p class="text-center text-xs text-gray-500">Continue to payment to purchase credits.</p>

    @if ($showPay)
        <x-ui.modal title="Pay for {{ number_format($credits) }} {{ $label }} credits" close="closePay()" max="max-w-lg" wire:key="credits-pay">
            <form wire:submit="savePay" class="space-y-4">
                <div class="flex items-center justify-between rounded-lg bg-brand-50 px-4 py-3"><span class="text-sm text-gray-700">Amount to pay</span><span class="text-xl font-extrabold text-brand-700">{{ $rs($total) }}</span></div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 whitespace-pre-line">{{ \App\Support\BillingSettings::get('payment_instructions') ?: 'Pay using the details shared by support, then fill in this form.' }}</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select name="payMethod" label="Method" wire:model="payMethod">@foreach ((array) \App\Support\BillingSettings::get('payment_methods') as $m) <option value="{{ $m }}">{{ \App\Models\SubscriptionPayment::METHODS[$m] ?? $m }}</option> @endforeach</x-ui.select>
                    <x-ui.input name="paidOn" label="Payment date" type="date" required wire:model="paidOn" />
                </div>
                <x-ui.input name="reference" label="Transaction / UTR reference" required wire:model="reference" />
                <x-ui.textarea name="note" label="Note (optional)" wire:model="note" />
                <div>
                    <label class="block text-sm font-medium text-gray-700">Proof of payment @if (\App\Support\BillingSettings::get('proof_required'))<span class="text-red-500">*</span>@endif</label>
                    <input type="file" multiple wire:model="proofs" accept=".jpg,.jpeg,.png,.pdf" class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700">
                    <p class="mt-1 text-xs text-gray-500">JPG, PNG or PDF, up to {{ round(\App\Support\BillingSettings::get('upload_limit_kb') / 1024, 1) }} MB each, max 5 files.</p>
                    @error('proofs') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    @error('proofs.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closePay">Cancel</x-ui.button><x-ui.button type="submit" wire:target="savePay,proofs">Submit payment</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
