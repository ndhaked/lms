<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Reports\Report;
use App\Reports\ReportRegistry;
use App\Support\LibrarySettings;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithPagination;

    #[Url]
    public string $report = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        abort_if(ReportRegistry::visibleTo(auth()->user())->isEmpty(), 403);

        if (! $this->definition) {
            $this->report = ReportRegistry::visibleTo(auth()->user())->keys()->first();
            $this->applyDefaults();
        } elseif ($this->from === '' && $this->until === '') {
            $this->applyDefaults();
        }
    }

    #[Computed]
    public function available()
    {
        return ReportRegistry::visibleTo(auth()->user());
    }

    /** The selected report, only if this user may see it. */
    #[Computed]
    public function definition(): ?Report
    {
        return $this->available->get($this->report);
    }

    public function updatedReport(): void
    {
        abort_unless($this->definition, 403);
        $this->applyDefaults();
        $this->resetPage();
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['from', 'until', 'status'], true)) {
            $this->resetPage();
        }
    }

    protected function applyDefaults(): void
    {
        $d = $this->definition?->defaultFilters() ?? [];
        $this->from = $d['from'] ?? '';
        $this->until = $d['until'] ?? '';
        $this->status = '';
    }

    public function filters(): array
    {
        return ['from' => $this->from ?: null, 'until' => $this->until ?: null, 'status' => $this->status ?: null];
    }

    #[Computed]
    public function paginator()
    {
        return $this->definition?->query($this->filters())?->paginate(25);
    }

    #[Computed]
    public function rows()
    {
        $def = $this->definition;
        if (! $def) {
            return collect();
        }

        return $this->paginator
            ? $this->paginator->getCollection()->map(fn ($m) => $def->row($m))
            : $def->collection($this->filters());
    }

    #[Computed]
    public function summary(): array
    {
        return $this->definition?->summary($this->filters()) ?? [];
    }

    #[Computed]
    public function canExport(): bool
    {
        $d = $this->definition;

        return $d && $this->can($d->exportPermission()) && (bool) LibrarySettings::get('export_enabled') && \App\Support\Entitlements::hasModule('exports');
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Reports" subtitle="Operational and financial reports" /></x-slot:header>

    @php
        $def = $this->definition;
        $slug = \App\Support\Tenancy::slug();
        $groups = [
            'Operations' => $this->available->filter(fn ($r) => $r->permission() === 'reports.operational'),
            'Finance' => $this->available->filter(fn ($r) => $r->permission() === 'reports.financial'),
        ];
    @endphp

    <div class="grid gap-4 lg:grid-cols-[16rem_1fr]">
        {{-- report list --}}
        <aside class="space-y-4 rounded-xl border border-gray-200 bg-white p-3 lg:self-start">
            @foreach ($groups as $label => $items)
                @if ($items->isNotEmpty())
                    <div>
                        <p class="px-2 pb-1 text-[11px] font-semibold uppercase tracking-wider text-gray-400">{{ $label }}</p>
                        @foreach ($items as $r)
                            <button type="button" wire:click="$set('report', '{{ $r->key() }}')" wire:key="rep-{{ $r->key() }}"
                                class="block w-full rounded-lg px-3 py-2 text-left text-sm {{ $report === $r->key() ? 'bg-brand-100/70 font-semibold text-brand-700' : 'text-gray-600 hover:bg-gray-50' }}">{{ $r->title() }}</button>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </aside>

        <div class="min-w-0 space-y-4">
            @if ($def)
                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><h2 class="text-base font-semibold text-gray-900">{{ $def->title() }}</h2><p class="text-xs text-gray-500">{{ $def->description() }}</p></div>
                        @if ($this->canExport)
                            @php $q = array_filter($this->filters()); @endphp
                            <div class="flex gap-2">
                                <a href="{{ route('app.reports.export', ['library' => $slug, 'report' => $def->key(), 'format' => 'csv'] + $q) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">CSV</a>
                                <a href="{{ route('app.reports.export', ['library' => $slug, 'report' => $def->key(), 'format' => 'xlsx'] + $q) }}" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Excel</a>
                                <a href="{{ route('app.reports.export', ['library' => $slug, 'report' => $def->key(), 'format' => 'pdf'] + $q) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">PDF</a>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 flex flex-wrap items-end gap-3">
                        @if ($def->usesRange())
                            <label class="text-xs text-gray-500">From <input type="date" wire:model.live="from" class="ml-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></label>
                            <label class="text-xs text-gray-500">Until <input type="date" wire:model.live="until" class="ml-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></label>
                        @endif
                        @if ($options = $def->statusOptions())
                            <label class="text-xs text-gray-500">{{ $def->statusLabel() }}
                                <select wire:model.live="status" class="ml-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                    <option value="">All</option>
                                    @foreach ($options as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach
                                </select>
                            </label>
                        @endif
                        <span wire:loading class="text-xs text-gray-400">Loading…</span>
                    </div>

                    @if ($this->summary)
                        <dl class="mt-4 flex flex-wrap gap-3">
                            @foreach ($this->summary as $label => $value)
                                <div class="rounded-lg bg-gray-50 px-4 py-2"><dt class="text-[11px] text-gray-500">{{ $label }}</dt><dd class="text-sm font-semibold text-gray-900">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr>@foreach ($def->headers() as $h)<th class="whitespace-nowrap px-4 py-3 font-medium">{{ $h }}</th>@endforeach</tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($this->rows as $i => $row)
                                    <tr wire:key="row-{{ $report }}-{{ $i }}">
                                        @foreach ($row as $cell)<td class="whitespace-nowrap px-4 py-2.5 text-gray-700">{{ $cell }}</td>@endforeach
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ count($def->headers()) }}" class="px-4 py-10 text-center text-gray-400">No data for these filters.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if ($this->paginator?->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->paginator->links() }}</div> @endif
                </div>
            @endif
        </div>
    </div>
</div>
