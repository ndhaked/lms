<?php

use App\Actions\Billing\RespondToPaymentInfoRequest;
use App\Actions\Billing\SubmitSubscriptionPayment;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Support\BillingSettings;
use App\Support\Entitlements;
use App\Support\Notifier;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithFileUploads;

    public bool $showPay = false;
    public string $planId = '';
    public string $cycle = 'monthly';
    public string $amount = '';
    public string $method = 'bank_transfer';
    public string $reference = '';
    public string $paidOn = '';
    public string $note = '';
    public array $proofs = [];

    public ?int $replyTo = null;
    public string $reply = '';
    public array $replyFiles = [];

    public function mount(): void
    {
        $this->authorizePermission('billing.view');
    }

    #[Computed]
    public function sub(): ?Subscription
    {
        return Subscription::with(['plan', 'nextPlan'])->where('library_id', Tenancy::currentId())->latest('id')->first();
    }

    #[Computed]
    public function plans()
    {
        return SubscriptionPlan::where('status', 'active')->orderBy('price_monthly')->get();
    }

    #[Computed]
    public function payments()
    {
        return SubscriptionPayment::with(['proofs', 'invoice', 'plan'])->where('library_id', Tenancy::currentId())->latest('id')->limit(30)->get();
    }

    #[Computed]
    public function open(): ?SubscriptionPayment
    {
        return $this->payments->first(fn ($p) => $p->isOpen());
    }

    #[Computed]
    public function usage(): array
    {
        $s = $this->sub;

        return collect(['students' => 'Students', 'seats' => 'Seats', 'staff' => 'Staff accounts'])->map(fn ($label, $key) => [
            'label' => $label, 'used' => Entitlements::usage($key), 'limit' => $s?->limit($key),
        ])->values()->all();
    }

    public function openPay(): void
    {
        $this->authorizePermission('billing.manage');
        $s = $this->sub;
        abort_unless($s && $s->status !== 'cancelled', 404);

        $this->reset(['reference', 'note', 'proofs']);
        $this->planId = (string) ($s->subscription_plan_id ?? '');
        $this->cycle = $s->billing_cycle;
        $this->method = (string) (BillingSettings::get('payment_methods')[0] ?? 'bank_transfer');
        $this->paidOn = today()->toDateString();
        $this->fillAmount();
        $this->resetErrorBag();
        $this->showPay = true;
    }

    public function updatedPlanId(): void
    {
        $plan = $this->plans->firstWhere('id', (int) $this->planId);
        if ($plan && ! in_array($this->cycle, $plan->cycles(), true)) {
            $this->cycle = $plan->cycles()[0] ?? 'monthly';
        }
        $this->fillAmount();
    }

    public function updatedCycle(): void
    {
        $this->fillAmount();
    }

    protected function fillAmount(): void
    {
        $plan = $this->plans->firstWhere('id', (int) $this->planId) ?? $this->sub?->plan;
        $this->amount = $plan && $plan->priceFor($this->cycle) !== null ? \App\Support\Pricing::quote(Tenancy::currentId(), $plan, $this->cycle)['total'] : '';
    }

    public function savePay(): void
    {
        $this->authorizePermission('billing.manage');

        $kb = (int) BillingSettings::get('upload_limit_kb');
        $this->validate([
            'planId' => ['required', 'integer'],
            'cycle' => ['required', 'in:'.implode(',', array_keys(SubscriptionPlan::CYCLES))],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string'],
            'reference' => ['required', 'string', 'max:100'],
            'paidOn' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'proofs' => ['array', 'max:5'],
            'proofs.*' => ['file', 'mimes:jpg,jpeg,png,pdf', "max:{$kb}"],
        ], [], ['planId' => 'plan', 'paidOn' => 'payment date', 'proofs.*' => 'proof file']);

        try {
            app(SubmitSubscriptionPayment::class)(
                Tenancy::library(), SubscriptionPlan::findOrFail($this->planId), $this->cycle, $this->reference, $this->amount,
                $this->method, Carbon::parse($this->paidOn), $this->note ?: null, $this->proofs,
            );
        } catch (BusinessRuleException $e) {
            $this->addError('reference', $e->getMessage());

            return;
        }

        $this->showPay = false;
        unset($this->payments, $this->open);
        $this->dispatch('notify', message: 'Payment submitted. We will review it shortly.');
    }

    public function startReply(int $id): void
    {
        $this->authorizePermission('billing.manage');
        $p = SubscriptionPayment::where('library_id', Tenancy::currentId())->findOrFail($id);
        abort_unless($p->status === 'info_requested', 404);

        $this->replyTo = $p->id;
        $this->reset(['reply', 'replyFiles']);
        $this->resetErrorBag();
    }

    public function sendReply(): void
    {
        $this->authorizePermission('billing.manage');
        $kb = (int) BillingSettings::get('upload_limit_kb');
        $this->validate(['reply' => ['nullable', 'string', 'max:1000'], 'replyFiles' => ['array', 'max:5'], 'replyFiles.*' => ['file', 'mimes:jpg,jpeg,png,pdf', "max:{$kb}"]]);

        try {
            app(RespondToPaymentInfoRequest::class)(
                SubscriptionPayment::where('library_id', Tenancy::currentId())->findOrFail($this->replyTo), $this->reply, $this->replyFiles,
            );
        } catch (BusinessRuleException $e) {
            $this->addError('reply', $e->getMessage());

            return;
        }

        $this->replyTo = null;
        unset($this->payments, $this->open);
        $this->dispatch('notify', message: 'Thanks — your reply was sent for review.');
    }

    // ---- contact platform support ----
    public bool $showSupport = false;
    public string $subject = '';
    public string $supportMessage = '';

    #[Computed]
    public function supportRequests()
    {
        return \App\Models\SupportRequest::with('responder')->where('library_id', Tenancy::currentId())->latest('id')->limit(10)->get();
    }

    public function openSupport(): void
    {
        $this->authorizePermission('billing.view');
        $this->reset(['subject', 'supportMessage']);
        $this->resetErrorBag();
        $this->showSupport = true;
    }

    public function requestModule(string $module): void
    {
        $this->authorizePermission('billing.manage');
        abort_unless(array_key_exists($module, SubscriptionPlan::MODULES), 422);

        $this->openSupport();
        $this->subject = 'Please enable '.SubscriptionPlan::MODULES[$module];
        $this->supportMessage = 'We would like to use the '.SubscriptionPlan::MODULES[$module].' module. Please tell us the price and enable it.';
    }

    public function sendSupport(): void
    {
        $this->authorizePermission('billing.view');
        $d = $this->validate(['subject' => ['required', 'string', 'max:150'], 'supportMessage' => ['required', 'string', 'max:3000']], [], ['supportMessage' => 'message']);

        $req = \App\Models\SupportRequest::create(['library_id' => Tenancy::currentId(), 'user_id' => auth()->id(), 'subject' => $d['subject'], 'message' => $d['supportMessage']]);

        Notifier::platform('libraries.manage', 'support.request', 'Support request: '.$req->subject, Tenancy::library()->name, route('admin.support.index'));

        $this->showSupport = false;
        unset($this->supportRequests);
        $this->dispatch('notify', message: 'Sent. We will reply here and in your notifications.');
    }

    public function closeModal(): void
    {
        $this->showPay = false;
        $this->replyTo = null;
        $this->showSupport = false;
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="My subscription" subtitle="Your plan, usage and payments" /></x-slot:header>

    @php
        $s = $this->sub;
        $eff = $s?->effectiveStatus();
        $subscriptionState = $subscriptionState ?? \App\Support\Entitlements::state();
        $color = fn ($st) => match ($st) { 'active' => 'green', 'trial' => 'blue', 'grace' => 'amber', 'pending' => 'purple', 'suspended' => 'amber', 'expired' => 'red', default => 'gray' };
        $payColor = fn ($st) => match ($st) { 'approved' => 'green', 'pending' => 'blue', 'info_requested' => 'amber', 'rejected' => 'red', default => 'gray' };
        $canPay = $this->can('billing.manage') && $s && $s->status !== 'cancelled';
        $slug = \App\Support\Tenancy::slug();
        $support = collect([\App\Support\BillingSettings::get('support_email'), \App\Support\BillingSettings::get('support_phone')])->filter()->implode(' · ');
    @endphp

    @if (! $s)
        <div class="rounded-xl border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">This library has no active plan, so {{ $subscriptionState === 'readonly' ? 'it is read-only: you can view everything but not change anything' : 'the rest of the system is paused' }}. Send a request to the platform team to assign a plan or grant free access{{ $support ? ', or reach us: '.$support : '' }}.
            <div class="mt-4"><x-ui.button wire:click="openSupport">Request access</x-ui.button></div></div>
    @else
        @if ($subscriptionState === 'readonly')
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-semibold">Your library is read-only.</p>
                <p class="mt-1">The subscription has expired. You can still view all your data and reports, but nothing can be added or changed until it is renewed.@if ($canPay) Submit a payment below to restore full access. @endif</p>
            </div>
        @elseif ($subscriptionState === 'restricted')
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-semibold">Your library is paused.</p>
                <p class="mt-1">{{ match ($eff) { 'suspended' => 'The subscription was suspended.', 'cancelled' => 'The subscription was cancelled.', 'pending' => 'The first subscription payment has not been approved yet.', default => 'The subscription has expired.' } }}
                    All your data is safe. Only this page works until it is resolved.@if ($canPay) Submit a payment below. @endif</p>
            </div>
        @elseif ($eff === 'grace')
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Your subscription ended on {{ $s->coveredUntil()->format('d M Y') }}. You can keep working until <strong>{{ $s->graceEndsOn()->format('d M Y') }}</strong>; renew before then to avoid interruption.</div>
        @endif

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-xl border border-gray-200 bg-white p-5 lg:col-span-2">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div><h3 class="text-base font-semibold text-gray-900">{{ $s->plan_name }}</h3><p class="text-xs text-gray-500">{{ ucfirst($s->billing_cycle) }} · ₹{{ number_format((float) $s->price, 2) }}</p></div>
                    @if ($s->is_complimentary) <x-ui.badge color="blue" class="mr-2 px-3 py-1 text-sm">Free access</x-ui.badge> @endif<x-ui.badge :color="$color($eff)" class="px-3 py-1 text-sm">{{ $eff === 'grace' ? 'Grace period' : ucfirst($eff) }}</x-ui.badge>
                </div>
                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
                    @foreach (['Started' => $s->starts_on?->format('d M Y'), 'Trial ends' => $s->trial_ends_on?->format('d M Y'), 'Expires' => $s->expires_on?->format('d M Y'),
                        'Days left' => $s->daysLeft() !== null ? max($s->daysLeft(), 0) : null, 'Grace period' => $s->grace_days.' days',
                        'Next plan' => $s->nextPlan?->name] as $label => $value)
                        <div><dt class="text-xs font-medium text-gray-500">{{ $label }}</dt><dd class="mt-1 text-gray-900">{{ $value ?? '—' }}</dd></div>
                    @endforeach
                </dl>

                <div class="mt-5 space-y-3">
                    @foreach ($this->usage as $u)
                        @php $pct = $u['limit'] ? min(100, round($u['used'] / $u['limit'] * 100)) : 0; @endphp
                        <div wire:key="use-{{ $u['label'] }}">
                            <div class="mb-1 flex justify-between text-xs"><span class="text-gray-600">{{ $u['label'] }}</span><span class="font-medium text-gray-800">{{ $u['used'] }} / {{ $u['limit'] ?? 'Unlimited' }}</span></div>
                            <div class="h-1.5 rounded-full bg-gray-100"><div class="h-1.5 rounded-full {{ $pct >= 90 ? 'bg-red-500' : 'bg-brand-600' }}" style="width: {{ $u['limit'] ? $pct : 8 }}%"></div></div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-5 border-t border-gray-100 pt-4">
                    <p class="mb-2 text-xs font-medium text-gray-500">Modules</p>
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach (\App\Models\SubscriptionPlan::MODULES as $key => $label)
                            @php $src = \App\Support\Entitlements::moduleSource($key); $grant = $src && $src !== 'plan' ? \App\Support\Entitlements::grant($key) : null; @endphp
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="mod-{{ $key }}">
                                <span class="font-medium text-gray-800">{{ $label }}</span>
                                <span class="flex items-center gap-2 text-xs">
                                    @if ($src === 'plan') <x-ui.badge color="green">In your plan</x-ui.badge>
                                    @elseif ($src === 'addon') <x-ui.badge color="blue">Add-on · ₹{{ number_format((float) $grant->price_monthly) }}/mo, billed with your subscription</x-ui.badge>
                                    @elseif ($src === 'complimentary') <x-ui.badge color="purple">Free{{ $grant?->expires_on ? ' until '.$grant->expires_on->format('d M Y') : '' }}</x-ui.badge>
                                    @else
                                        <x-ui.badge color="gray">Not enabled</x-ui.badge>
                                        @if ($this->can('billing.manage')) <button type="button" wire:click="requestModule('{{ $key }}')" class="font-medium text-brand-600 hover:text-brand-800">Request</button> @endif
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>

            <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900">How to pay</h3>
                    @if (filled(\App\Support\BillingSettings::get('payment_instructions')))
                        <p class="mt-2 whitespace-pre-line text-sm text-gray-700">{{ \App\Support\BillingSettings::get('payment_instructions') }}</p>
                    @else
                        <p class="mt-2 text-sm text-gray-400">Payment instructions have not been set. Contact support.</p>
                    @endif
                </div>
                @if ($canPay)
                    @if ($this->open)
                        <p class="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-800">You have a payment {{ $this->open->status === 'pending' ? 'waiting for review' : 'that needs more information' }}.</p>
                    @else
                        <x-ui.button class="w-full" wire:click="openPay">Submit a payment</x-ui.button>
                    @endif
                @endif
                <x-ui.button variant="secondary" class="w-full" wire:click="openSupport"><x-lm.icon name="mail" class="h-4 w-4" /> Contact support</x-ui.button>
                @if ($support) <p class="text-xs text-gray-500">Or reach us: {{ $support }}</p> @endif
            </section>
        </div>

        @foreach ($this->payments->where('status', 'info_requested') as $p)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm" wire:key="ask-{{ $p->id }}">
                <p class="font-semibold text-amber-900">We need more information about payment {{ $p->reference }}</p>
                <p class="mt-1 whitespace-pre-line text-amber-900">{{ $p->review_note }}</p>
                @if ($this->can('billing.manage')) <x-ui.button class="mt-3" wire:click="startReply({{ $p->id }})">Reply</x-ui.button> @endif
            </div>
        @endforeach

        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-3 text-sm font-semibold text-gray-900">Payment history</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500"><th class="pb-2 font-medium">Submitted</th><th class="pb-2 font-medium">Reference</th><th class="pb-2 text-right font-medium">Amount</th><th class="pb-2 pl-4 font-medium">Status</th><th class="pb-2 font-medium">Covers</th><th class="pb-2 font-medium">Notes</th><th class="pb-2 font-medium">Files</th></tr></thead>
                    <tbody>
                        @forelse ($this->payments as $p)
                            <tr class="border-t border-gray-100 align-top" wire:key="hp-{{ $p->id }}">
                                <td class="py-2">{{ $p->created_at->format('d M Y') }}</td><td class="py-2 font-medium">{{ $p->reference }}</td>
                                <td class="py-2 text-right">₹{{ number_format((float) $p->amount, 2) }}</td>
                                <td class="py-2 pl-4"><x-ui.badge :color="$payColor($p->status)">{{ \App\Models\SubscriptionPayment::STATUSES[$p->status] }}</x-ui.badge></td>
                                <td class="py-2 text-gray-600">{{ $p->period_starts_on ? $p->period_starts_on->format('d M Y').' – '.$p->period_ends_on->format('d M Y') : '—' }}</td>
                                <td class="max-w-xs py-2 text-xs text-gray-600">@if ($p->status === 'rejected')<span class="text-red-700">Rejected: {{ $p->review_note }}</span>@else{{ $p->review_note }}@endif</td>
                                <td class="space-x-2 py-2 text-xs">
                                    @foreach ($p->proofs as $proof)<a href="{{ route('app.subscription.proof', ['library' => $slug, 'proof' => $proof->id]) }}" target="_blank" class="text-brand-600 hover:text-brand-800">Proof</a>@endforeach
                                    @if ($p->invoice)<a href="{{ route('app.subscription.invoice', ['library' => $slug, 'invoice' => $p->invoice->id]) }}" target="_blank" class="font-medium text-brand-600 hover:text-brand-800">{{ $p->invoice->invoice_no }}</a>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-6 text-center text-gray-400">No payments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($this->supportRequests->isNotEmpty())
        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-3 text-sm font-semibold text-gray-900">Your support requests</h3>
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach ($this->supportRequests as $sr)
                    <li class="py-3" wire:key="sr-{{ $sr->id }}">
                        <div class="flex items-center justify-between"><span class="font-medium text-gray-900">{{ $sr->subject }}</span><x-ui.badge :color="$sr->status === 'open' ? 'amber' : 'green'">{{ ucfirst($sr->status) }}</x-ui.badge></div>
                        <p class="mt-1 whitespace-pre-line text-xs text-gray-600">{{ $sr->message }}</p>
                        @if ($sr->response) <p class="mt-2 whitespace-pre-line rounded-lg bg-brand-50/70 p-2 text-xs text-gray-800"><span class="font-medium">{{ $sr->responder?->name ?? 'Support' }}:</span> {{ $sr->response }}</p> @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($showSupport)
        <x-ui.modal title="Contact support" max="max-w-lg" wire:key="support-modal">
            <form wire:submit="sendSupport" class="space-y-4">
                <x-ui.input name="subject" label="Subject" required wire:model="subject" />
                <x-ui.textarea name="supportMessage" label="How can we help?" required rows="5" wire:model="supportMessage" />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Send</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    @if ($showPay)
        <x-ui.modal title="Submit subscription payment" max="max-w-xl" wire:key="sp-modal">
            <form wire:submit="savePay" class="space-y-4">
                <div class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 whitespace-pre-line">{{ \App\Support\BillingSettings::get('payment_instructions') ?: 'Pay using the details shared by support, then fill in this form.' }}</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select name="planId" label="Plan" wire:model.live="planId">@foreach ($this->plans as $p) <option value="{{ $p->id }}">{{ $p->name }}</option> @endforeach</x-ui.select>
                    <x-ui.select name="cycle" label="Billing cycle" wire:model.live="cycle">
                        @foreach (($this->plans->firstWhere('id', (int) $planId)?->cycles() ?? []) as $c) <option value="{{ $c }}">{{ \App\Models\SubscriptionPlan::CYCLES[$c] }}</option> @endforeach
                    </x-ui.select>
                    <x-ui.input name="amount" label="Amount paid (₹)" type="number" step="0.01" min="0" required wire:model="amount"                     :hint="\App\Support\BillingSettings::get('require_exact_amount') ? 'Must match the total below.' : null" />
                    @php
                        $quotePlan = $this->plans->firstWhere('id', (int) $planId) ?? $this->sub?->plan;
                        $quote = $quotePlan && $quotePlan->priceFor($cycle) !== null ? \App\Support\Pricing::quote(\App\Support\Tenancy::currentId(), $quotePlan, $cycle) : null;
                    @endphp
                    @if ($quote && count($quote['lines']) > 1)
                        <ul class="rounded-lg bg-gray-50 p-3 text-xs text-gray-700">
                            @foreach ($quote['lines'] as $l) <li class="flex justify-between"><span>{{ $l['label'] }}</span><span>₹{{ number_format((float) $l['amount'], 2) }}</span></li> @endforeach
                            <li class="mt-1 flex justify-between border-t border-gray-200 pt-1 font-semibold"><span>Total</span><span>₹{{ number_format((float) $quote['total'], 2) }}</span></li>
                        </ul>
                    @endif
                    <x-ui.select name="method" label="Method" wire:model="method">@foreach ((array) \App\Support\BillingSettings::get('payment_methods') as $m) <option value="{{ $m }}">{{ \App\Models\SubscriptionPayment::METHODS[$m] ?? $m }}</option> @endforeach</x-ui.select>
                    <x-ui.input name="reference" label="Transaction / UTR reference" required wire:model="reference" />
                    <x-ui.input name="paidOn" label="Payment date" type="date" required wire:model="paidOn" />
                </div>
                <x-ui.textarea name="note" label="Note (optional)" wire:model="note" />
                <div>
                    <label class="block text-sm font-medium text-gray-700">Proof of payment @if (\App\Support\BillingSettings::get('proof_required'))<span class="text-red-500">*</span>@endif</label>
                    <input type="file" multiple wire:model="proofs" accept=".jpg,.jpeg,.png,.pdf" class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700">
                    <p class="mt-1 text-xs text-gray-500">JPG, PNG or PDF, up to {{ round(\App\Support\BillingSettings::get('upload_limit_kb') / 1024, 1) }} MB each, max 5 files.</p>
                    @error('proofs') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    @error('proofs.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit" wire:target="savePay,proofs">Submit payment</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    @if ($replyTo)
        <x-ui.modal title="Reply to our question" max="max-w-lg" wire:key="reply-modal">
            <form wire:submit="sendReply" class="space-y-4">
                <x-ui.textarea name="reply" label="Your reply" wire:model="reply" />
                <div>
                    <label class="block text-sm font-medium text-gray-700">Attach more proof (optional)</label>
                    <input type="file" multiple wire:model="replyFiles" accept=".jpg,.jpeg,.png,.pdf" class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700">
                    @error('replyFiles.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit" wire:target="sendReply,replyFiles">Send reply</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
