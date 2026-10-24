{{-- Dashboard-only controls: academic year filter and quick student search. --}}
@php $period = $this->period; @endphp
<div class="flex flex-wrap items-center justify-end gap-3">
    <div class="flex items-stretch overflow-hidden rounded-lg border border-gray-200 bg-white text-xs">
        <span class="flex items-center border-r border-gray-200 px-3 py-2 font-medium text-gray-700">Academic Year</span>
        <select wire:model.live="year" aria-label="Academic year" class="border-0 bg-white py-2 pl-3 pr-8 text-xs font-medium text-gray-700 focus:ring-0">
            @foreach (\App\Support\AcademicYear::options() as $start => $label)
                <option value="{{ $start }}" @selected($start === $period['start'])>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @if (auth()->user()->can('students.view'))
        <form method="GET" action="{{ route('app.students.index', ['library' => \App\Support\Tenancy::slug()]) }}" class="relative w-72 max-w-full">
            <input type="search" name="q" placeholder="Search student, phone, ID..."
                class="w-full rounded-lg border border-gray-200 bg-white py-2 pl-3.5 pr-9 text-xs text-gray-700 placeholder:text-gray-400 focus:border-brand-500 focus:ring-1 focus:ring-brand-500">
            <button type="submit" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700" aria-label="Search"><x-lm.icon name="search" class="h-4 w-4" /></button>
        </form>
    @endif
</div>

@unless ($period['current'])
    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm text-blue-900">
        <span>Showing <strong>{{ $period['label'] }}</strong> ({{ $period['from']->format('d M Y') }} – {{ $period['to']->format('d M Y') }}). Live figures such as seat occupancy are for the current year only.</span>
        <button type="button" wire:click="$set('year', '')" class="font-semibold underline">Back to current year</button>
    </div>
@endunless