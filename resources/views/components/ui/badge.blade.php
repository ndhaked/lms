@props(['color' => 'gray'])

@php
    $map = [
        'gray' => 'bg-gray-100 text-gray-600',
        'green' => 'bg-brand-50 text-brand-700 ring-brand-200',
        'blue' => 'bg-blue-50 text-blue-700 ring-blue-200',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'red' => 'bg-red-50 text-red-700 ring-red-200',
        'purple' => 'bg-violet-50 text-violet-700 ring-violet-200',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-transparent', $map[$color] ?? $map['gray']]) }}>{{ $slot }}</span>
