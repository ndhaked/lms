<?php

use App\Models\Library;
use App\Models\ReferralCoin;
use App\Support\BillingSettings;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->can('billing.view'), 403);
    }

    #[Computed]
    public function library(): Library
    {
        return Tenancy::library();
    }

    #[Computed]
    public function balance(): int
    {
        return ReferralCoin::balance($this->library->id);
    }

    #[Computed]
    public function referrals()
    {
        return Library::where('referred_by_id', $this->library->id)->with('subscriptions')->latest('id')->get();
    }

    #[Computed]
    public function history()
    {
        return ReferralCoin::where('library_id', $this->library->id)->latest('id')->limit(15)->get();
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Refer & Earn" subtitle="Share with other library owners and earn subscription discounts" /></x-slot:header>

    @php
        $lib = $this->library;
        $link = url('/').'?ref='.$lib->code;
        $enabled = (bool) BillingSettings::get('referral_enabled');
        $reward = (int) BillingSettings::get('referral_reward_coins');
        $value = ReferralCoin::coinValue();
        $pct = (int) BillingSettings::get('referral_redeem_percent');
        $hour = now($lib->timezone)->hour;
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $appName = BillingSettings::get('app_name') ?: config('app.name');
        $message = "I run my library on {$appName} and it saves me hours every week. Try it with my link: {$link}";
    @endphp

    @if (! $enabled)
        <div class="rounded-xl border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">The referral program is not running right now.</div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div>
                <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-brand-700"><x-lm.icon name="ticket" class="h-4 w-4" /> Referral program</p>
                <h2 class="mt-1 text-3xl font-extrabold text-gray-900">Refer &amp; Earn</h2>
                <p class="mt-2 text-sm text-gray-600">Share {{ $appName }} with other library owners and use earned coins for subscription discounts.</p>
            </div>
            <a href="#share" class="inline-flex items-center gap-2 rounded-xl bg-brand-700 px-5 py-3 text-sm font-bold text-white shadow hover:bg-brand-800">✦ Earn Coins</a>
        </div>

        <div class="grid gap-5 lg:grid-cols-[1fr_1.4fr]">
            <div class="space-y-5">
                <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h3 class="text-2xl font-extrabold text-gray-900">{{ $greeting }} {{ $lib->name }}</h3>
                    <p class="mt-4 text-gray-700">You have <span class="text-2xl font-extrabold text-gray-900">{{ number_format($this->balance) }} Coins ({{ '₹'.rtrim(rtrim(number_format($this->balance * $value, 2), '0'), '.') }})</span></p>
                    <p class="mt-3 text-sm text-gray-500">You can redeem up to {{ $pct }}% of the plan price while subscribing, and get a discount.</p>
                    <a href="{{ route('app.subscription.plans', ['library' => $lib->slug]) }}" wire:navigate class="mt-4 inline-block text-sm font-semibold text-brand-700 hover:text-brand-800">Use coins on a plan →</a>
                </section>

                <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h3 class="text-sm font-semibold text-gray-900">How it works</h3>
                    <ol class="mt-3 space-y-3 text-sm text-gray-700">
                        <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">1</span><span>Share your link with another library owner.</span></li>
                        <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">2</span><span>They ask for the free months on the website through your link.</span></li>
                        <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">3</span><span>When their first subscription payment is approved, you earn <strong>{{ $reward }} coins</strong> (worth ₹{{ rtrim(rtrim(number_format($reward * $value, 2), '0'), '.') }}).</span></li>
                    </ol>
                </section>
            </div>

            <section id="share" class="rounded-2xl border border-gray-200 bg-white p-6 text-center shadow-sm" x-data="{ copied: false, link: @js($link), text: @js($message),
                copy() { navigator.clipboard?.writeText(this.link); this.copied = true; setTimeout(() => this.copied = false, 1800) },
                share() { if (navigator.share) { navigator.share({ title: 'Try {{ $appName }}', text: this.text, url: this.link }).catch(() => {}) } else { this.copy() } } }">
                <div class="mx-auto flex h-44 max-w-xs items-center justify-center rounded-2xl bg-gradient-to-br from-brand-100 via-white to-amber-100 text-6xl">🎁</div>
                <p class="mt-2 text-2xl font-extrabold text-gray-900">#ReferNEarn</p>
                <p class="mx-auto mt-3 max-w-xl text-sm text-gray-600">Welcome to the {{ $appName }} referral program. Share your link and grab coins that give you extra discounts when you purchase a plan.</p>

                <div class="mx-auto mt-5 flex max-w-xl overflow-hidden rounded-xl border-2 border-brand-200">
                    <input type="text" readonly x-bind:value="link" class="min-w-0 flex-1 border-0 bg-white px-4 py-3 text-sm text-gray-700 focus:ring-0" x-on:click="$el.select()">
                    <button type="button" x-on:click="share()" class="bg-brand-700 px-6 text-sm font-bold text-white hover:bg-brand-800">Share</button>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-center gap-3 text-sm">
                    <button type="button" x-on:click="copy()" class="rounded-lg border border-gray-200 px-4 py-2 font-medium text-gray-700 hover:bg-gray-50"><span x-show="! copied">Copy link</span><span x-show="copied" x-cloak>Copied ✓</span></button>
                    <a x-bind:href="'https://wa.me/?text=' + encodeURIComponent(text)" target="_blank" rel="noopener" class="rounded-lg bg-emerald-600 px-4 py-2 font-medium text-white hover:bg-emerald-700">Share on WhatsApp</a>
                </div>
                <p class="mt-3 text-xs text-gray-500">Your referral code: <strong class="text-gray-800">{{ $lib->code }}</strong></p>
            </section>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="mb-3 text-sm font-semibold text-gray-900">Your referrals</h3>
                <ul class="divide-y divide-gray-100 text-sm">
                    @forelse ($this->referrals as $ref)
                        @php $paid = \App\Models\ReferralCoin::where('library_id', $lib->id)->where('referred_library_id', $ref->id)->where('kind', 'earned')->exists(); @endphp
                        <li class="flex items-center justify-between py-2.5" wire:key="ref-{{ $ref->id }}"><span><span class="font-medium text-gray-900">{{ $ref->name }}</span><span class="block text-xs text-gray-500">Joined {{ $ref->created_at->format('d M Y') }}</span></span>
                            <x-ui.badge :color="$paid ? 'green' : 'blue'">{{ $paid ? '+'.$reward.' coins earned' : 'Signed up' }}</x-ui.badge></li>
                    @empty
                        <li class="py-6 text-center text-gray-400">No referrals yet. Share your link to get started.</li>
                    @endforelse
                </ul>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="mb-3 text-sm font-semibold text-gray-900">Coin history</h3>
                <ul class="divide-y divide-gray-100 text-sm">
                    @forelse ($this->history as $c)
                        <li class="flex items-center justify-between py-2.5" wire:key="coin-{{ $c->id }}"><span><span class="font-medium text-gray-900">{{ $c->description }}</span><span class="block text-xs text-gray-500">{{ $c->created_at->format('d M Y') }}</span></span>
                            <span class="font-bold {{ $c->coins > 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $c->coins > 0 ? '+' : '' }}{{ $c->coins }}</span></li>
                    @empty
                        <li class="py-6 text-center text-gray-400">No coins yet.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif
</div>
