@props(['title', 'close' => 'closeModal()', 'max' => 'max-w-lg'])

{{-- Render inside @if($show). $close is a Livewire expression, e.g. "closeModal()" or "$set('open', false)".
     Escape, the X button and clicking the backdrop all run it. --}}
<div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 px-4 py-10"
    x-data @keydown.escape.window="$wire.{{ $close }}">
    <div class="w-full {{ $max }} rounded-xl bg-white p-6 shadow-lg" @click.outside="$wire.{{ $close }}">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="text-lg font-semibold text-gray-900">{{ $title }}</h3>
            <button type="button" wire:click="{{ $close }}" class="text-gray-400 hover:text-gray-600" aria-label="Close">
                <x-lm.icon name="x" class="h-5 w-5" />
            </button>
        </div>
        {{ $slot }}
    </div>
</div>
