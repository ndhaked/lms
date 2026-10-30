@props(['segments' => [], 'size' => 150, 'center' => null, 'sub' => null])

{{-- Donut chart in plain SVG. $segments: [['label' => .., 'value' => .., 'color' => '#hex'], ...]; an empty total draws a grey ring. --}}
@php
    $total = collect($segments)->sum('value');
    $r = 52; $circ = 2 * M_PI * $r; $offset = 0;
@endphp
<div class="flex flex-col items-center gap-3">
    <div class="relative" style="width: {{ $size }}px; height: {{ $size }}px">
        <svg viewBox="0 0 140 140" class="h-full w-full -rotate-90">
            <circle cx="70" cy="70" r="{{ $r }}" fill="none" stroke="#e5e7eb" stroke-width="18" />
            @if ($total > 0)
                @foreach ($segments as $s)
                    @continue(! $s['value'])
                    @php $len = $s['value'] / $total * $circ; @endphp
                    <circle cx="70" cy="70" r="{{ $r }}" fill="none" stroke="{{ $s['color'] }}" stroke-width="18" stroke-dasharray="{{ round($len, 2) }} {{ round($circ - $len, 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}" />
                    @php $offset += $len; @endphp
                @endforeach
            @endif
        </svg>
        @if ($center !== null)
            <div class="absolute inset-0 flex flex-col items-center justify-center text-center"><span class="text-2xl font-extrabold text-gray-900">{{ $center }}</span>@if ($sub)<span class="text-[11px] text-gray-500">{{ $sub }}</span>@endif</div>
        @endif
    </div>
    <ul class="flex flex-wrap justify-center gap-x-3 gap-y-1 text-[11px] text-gray-600">
        @foreach ($segments as $s)
            <li class="flex items-center gap-1"><span class="h-2 w-2 rounded-full" style="background: {{ $s['color'] }}"></span>{{ $s['label'] }}</li>
        @endforeach
    </ul>
</div>
