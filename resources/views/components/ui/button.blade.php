@props(['variant' => 'primary', 'type' => 'button'])

@php
    $styles = [
        'primary' => 'bg-brand-600 text-white hover:bg-brand-700 focus:ring-brand-500',
        'secondary' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-gray-300',
        'danger' => 'bg-red-600 text-white hover:bg-red-500 focus:ring-red-500',
        'warning' => 'bg-amber-500 text-white hover:bg-amber-600 focus:ring-amber-500',
    ];
@endphp

<button type="{{ $type }}" {{ $attributes->class(['inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-60', $styles[$variant] ?? $styles['primary']]) }}
    wire:loading.attr="disabled">
    {{ $slot }}
</button>
