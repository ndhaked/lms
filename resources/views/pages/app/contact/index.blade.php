<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Contact us" subtitle="Talk to the team directly" /></x-slot:header>

    @php
        $phone = (string) \App\Support\BillingSettings::get('support_phone');
        $email = (string) \App\Support\BillingSettings::get('support_email');
        $appName = \App\Support\BillingSettings::get('app_name') ?: config('app.name');
        $wa = \App\Http\Controllers\HomeController::whatsappLink($phone, (string) $appName);
    @endphp

    <div class="grid gap-4 sm:grid-cols-3">
        @if ($phone !== '')
            <a href="tel:{{ $phone }}" class="rounded-2xl border border-gray-200 bg-white p-6 text-center shadow-sm hover:border-brand-300">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-violet-50 text-violet-600"><x-lm.icon name="tablet" class="h-6 w-6" /></span>
                <p class="mt-3 text-base font-bold text-gray-900">{{ $phone }}</p><p class="text-sm text-gray-500">Call sales and support</p>
            </a>
        @endif
        @if ($wa)
            <a href="{{ $wa }}" target="_blank" rel="noopener" class="rounded-2xl border border-gray-200 bg-white p-6 text-center shadow-sm hover:border-brand-300">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><x-lm.icon name="chat" class="h-6 w-6" /></span>
                <p class="mt-3 text-base font-bold text-gray-900">WhatsApp chat</p><p class="text-sm text-gray-500">Message us on WhatsApp</p>
            </a>
        @endif
        @if ($email !== '')
            <a href="mailto:{{ $email }}" class="rounded-2xl border border-gray-200 bg-white p-6 text-center shadow-sm hover:border-brand-300">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-blue-600"><x-lm.icon name="mail" class="h-6 w-6" /></span>
                <p class="mt-3 break-all text-base font-bold text-gray-900">{{ $email }}</p><p class="text-sm text-gray-500">For more info</p>
            </a>
        @endif
    </div>

    <p class="text-sm text-gray-500">Prefer to write it down? <a href="{{ route('app.help.index', ['library' => \App\Support\Tenancy::slug()]) }}" wire:navigate class="font-semibold text-brand-700 hover:text-brand-800">Send a support request</a> and track the reply here.</p>
</div>
