<?php

use App\Models\Library;
use App\Models\MessageLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Every SMS and WhatsApp message sent (or that failed) across all libraries, for finding out why something did not go out. */
new #[Layout('layouts::panel')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $channel = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $library = '';

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $open = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('payments.approve'), 403);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['channel', 'status', 'library', 'search'], true)) {
            $this->resetPage();
        }
    }

    protected function query()
    {
        return MessageLog::withoutGlobalScopes()->with('library:id,name,code')
            ->when(in_array($this->channel, ['sms', 'whatsapp'], true), fn ($q) => $q->where('channel', $this->channel))
            ->when(in_array($this->status, ['sent', 'failed'], true), fn ($q) => $q->where('status', $this->status))
            ->when($this->library !== '', fn ($q) => $q->where('library_id', (int) $this->library))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('mobile', 'like', "%{$this->search}%")->orWhere('message', 'like', "%{$this->search}%")->orWhere('error', 'like', "%{$this->search}%")));
    }

    #[Computed]
    public function logs()
    {
        return $this->query()->latest('id')->paginate(20);
    }

    #[Computed]
    public function libraries()
    {
        return Library::orderBy('name')->get(['id', 'name']);
    }

    /** Totals for the last 24 hours, whatever filters are set. */
    #[Computed]
    public function today(): array
    {
        $day = MessageLog::withoutGlobalScopes()->where('created_at', '>=', now()->subDay());

        return ['sent' => (clone $day)->where('status', 'sent')->count(), 'failed' => (clone $day)->where('status', 'failed')->count()];
    }

    /** The most common reasons for failure in the last 7 days. */
    #[Computed]
    public function reasons()
    {
        return MessageLog::withoutGlobalScopes()->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('error, count(*) as n')->groupBy('error')->orderByDesc('n')->limit(4)->get();
    }

    #[Computed]
    public function item(): ?MessageLog
    {
        return $this->open ? MessageLog::withoutGlobalScopes()->with('library:id,name,code')->find($this->open) : null;
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Message logs" subtitle="Every SMS and WhatsApp message, with the reason when it failed" /></x-slot:header>

    @php $t = $this->today; @endphp

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-4"><p class="text-xs font-medium text-gray-500">Sent, last 24 hours</p><p class="text-2xl font-extrabold text-emerald-600">{{ number_format($t['sent']) }}</p></div>
        <div class="rounded-xl border border-gray-200 bg-white p-4"><p class="text-xs font-medium text-gray-500">Failed, last 24 hours</p><p class="text-2xl font-extrabold {{ $t['failed'] ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($t['failed']) }}</p></div>
        <div class="rounded-xl border border-gray-200 bg-white p-4"><p class="mb-1 text-xs font-medium text-gray-500">Top failure reasons, 7 days</p>
            @forelse ($this->reasons as $r)<p class="flex justify-between gap-2 text-xs text-gray-700"><span class="truncate">{{ $r->error ?: 'Unknown' }}</span><span class="font-bold">{{ $r->n }}</span></p>@empty<p class="text-xs text-gray-400">No failures.</p>@endforelse
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search number, message or error…" class="w-64 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <select wire:model.live="channel" class="rounded-lg border-gray-300 text-sm"><option value="">SMS and WhatsApp</option><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option></select>
        <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm"><option value="">Sent and failed</option><option value="failed">Failed only</option><option value="sent">Sent only</option></select>
        <select wire:model.live="library" class="rounded-lg border-gray-300 text-sm"><option value="">All libraries</option>@foreach ($this->libraries as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Date</th><th class="px-4 py-3 font-medium">Library</th><th class="px-4 py-3 font-medium">Channel</th><th class="px-4 py-3 font-medium">Mobile</th><th class="px-4 py-3 font-medium">Event</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3 font-medium">Reason / message</th><th class="px-4 py-3"></th></tr></thead>
                <tbody>
                    @forelse ($this->logs as $m)
                        <tr class="border-t border-gray-100 align-top" wire:key="ml-{{ $m->id }}">
                            <td class="whitespace-nowrap px-4 py-3 text-gray-600">{{ $m->created_at->format('d M Y H:i') }}</td>
                            <td class="px-4 py-3">{{ $m->library?->name ?? '—' }}<div class="text-xs text-gray-400">{{ $m->library?->code }}</div></td>
                            <td class="px-4 py-3">{{ $m->channel === 'sms' ? 'SMS' : 'WhatsApp' }}<div class="text-xs text-gray-400">{{ $m->provider ?? '—' }}</div></td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $m->mobile }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $m->event ? (\App\Support\Messaging::EVENTS[$m->event][0] ?? $m->event) : '—' }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$m->status === 'sent' ? 'green' : 'red'">{{ ucfirst($m->status) }}</x-ui.badge></td>
                            <td class="max-w-xs px-4 py-3 text-xs {{ $m->status === 'failed' ? 'text-red-700' : 'text-gray-600' }}">{{ $m->status === 'failed' ? $m->error : \Illuminate\Support\Str::limit($m->message, 90) }}</td>
                            <td class="px-4 py-3 text-right"><button wire:click="$set('open', {{ $m->id }})" class="font-medium text-brand-600 hover:text-brand-800">Details</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-12 text-center text-gray-400">No messages match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->logs->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->logs->links() }}</div> @endif
    </div>

    @if ($this->item)
        @php $m = $this->item; @endphp
        <x-ui.modal title="Message #{{ $m->id }}" close="$set('open', null)" max="max-w-2xl" wire:key="log-modal">
            <div class="space-y-4 text-sm">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                    @foreach (['Library' => $m->library?->name ?? '—', 'Channel' => $m->channel === 'sms' ? 'SMS' : 'WhatsApp', 'Provider' => $m->provider ?? '—', 'Mobile' => $m->mobile, 'Credits used' => $m->count, 'Status' => ucfirst($m->status),
                        'Event' => $m->event ? (\App\Support\Messaging::EVENTS[$m->event][0] ?? $m->event) : '—', 'Time' => $m->created_at->format('d M Y H:i:s')] as $l => $v)
                        <div><dt class="text-xs font-medium text-gray-500">{{ $l }}</dt><dd class="mt-0.5 text-gray-900">{{ $v }}</dd></div>
                    @endforeach
                </dl>
                <div><p class="mb-1 text-xs font-medium text-gray-500">Message</p><p class="whitespace-pre-line rounded-lg bg-gray-50 p-3 text-gray-800">{{ $m->message }}</p></div>
                @if ($m->error)<div><p class="mb-1 text-xs font-medium text-gray-500">Why it failed</p><p class="rounded-lg bg-red-50 p-3 text-red-800">{{ $m->error }}</p></div>@endif
                @if ($m->response)<div><p class="mb-1 text-xs font-medium text-gray-500">Provider's reply</p><pre class="max-h-48 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-900 p-3 text-xs text-gray-100">{{ $m->response }}</pre></div>@endif
                <div class="flex justify-end"><x-ui.button variant="secondary" wire:click="$set('open', null)">Close</x-ui.button></div>
            </div>
        </x-ui.modal>
    @endif
</div>
