<?php

use App\Models\AuditLog;
use App\Models\Lead;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'new';

    #[Url]
    public string $type = '';

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $noting = null;
    public string $note = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('libraries.manage'), 403);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['status', 'type', 'search'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function counts(): array
    {
        return Lead::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
    }

    #[Computed]
    public function rows()
    {
        return Lead::with(['plan', 'library', 'handler'])
            ->when(array_key_exists($this->status, Lead::STATUSES), fn ($q) => $q->where('status', $this->status))
            ->when(array_key_exists($this->type, Lead::TYPES), fn ($q) => $q->where('type', $this->type))
            ->when(trim($this->search) !== '', function ($q) {
                $t = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('library_name', 'like', $t)->orWhere('name', 'like', $t)->orWhere('email', 'like', $t)->orWhere('phone', 'like', $t)->orWhere('city', 'like', $t));
            })
            ->latest('id')->paginate(20);
    }

    public function mark(int $id, string $status): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('libraries.manage'), 403);
        abort_unless(in_array($status, ['new', 'contacted', 'closed'], true), 422);

        $lead = Lead::findOrFail($id);
        if ($lead->status === 'converted') {
            $this->dispatch('notify', type: 'error', message: 'A library was already created from this request.');

            return;
        }

        $lead->forceFill(['status' => $status, 'handled_by' => auth()->id()])->save();
        AuditLog::record('lead.'.$status, $lead, ['library' => $lead->library_name], null);
        unset($this->rows, $this->counts);
    }

    public function openNote(int $id): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('libraries.manage'), 403);
        $this->noting = Lead::findOrFail($id)->id;
        $this->note = (string) Lead::find($id)->notes;
    }

    public function saveNote(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('libraries.manage'), 403);
        $this->validate(['note' => ['nullable', 'string', 'max:3000']]);

        Lead::findOrFail($this->noting)->forceFill(['notes' => trim($this->note) ?: null, 'handled_by' => auth()->id()])->save();
        $this->noting = null;
        unset($this->rows);
        $this->dispatch('notify', message: 'Saved.');
    }

    public function closeModal(): void
    {
        $this->noting = null;
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Leads" subtitle="Free-trial and demo requests from the website" /></x-slot:header>

    @php $c = $this->counts; @endphp

    <div class="flex flex-wrap items-center gap-2">
        @foreach (['new' => 'New', 'contacted' => 'Contacted', 'converted' => 'Library created', 'closed' => 'Closed', 'all' => 'All'] as $key => $label)
            <button wire:click="$set('status', '{{ $key }}')" wire:key="ls-{{ $key }}" class="rounded-lg border px-3.5 py-1.5 text-sm font-medium {{ $status === $key ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50' }}">{{ $label }}@if ($key !== 'all') <span class="ml-1 {{ $key === 'new' && ($c['new'] ?? 0) > 0 ? 'font-bold text-red-600' : 'text-gray-400' }}">{{ $c[$key] ?? 0 }}</span>@endif</button>
        @endforeach
        <select wire:model.live="type" class="ml-auto rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"><option value="">All requests</option><option value="trial">Free months</option><option value="demo">Demo</option></select>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Library, name, phone…" class="w-56 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left text-xs text-gray-500"><th class="px-4 py-3 font-medium">Library</th><th class="px-4 py-3 font-medium">Contact</th><th class="px-4 py-3 font-medium">Wants</th><th class="px-4 py-3 font-medium">Received</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th></tr></thead>
            <tbody>
                @forelse ($this->rows as $l)
                    <tr class="border-t border-gray-100 align-top" wire:key="ld-{{ $l->id }}">
                        <td class="px-4 py-3"><p class="font-medium text-gray-900">{{ $l->library_name }}</p><p class="text-xs text-gray-500">{{ collect([$l->city, $l->students ? \App\Models\Lead::SIZES[$l->students] ?? $l->students : null])->filter()->implode(' · ') }}</p>@if ($l->referral_code)<p class="mt-1 text-xs font-medium text-violet-700">Referred by {{ $l->referral_code }}</p>@endif @if ($l->message)<p class="mt-1 max-w-xs text-xs italic text-gray-500">“{{ \Illuminate\Support\Str::limit($l->message, 140) }}”</p>@endif</td>
                        <td class="px-4 py-3"><p class="text-gray-900">{{ $l->name }}</p><a href="tel:{{ $l->phone }}" class="block text-xs text-brand-700 hover:underline">{{ $l->phone }}</a><a href="mailto:{{ $l->email }}" class="block text-xs text-gray-500 hover:underline">{{ $l->email }}</a></td>
                        <td class="px-4 py-3"><x-ui.badge :color="$l->type === 'demo' ? 'blue' : 'green'">{{ \App\Models\Lead::TYPES[$l->type] }}</x-ui.badge>
                            @if ($l->plan) <p class="mt-1 text-xs text-gray-500">{{ $l->plan->name }}</p> @endif
                            @if ($l->preferred_date) <p class="mt-1 text-xs text-gray-500">{{ $l->preferred_date->format('d M Y') }} {{ $l->preferred_time ? '· '.(\App\Models\Lead::TIMES[$l->preferred_time] ?? '') : '' }}</p> @endif</td>
                        <td class="px-4 py-3 text-gray-600">{{ $l->created_at->format('d M Y') }}<p class="text-xs text-gray-400">{{ $l->created_at->diffForHumans() }}</p></td>
                        <td class="px-4 py-3"><x-ui.badge :color="match ($l->status) { 'new' => 'red', 'contacted' => 'amber', 'converted' => 'green', default => 'gray' }">{{ \App\Models\Lead::STATUSES[$l->status] }}</x-ui.badge>
                            @if ($l->notes) <p class="mt-1 max-w-40 truncate text-xs text-gray-500" title="{{ $l->notes }}">{{ $l->notes }}</p> @endif</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            @if ($l->status === 'converted' && $l->library) <a href="{{ route('admin.libraries.show', $l->library) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-800">Open library</a>
                            @else
                                <a href="{{ route('admin.libraries.create', ['lead' => $l->id]) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-800">Create library</a>
                                @if ($l->status === 'new') <button wire:click="mark({{ $l->id }}, 'contacted')" class="ml-3 font-medium text-gray-600 hover:text-gray-900">Mark contacted</button> @endif
                                @if ($l->status !== 'closed') <button wire:click="mark({{ $l->id }}, 'closed')" class="ml-3 font-medium text-gray-500 hover:text-gray-800">Close</button> @else <button wire:click="mark({{ $l->id }}, 'new')" class="ml-3 font-medium text-gray-600 hover:text-gray-900">Reopen</button> @endif
                            @endif
                            <button wire:click="openNote({{ $l->id }})" class="ml-3 font-medium text-gray-500 hover:text-gray-800">Note</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">No requests here yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->rows->links() }}

    @if ($noting)
        <x-ui.modal title="Note on this request" max="max-w-md" wire:key="note-modal">
            <form wire:submit="saveNote" class="space-y-4">
                <x-ui.textarea name="note" label="Internal note (never shown to the visitor)" wire:model="note" rows="5" />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Save</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
