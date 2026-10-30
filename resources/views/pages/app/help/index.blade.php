<?php

use App\Models\SupportRequest;
use App\Support\HelpContent;
use App\Support\Notifier;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    public string $subject = '';
    public string $body = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('billing.view'), 403);
    }

    #[Computed]
    public function requests()
    {
        return SupportRequest::with('responder')->where('library_id', Tenancy::currentId())->latest('id')->limit(15)->get();
    }

    public function send(): void
    {
        $d = $this->validate(['subject' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'max:3000']], [], ['body' => 'message']);

        $req = SupportRequest::create(['library_id' => Tenancy::currentId(), 'user_id' => auth()->id(), 'subject' => $d['subject'], 'message' => $d['body']]);
        Notifier::platform('libraries.manage', 'support.request', 'Support request: '.$req->subject, Tenancy::library()->name, route('admin.support.index'));

        $this->reset(['subject', 'body']);
        unset($this->requests);
        $this->dispatch('notify', message: 'Sent. We will reply here and in your notifications.');
    }
}
?>

<div class="space-y-5" x-data="{ lang: 'en', open: null }">
    <x-slot:header><x-ui.page-title title="Need help?" subtitle="Quick answers and a way to reach us" /></x-slot:header>

    @php
        $faqs = \App\Support\HelpContent::faqs();
        $videos = \App\Support\HelpContent::videos();
        $full = (string) \App\Support\BillingSettings::get('help_full_video');
        $appName = \App\Support\BillingSettings::get('app_name') ?: config('app.name');
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <div>
            <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-brand-700"><x-lm.icon name="chat" class="h-4 w-4" /> Support center</p>
            <h2 class="mt-1 text-3xl font-extrabold text-gray-900">Need Help?</h2>
            <p class="mt-1 text-sm text-gray-500" x-text="lang === 'en' ? 'Find quick answers for common {{ $appName }} workflows.' : '{{ $appName }} के आम कामों के जवाब यहाँ पाएँ।'"></p>
        </div>
        <div class="inline-flex rounded-xl border border-gray-200 bg-gray-50 p-1">
            <button type="button" x-on:click="lang = 'en'" x-bind:class="lang === 'en' ? 'bg-brand-700 text-white shadow' : 'text-gray-600'" class="rounded-lg px-8 py-2 text-sm font-bold">English</button>
            <button type="button" x-on:click="lang = 'hi'" x-bind:class="lang === 'hi' ? 'bg-brand-700 text-white shadow' : 'text-gray-600'" class="rounded-lg px-8 py-2 text-sm font-bold">हिन्दी</button>
        </div>
    </div>

    <div class="grid items-start gap-3 lg:grid-cols-2">
        @foreach ($faqs as $i => $f)
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm" wire:key="faq-{{ $f['key'] }}">
                <button type="button" x-on:click="open = open === {{ $i }} ? null : {{ $i }}" class="flex w-full items-center gap-3 p-4 text-left">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700"><x-lm.icon :name="$f['icon']" class="h-5 w-5" /></span>
                    <span class="flex-1 text-sm font-bold text-gray-900"><span x-show="lang === 'en'">{{ $f['en'][0] }}</span><span x-show="lang === 'hi'" x-cloak>{{ $f['hi'][0] }}</span></span>
                    <x-lm.icon name="chevron-down" class="h-5 w-5 shrink-0 text-gray-400 transition" x-bind:class="open === {{ $i }} ? 'rotate-180' : ''" />
                </button>
                <div x-show="open === {{ $i }}" x-cloak x-collapse class="px-4 pb-4">
                    <div class="rounded-xl bg-gray-50 p-3 text-sm text-gray-700">
                        <p x-show="lang === 'en'">{{ $f['en'][1] }}</p>
                        <p x-show="lang === 'hi'" x-cloak>{{ $f['hi'][1] }}</p>
                        @if (isset($videos[$f['key']]))
                            <a href="{{ $videos[$f['key']] }}" target="_blank" rel="noopener" class="mt-3 flex items-center justify-center gap-2 rounded-lg border border-brand-200 bg-brand-50 py-2 text-sm font-bold text-brand-700 hover:bg-brand-100">▶ <span x-text="lang === 'en' ? 'Watch video' : 'वीडियो देखें'"></span></a>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach

        <div class="rounded-2xl border border-gray-200 bg-white p-6 text-center shadow-sm">
            <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-brand-100 text-brand-700">♥</span>
            <p class="mt-3 text-lg font-extrabold text-gray-900" x-text="lang === 'en' ? 'Thank you' : 'धन्यवाद'"></p>
            <p class="text-sm text-gray-500" x-text="lang === 'en' ? 'We hope this helps!' : 'हमें आशा है कि यह मददगार रहा!'"></p>
            @if (str_starts_with($full, 'https://'))
                <a href="{{ $full }}" target="_blank" rel="noopener" class="mt-4 flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-gray-50 py-3 text-sm font-bold text-brand-700 hover:bg-brand-50">▶ <span x-text="lang === 'en' ? 'Watch full video' : 'पूरा वीडियो देखें'"></span></a>
            @endif
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[1.2fr_1fr]">
        <form wire:submit="send" class="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div><h3 class="text-base font-semibold text-gray-900">Still need help? Send us a message</h3><p class="text-xs text-gray-500">We reply within a day. You will see the answer below and in your notifications.</p></div>
            <x-ui.input name="subject" label="Subject" required wire:model="subject" />
            <x-ui.textarea name="body" label="How can we help?" required rows="5" wire:model="body" />
            <div class="flex justify-end"><x-ui.button type="submit" wire:target="send">Send</x-ui.button></div>
        </form>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <h3 class="mb-3 text-base font-semibold text-gray-900">Your requests</h3>
            <ul class="divide-y divide-gray-100 text-sm">
                @forelse ($this->requests as $sr)
                    <li class="py-3" wire:key="sr-{{ $sr->id }}">
                        <div class="flex items-center justify-between gap-2"><span class="font-medium text-gray-900">{{ $sr->subject }}</span><x-ui.badge :color="$sr->status === 'open' ? 'amber' : 'green'">{{ ucfirst($sr->status) }}</x-ui.badge></div>
                        <p class="mt-1 whitespace-pre-line text-xs text-gray-600">{{ $sr->message }}</p>
                        @if ($sr->response)<p class="mt-2 whitespace-pre-line rounded-lg bg-brand-50/70 p-2 text-xs text-gray-800"><span class="font-medium">{{ $sr->responder?->name ?? 'Support' }}:</span> {{ $sr->response }}</p>@endif
                    </li>
                @empty
                    <li class="py-6 text-center text-gray-400">You have not sent any requests yet.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
