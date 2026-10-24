<?php

use App\Models\AuditLog;
use App\Models\Library;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $action = '';

    #[Url]
    public string $libraryId = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    public ?int $viewing = null;

    public function updated(string $name): void
    {
        if (in_array($name, ['action', 'libraryId', 'from', 'until'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function actions(): array
    {
        return AuditLog::query()->distinct()->orderBy('action')->pluck('action')->all();
    }

    #[Computed]
    public function libraries()
    {
        return Library::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function logs()
    {
        return AuditLog::with(['actor', 'library'])
            ->when($this->action !== '', fn ($q) => $q->where('action', $this->action))
            ->when($this->libraryId !== '', fn ($q) => $q->where('library_id', $this->libraryId))
            ->when($this->from !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->until !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->until))
            ->latest('created_at')->latest('id')
            ->paginate(20);
    }

    #[Computed]
    public function entry(): ?AuditLog
    {
        return $this->viewing ? AuditLog::with(['actor', 'library'])->find($this->viewing) : null;
    }

    public function showEntry(int $id): void
    {
        $this->viewing = $id;
    }

    public function closeModal(): void
    {
        $this->viewing = null;
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Audit logs" subtitle="A read-only record of sensitive actions" /></x-slot:header>

    <div class="flex flex-wrap items-end gap-3">
        <select wire:model.live="action" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="">All actions</option>
            @foreach ($this->actions as $a) <option value="{{ $a }}">{{ $a }}</option> @endforeach
        </select>
        <select wire:model.live="libraryId" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <option value="">All libraries</option>
            @foreach ($this->libraries as $l) <option value="{{ $l->id }}">{{ $l->name }}</option> @endforeach
        </select>
        <label class="text-xs text-gray-500">From <input type="date" wire:model.live="from" class="ml-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></label>
        <label class="text-xs text-gray-500">Until <input type="date" wire:model.live="until" class="ml-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></label>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500">
                    <tr><th class="px-4 py-3 font-medium">When</th><th class="px-4 py-3 font-medium">Action</th><th class="px-4 py-3 font-medium">Actor</th><th class="px-4 py-3 font-medium">Library</th><th class="px-4 py-3 font-medium">Record</th><th class="px-4 py-3 text-right font-medium"></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td class="px-4 py-3 text-gray-600">{{ $log->created_at->format('d M Y, H:i') }}</td>
                            <td class="px-4 py-3"><x-ui.badge>{{ $log->action }}</x-ui.badge></td>
                            <td class="px-4 py-3">{{ $log->actor?->name ?? 'System' }}</td>
                            <td class="px-4 py-3">{{ $log->library?->name ?? 'Platform' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : '—' }}</td>
                            <td class="px-4 py-3 text-right"><button wire:click="showEntry({{ $log->id }})" class="font-medium text-brand-600 hover:text-brand-800">View</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">No audit entries match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->logs->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->logs->links() }}</div> @endif
    </div>

    @if ($this->entry)
        <x-ui.modal title="Audit entry" max="max-w-2xl" wire:key="entry-modal">
            @php $e = $this->entry; @endphp
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-xs font-medium text-gray-500">When</dt><dd>{{ $e->created_at->format('d M Y, H:i:s') }}</dd></div>
                <div><dt class="text-xs font-medium text-gray-500">Action</dt><dd>{{ $e->action }}</dd></div>
                <div><dt class="text-xs font-medium text-gray-500">Actor</dt><dd>{{ $e->actor?->name ?? 'System' }}</dd></div>
                <div><dt class="text-xs font-medium text-gray-500">Library</dt><dd>{{ $e->library?->name ?? 'Platform' }}</dd></div>
                <div><dt class="text-xs font-medium text-gray-500">IP address</dt><dd>{{ $e->ip_address ?? '—' }}</dd></div>
                <div><dt class="text-xs font-medium text-gray-500">Record</dt><dd>{{ $e->auditable_type ? class_basename($e->auditable_type).' #'.$e->auditable_id : '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs font-medium text-gray-500">Details</dt>
                    <dd><pre class="mt-1 overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs">{{ $e->properties ? json_encode($e->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '—' }}</pre></dd></div>
            </dl>
        </x-ui.modal>
    @endif
</div>
