@props(['label', 'value', 'icon' => 'badge', 'tone' => 'green', 'hint' => null, 'trend' => null, 'up' => true])

@php
    $tones = [
        'green' => 'bg-brand-100 text-brand-600',
        'blue' => 'bg-blue-100 text-blue-600',
        'purple' => 'bg-violet-100 text-violet-600',
        'orange' => 'bg-orange-100 text-orange-500',
        'red' => 'bg-red-100 text-red-500',
        'gray' => 'bg-gray-100 text-gray-500',
    ];
@endphp

<div {{ $attributes->class(['rounded-xl border border-gray-200 bg-white p-4']) }}>
    <div class="flex items-center gap-3">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $tones[$tone] ?? $tones['green'] }}">
            <x-lm.icon :name="$icon" class="h-6 w-6" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] leading-tight text-gray-600">{{ $label }}</p>
            <p class="truncate text-xl font-bold leading-tight text-gray-900">{{ $value }}</p>
        </div>
    </div>
    @if ($trend)
        <p class="mt-3 flex items-center gap-1 text-[11px] {{ $up ? 'text-brand-600' : 'text-red-500' }}">
            <x-lm.icon :name="$up ? 'up' : 'down'" class="h-3 w-3" />
            <span class="font-semibold">{{ $trend }}</span> <span class="text-gray-400">vs last year</span>
        </p>
    @elseif ($hint)
        <p class="mt-3 text-[11px] text-gray-400">{{ $hint }}</p>
    @endif
</div>
