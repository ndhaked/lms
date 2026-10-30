<?php

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    use \App\Livewire\Concerns\AuthorizesActions;

    public function mount(): void
    {
        $this->authorizePermission('billing.view');
        $this->tab = in_array(request('tab'), ['library', 'sms', 'whatsapp'], true) ? request('tab') : 'library';
    }

    #[Computed]
    public function sub(): ?Subscription
    {
        return Subscription::where('library_id', Tenancy::currentId())->latest('id')->first();
    }

    #[Computed]
    public function plans()
    {
        return SubscriptionPlan::where('status', 'active')->orderBy('price_monthly')->get();
    }

    #[Computed]
    public function open(): ?SubscriptionPayment
    {
        return SubscriptionPayment::where('library_id', Tenancy::currentId())->get()->first(fn ($p) => $p->isOpen());
    }

    /** The channel the buy form is working on; also the tab opened first. */
    public string $tab = 'library';


    public function ratesFor(string $ch): array
    {
        return (array) (\App\Support\BillingSettings::get('credit_rates')[$ch] ?? \App\Support\BillingSettings::DEFAULTS['credit_rates']['sms']);
    }

    public function balanceFor(string $ch): int
    {
        return (int) (\App\Models\Library::find(Tenancy::currentId())?->{$ch.'_credits'} ?? 0);
    }

    public function purchasesFor(string $ch)
    {
        return \App\Models\CreditPurchase::with('proofs')->where('library_id', Tenancy::currentId())->where('channel', $ch)->latest('id')->limit(10)->get();
    }

    public function openFor(string $ch): bool
    {
        return \App\Models\CreditPurchase::where('library_id', Tenancy::currentId())->where('channel', $ch)->where('status', 'pending')->exists();
    }

    /** Rate card for the open SMS / WhatsApp tab. */
    #[Computed]
    public function rates(): array
    {
        return (array) (\App\Support\BillingSettings::get('credit_rates')[$this->tab] ?? \App\Support\BillingSettings::DEFAULTS['credit_rates']['sms']);
    }

    #[Computed]
    public function balance(): int
    {
        return (int) (\App\Models\Library::find(Tenancy::currentId())?->{$this->tab.'_credits'} ?? 0);
    }

    #[Computed]
    public function purchases()
    {
        return \App\Models\CreditPurchase::where('library_id', Tenancy::currentId())->where('channel', $this->tab)->latest('id')->limit(10)->get();
    }

    #[Computed]
    public function open_credit(): bool
    {
        return \App\Models\CreditPurchase::where('library_id', Tenancy::currentId())->where('channel', $this->tab)->where('status', 'pending')->exists();
    }

    public function buy(int $credits, string $channel)
    {
        $this->authorizePermission('billing.manage');
        abort_unless(in_array($channel, ['sms', 'whatsapp'], true) && in_array($credits, (array) $this->ratesFor($channel)['packs'], true), 404);

        return $this->redirectRoute('app.subscription.credits-checkout', ['library' => Tenancy::slug(), 'channel' => $channel, 'credits' => $credits]);
    }
    public function choose(int $planId, string $cycle)
    {
        $this->authorizePermission('billing.manage');
        $plan = $this->plans->firstWhere('id', $planId);
        abort_unless($plan && in_array($cycle, $plan->cycles(), true), 404);

        return $this->redirectRoute('app.subscription.checkout', ['library' => Tenancy::slug(), 'plan' => $plan->id, 'cycle' => $cycle], navigate: false);
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Plans and pricing" subtitle="Choose a plan and billing period">
        <a href="{{ route('app.subscription.index', ['library' => \App\Support\Tenancy::slug()]) }}" class="text-sm font-medium text-brand-600 hover:text-brand-800">← My subscription</a>
    </x-ui.page-title></x-slot:header>

    @php
        $s = $this->sub;
        $canPay = $this->can('billing.manage') && $s && $s->status !== 'cancelled';
    @endphp

    @if (! $this->can('billing.manage'))
        <div class="rounded-xl border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">Only people who can manage billing can choose a plan.</div>
    @else
            @php
                $months = ['monthly' => 1, 'quarterly' => 3, 'annual' => 12];
                $duration = ['monthly' => '1 Month', 'quarterly' => '3 Months', 'annual' => '1 Year'];
                $tones = ['monthly' => 'from-blue-600 to-blue-400', 'quarterly' => 'from-emerald-700 to-emerald-500', 'annual' => 'from-violet-600 to-purple-400'];
                $modLabels = \App\Models\SubscriptionPlan::MODULES;
                $cards = collect();
                foreach (array_keys($months) as $c) { foreach ($this->plans as $p) { if ($p->priceFor($c) !== null) { $cards->push([$p, $c]); } } }
                $maxSave = $cards->map(fn ($x) => $x[1] === 'monthly' ? 0 : round((float) $x[0]->priceFor('monthly') * $months[$x[1]] - (float) $x[0]->priceFor($x[1])))->max();
            @endphp
            <section class="space-y-4" id="plans">
                <div class="rounded-2xl bg-gradient-to-r from-slate-900 via-teal-900 to-slate-800 p-6 text-white shadow">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/15 px-3 py-1 text-[11px] font-semibold uppercase tracking-wider"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><path d="M6 3h12l4 6-10 12L2 9l4-6z"/><path d="M2 9h20M9 3l3 6 3-6M12 21L9 9m3 12l3-12"/></svg>Subscription</span>
                    <h3 class="mt-3 text-2xl font-extrabold sm:text-3xl">Upgrade your {{ config('app.name', 'LMS') }} workspace</h3>
                    <p class="mt-1 text-sm text-white/80">Choose a plan and billing period, then submit your payment. Longer plans cost less per month.</p>
                </div>
                @if ($maxSave > 0)
                    <div class="flex items-center gap-3 rounded-2xl border border-dashed border-violet-300 bg-violet-50/50 p-4">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-100 text-lg">🎁</span>
                        <div><p class="text-sm font-bold text-gray-900">Get best discounts and deals of the day</p><p class="text-xs font-medium text-gray-600">Hurry. Save up to ₹{{ number_format($maxSave) }} on long duration plans.</p></div>
                    </div>
                @endif

                <div x-data="{ tab: '{{ $tab }}' }" class="space-y-4">
                <div class="flex w-full max-w-2xl rounded-2xl border border-gray-200 bg-white p-1 shadow-sm">
                    @foreach (['library' => 'Library', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'] as $k => $l)
                        <button type="button" x-on:click="tab = '{{ $k }}'" x-bind:class="tab === '{{ $k }}' ? 'bg-gradient-to-r from-violet-600 to-blue-600 text-white shadow' : 'text-gray-600 hover:text-gray-900'" class="flex-1 rounded-xl px-8 py-3 text-sm font-bold">{{ $l }}</button>
                    @endforeach
                </div>
                <div x-show="tab === 'library'" class="space-y-4">
                @if (! $s)
                    <div class="rounded-xl border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">This library has no plan yet. Ask the platform team to assign one from the My subscription page.</div>
                @else
                <div x-data="{ go(d) { $refs.track.scrollBy({ left: d * ($refs.track.firstElementChild?.offsetWidth ?? 280), behavior: 'smooth' }) } }" class="relative">
                    <button type="button" x-on:click="go(-1)" aria-label="Previous" class="absolute -left-2 top-1/2 z-10 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg hover:bg-indigo-700">‹</button>
                    <button type="button" x-on:click="go(1)" aria-label="Next" class="absolute -right-2 top-1/2 z-10 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg hover:bg-indigo-700">›</button>
                    <div x-ref="track" class="flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-smooth px-1 pb-4 pt-1" style="scrollbar-width: none">
                        @foreach ($cards as [$p, $c])
                            @php
                                $price = (float) $p->priceFor($c);
                                $full = (float) $p->priceFor('monthly') * $months[$c];
                                $save = $c === 'monthly' ? 0 : round($full - $price);
                                $current = $s->subscription_plan_id === $p->id && $s->billing_cycle === $c;
                                $feat = [$p->max_students ? 'Up to '.number_format($p->max_students).' students' : 'Unlimited students', $p->max_seats ? 'Up to '.number_format($p->max_seats).' seats' : 'Unlimited seats', $p->max_staff ? $p->max_staff.' staff accounts' : 'Unlimited staff accounts', 'Permission wise access'];
                                foreach ($modLabels as $k => $l) { if (in_array($k, (array) $p->modules, true)) { $feat[] = $l; } }
                                $feat[] = 'Free support';
                            @endphp
                            <div class="flex w-72 shrink-0 snap-start flex-col overflow-hidden rounded-2xl border {{ $current ? 'border-brand-500 ring-2 ring-brand-200' : 'border-gray-200' }} bg-white shadow-md" wire:key="plan-{{ $p->id }}-{{ $c }}">
                                <div class="relative overflow-hidden bg-gradient-to-br {{ $tones[$c] }} p-5 text-white">
                                    <span class="pointer-events-none absolute -bottom-10 -right-8 h-32 w-32 rounded-full bg-white/15"></span>
                                    <div class="flex items-start justify-between"><p class="text-sm font-extrabold uppercase tracking-wider">{{ $p->name }}</p>
                                        @if ($current)<span class="rounded-full bg-white/25 px-2 py-0.5 text-[11px] font-semibold">Current</span>@elseif ($c === 'quarterly')<span class="rounded-full bg-white/25 px-2 py-0.5 text-[11px] font-semibold">Most used</span>@elseif ($c === 'annual')<span class="rounded-full bg-white/25 px-2 py-0.5 text-[11px] font-semibold">Best value</span>@endif</div>
                                    <p class="relative mt-2 flex items-baseline gap-2"><span class="text-3xl font-extrabold">₹{{ number_format($price) }}</span>@if ($save > 0)<span class="text-xs text-white/70 line-through">₹{{ number_format($full) }}</span>@endif</p>
                                    <p class="relative text-sm font-semibold">{{ $duration[$c] }}</p>
                                    <span class="relative mt-2 inline-block rounded-full bg-black/20 px-2.5 py-0.5 text-[11px] font-semibold">₹{{ number_format($price / $months[$c], 2) }} / month</span>
                                </div>
                                <ul class="flex-1 space-y-2.5 p-5 text-[13px] font-semibold text-gray-800">
                                    @foreach ($feat as $f)<li class="flex items-center gap-2"><span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-[9px] text-white">✓</span>{{ $f }}</li>@endforeach
                                </ul>
                                <div class="space-y-3 px-5 pb-5">
                                    <div class="h-6">@if ($save > 0)<span class="rounded-full bg-brand-50 px-3 py-1 text-[11px] font-bold text-brand-700">You save ₹{{ number_format($save) }}</span>@endif</div>
                                    @if ($this->open)
                                        <button type="button" disabled class="w-full cursor-not-allowed rounded-xl bg-gray-200 py-3 text-sm font-bold text-gray-500">Payment under review</button>
                                    @else
                                        <button type="button" wire:click="choose({{ $p->id }}, '{{ $c }}')" class="w-full rounded-xl bg-gradient-to-r {{ $tones[$c] }} py-3 text-sm font-bold text-white shadow hover:opacity-90">{{ $current ? 'Renew plan' : 'Choose Plan' }}</button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif
                </div>
                @foreach (['sms', 'whatsapp'] as $ch)
                <div x-show="tab === '{{ $ch }}'" x-cloak class="space-y-4" wire:key="credit-{{ $ch }}">
                    @php
                        $label = \App\Models\CreditPurchase::CHANNELS[$ch];
                        $r = $this->ratesFor($ch);
                        $support = collect([\App\Support\BillingSettings::get('support_phone'), \App\Support\BillingSettings::get('support_email')])->filter();
                    @endphp
                    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                        <div class="grid md:grid-cols-[260px_1fr]">
                            <div class="flex flex-col items-center justify-center gap-2 bg-gradient-to-br {{ $ch === 'sms' ? 'from-orange-600 to-red-500' : 'from-emerald-600 to-teal-500' }} p-6 text-center text-white">
                                <p class="text-base font-extrabold">{{ $label }} Subscription</p>
                                @if ((float) $r['mrp'] > (float) $r['rate'])<p class="text-sm text-white/70 line-through">₹{{ $r['mrp'] }}/{{ $label }}</p>@endif
                                <p class="text-3xl font-extrabold">₹{{ $r['rate'] }}/{{ $label }}</p>
                                <p class="text-xs font-semibold text-white/90">Minimum credits to purchase:<br>{{ number_format($r['min']) }}</p>
                                <p class="mt-2 rounded-full bg-black/20 px-3 py-1 text-xs font-semibold">Your balance: {{ number_format($this->balanceFor($ch)) }} credits</p>
                            </div>
                            <div class="p-5">
                                <h4 class="text-lg font-extrabold text-gray-900">How many {{ $label }} credits would you like to purchase?</h4>
                                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    @foreach ($r['packs'] as $n)
                                        <div class="rounded-xl border border-gray-200 p-4" wire:key="pack-{{ $ch }}-{{ $n }}">
                                            <p class="text-xl font-extrabold text-violet-700">{{ $n >= 1000 ? ($n / 1000).'k' : $n }}</p>
                                            <p class="text-xs font-semibold text-gray-500">{{ number_format($n) }} credits</p>
                                            <p class="mt-2 text-base font-extrabold text-gray-900">₹{{ rtrim(rtrim(number_format($n * (float) $r['rate'], 2, '.', ''), '0'), '.') }}</p>
                                            @if ($this->openFor($ch))
                                                <button type="button" disabled class="mt-2 w-full cursor-not-allowed rounded-lg bg-gray-200 py-2 text-xs font-bold text-gray-500">Payment under review</button>
                                            @else
                                                <button type="button" wire:click="buy({{ $n }}, '{{ $ch }}')" class="mt-2 w-full rounded-lg bg-gradient-to-r from-violet-600 to-blue-600 py-2 text-sm font-bold text-white shadow hover:opacity-90">Pay ₹{{ rtrim(rtrim(number_format($n * (float) $r['rate'], 2, '.', ''), '0'), '.') }}</button>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                <p class="mt-4 flex items-center gap-2 text-sm font-semibold text-gray-700"><span class="flex h-4 w-4 items-center justify-center rounded-full bg-emerald-500 text-[9px] text-white">✓</span>Credits do not have a separate validity period; use them while your plan remains active.</p>
                            </div>
                        </div>
                    </div>

                    @php $mine = $this->purchasesFor($ch); @endphp
                    @if ($mine->isNotEmpty())
                        <div class="rounded-2xl border border-gray-200 bg-white p-5">
                            <h4 class="mb-3 text-sm font-semibold text-gray-900">Your {{ $label }} purchases</h4>
                            <ul class="divide-y divide-gray-100 text-sm">
                                @foreach ($mine as $cp)
                                    <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="cp-{{ $cp->id }}">
                                        <span>{{ $cp->created_at->format('d M Y') }} · {{ number_format($cp->credits) }} credits · ₹{{ number_format((float) $cp->amount, 2) }} · {{ $cp->reference }} @foreach ($cp->proofs as $pf)<a href="{{ route('app.subscription.credit-proof', ['library' => $lib->slug ?? \App\Support\Tenancy::slug(), 'proof' => $pf->id]) }}" target="_blank" class="ml-1 text-xs font-medium text-brand-600 hover:text-brand-800">Proof</a>@endforeach</span>
                                        <span class="flex items-center gap-2"><x-ui.badge :color="['pending' => 'blue', 'approved' => 'green', 'rejected' => 'red'][$cp->status]">{{ \App\Models\CreditPurchase::STATUSES[$cp->status] }}</x-ui.badge>@if ($cp->status === 'rejected' && $cp->review_note)<span class="text-xs text-red-700">{{ $cp->review_note }}</span>@endif</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
                @endforeach
            @php
                $sp = (string) \App\Support\BillingSettings::get('support_phone');
                $se = (string) \App\Support\BillingSettings::get('support_email');
                $wa = preg_replace('/\D+/', '', $sp);
            @endphp
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="mb-3 text-xs text-gray-500">For any queries, contact our support team.</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    @if ($sp !== '')<a href="tel:{{ $sp }}" class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-center transition hover:border-brand-300 hover:bg-white"><span class="mx-auto flex h-9 w-9 items-center justify-center rounded-full bg-violet-50 text-violet-600"><x-lm.icon name="tablet" class="h-4 w-4" /></span><p class="mt-2 text-sm font-bold text-gray-900">{{ $sp }}</p><p class="text-xs text-gray-500">Sales support</p></a>@endif
                    @if ($wa !== '')<a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-center transition hover:border-brand-300 hover:bg-white"><span class="mx-auto flex h-9 w-9 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><x-lm.icon name="chat" class="h-4 w-4" /></span><p class="mt-2 text-sm font-bold text-gray-900">{{ $sp }}</p><p class="text-xs text-gray-500">WhatsApp / technical support</p></a>@endif
                    @if ($se !== '')<a href="mailto:{{ $se }}" class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-center transition hover:border-brand-300 hover:bg-white"><span class="mx-auto flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 text-blue-600"><x-lm.icon name="mail" class="h-4 w-4" /></span><p class="mt-2 break-all text-sm font-bold text-gray-900">{{ $se }}</p><p class="text-xs text-gray-500">For more info</p></a>@endif
                </div>
            </div>
                </div>
            </section>

        @endif

</div>
