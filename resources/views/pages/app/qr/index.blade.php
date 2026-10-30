<?php

use App\Support\Qr;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    public string $source = 'login';      // login | website | referral | custom
    public string $custom = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('students.view'), 403);
    }

    #[Computed]
    public function target(): string
    {
        return match ($this->source) {
            'website' => url('/'),
            'referral' => url('/').'?ref='.Tenancy::library()->code,
            'custom' => trim($this->custom),
            default => route('login'),
        };
    }

    #[Computed]
    public function svg(): string
    {
        return $this->target === '' ? '' : Qr::svg(mb_substr($this->target, 0, 500), 320);
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Generate QR code" subtitle="Print it for your desk, door or flyers" /></x-slot:header>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <x-ui.select name="source" label="What should the QR open?" wire:model.live="source">
                <option value="login">Student and staff sign-in page</option>
                <option value="website">Our website</option>
                <option value="referral">My referral link</option>
                <option value="custom">Any link or text</option>
            </x-ui.select>
            @if ($source === 'custom')
                <x-ui.input name="custom" label="Link or text" placeholder="https://" wire:model.live.debounce.400ms="custom" />
            @endif
            <p class="break-all rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">{{ $this->target ?: 'Type something to generate a code.' }}</p>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 text-center shadow-sm" x-data="{
            download() { const svg = this.$refs.qr.innerHTML; const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' })); a.download = 'qr-code.svg'; a.click(); URL.revokeObjectURL(a.href) } }">
            @if ($this->svg !== '')
                <div x-ref="qr" class="mx-auto w-64 max-w-full rounded-xl border border-gray-200 bg-white p-3 [&>svg]:h-auto [&>svg]:w-full">{!! $this->svg !!}</div>
                <p class="mt-3 text-sm font-semibold text-gray-900">{{ \App\Support\Tenancy::library()->name }}</p>
                <div class="mt-4 flex justify-center gap-3">
                    <button type="button" x-on:click="download()" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Download</button>
                    <button type="button" x-on:click="window.print()" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Print</button>
                </div>
            @else
                <p class="py-16 text-sm text-gray-400">Your QR code appears here.</p>
            @endif
        </section>
    </div>
</div>
