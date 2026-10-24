<?php

use App\Models\Library;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function libraries()
    {
        return Library::query()
            ->with('owner')
            ->withCount(['students', 'seats'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%")
                ->orWhereHas('owner', fn ($o) => $o->where('name', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%"))))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(15);
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Libraries" subtitle="Every library registered on the platform" /></x-slot:header>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name, code, owner…" class="w-64 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">All statuses</option>
                @foreach (\App\Models\Library::STATUSES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <span wire:loading class="text-xs text-gray-400">Loading…</span>
        </div>
        <a href="{{ route('admin.libraries.create') }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">
            <x-lm.icon name="plus" class="h-4 w-4" /> Register library
        </a>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Library</th>
                        <th class="px-4 py-3 font-medium">Owner</th>
                        <th class="px-4 py-3 font-medium">Registered</th>
                        <th class="px-4 py-3 text-right font-medium">Students</th>
                        <th class="px-4 py-3 text-right font-medium">Seats</th>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->libraries as $lib)
                        <tr wire:key="lib-{{ $lib->id }}" class="hover:bg-gray-50/60">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.libraries.show', $lib) }}" wire:navigate class="font-medium text-gray-900 hover:text-brand-700">{{ $lib->name }}</a>
                                <div class="text-xs text-gray-400">{{ $lib->code }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-gray-800">{{ $lib->owner?->name ?? '—' }}</div>
                                <div class="text-xs text-gray-400">{{ $lib->owner?->email }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $lib->created_at->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-right text-gray-700">{{ number_format($lib->students_count) }}</td>
                            <td class="px-4 py-3 text-right text-gray-700">{{ number_format($lib->seats_count) }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge :color="match ($lib->status) { 'active' => 'green', 'pending' => 'blue', 'suspended' => 'amber', default => 'gray' }">{{ \App\Models\Library::STATUSES[$lib->status] }}</x-ui.badge>
                            </td>
                            <td class="space-x-3 px-4 py-3 text-right">
                                <a href="{{ route('admin.libraries.show', $lib) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-800">View</a>
                                <a href="{{ route('admin.libraries.edit', $lib) }}" wire:navigate class="font-medium text-gray-500 hover:text-gray-800">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No libraries match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->libraries->hasPages())
            <div class="border-t border-gray-100 px-4 py-3">{{ $this->libraries->links() }}</div>
        @endif
    </div>
</div>
