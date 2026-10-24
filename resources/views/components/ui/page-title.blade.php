@props(['title', 'subtitle' => null])

{{-- The top header shows the title only (like the doctorweb admin); subtitle is kept as a tooltip. --}}
<h1 class="truncate text-lg font-semibold text-gray-900" @if ($subtitle) title="{{ $subtitle }}" @endif>{{ $title }}</h1>
