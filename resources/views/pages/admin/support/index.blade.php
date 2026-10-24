<?php

use App\Models\AuditLog;
use App\Models\SupportRequest;
use App\Support\Notifier;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'open';

    public ?int $viewing = null;
    public string $response = '';

    public function mount(): void
    {
        $this->authorizeManage();
    }

    protected function authorizeManage(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('libraries.manage'), 403);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function requests()
    {
        return SupportRequest::with(['library', 'requester'])->when($this->status !== '', fn ($q) => $q->where('status', $this->status))->latest('id')->paginate(15);
    }

    #[Computed]
    public function item(): ?SupportRequest
    {
        return $this->viewing ? SupportRequest::with(['library', 'requester', 'responder'])->find($this->viewing) : null;
    }

    public function open(int $id): void
    {
        $this->authorizeManage();
        $this->viewing = SupportRequest::findOrFail($id)->id;
        $this->response = '';
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->viewing = null;
    }

    public function respond(): void
    {
        $this->authorizeManage();
        $this->validate(['response' => ['required', 'string', 'max:3000']], [], ['response' => 'reply']);

        $req = SupportRequest::with('library')->findOrFail($this->viewing);
        abort_unless($req->status === 'open', 422);

        $req->forceFill(['status' => 'resolved', 'response' => $this->response, 'responded_by' => auth()->id(), 'responded_at' => now()])->save();
        AuditLog::record('support.resolved', $req, ['subject' => $req->subject], $req->library_id);

        if ($req->requester) {
            Notifier::user($req->requester, $req->library_id, 'support.reply', 'Support replied: '.$req->subject, $this->response,
                route('app.subscription.index', ['library' => $req->library->slug]));
        }

        $this->viewing = null;
        unset($this->requests);
        $this->dispatch('notify', message: 'Reply sent and request resolved.');
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Support requests" subtitle="Questions from libraries" /></x-slot:header>

    <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <option value="open">Open</option><option value="resolved">Resolved</option><option value="">All</option>
    </select>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Received</th><th class="px-4 py-3 font-medium">Library</th><th class="px-4 py-3 font-medium">From</th><th class="px-4 py-3 font-medium">Subject</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->requests as $r)
                        <tr wire:key="sr-{{ $r->id }}">
                            <td class="px-4 py-3 text-gray-600">{{ $r->created_at->format('d M Y, H:i') }}</td>
                            <td class="px-4 py-3">{{ $r->library->name }}<div class="text-xs text-gray-400">{{ $r->library->code }}</div></td>
                            <td class="px-4 py-3 text-gray-600">{{ $r->requester?->name ?? '—' }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $r->subject }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$r->status === 'open' ? 'amber' : 'green'">{{ ucfirst($r->status) }}</x-ui.badge></td>
                            <td class="px-4 py-3 text-right"><button wire:click="open({{ $r->id }})" class="font-medium text-brand-600 hover:text-brand-800">{{ $r->status === 'open' ? 'Reply' : 'View' }}</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">No requests here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->requests->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->requests->links() }}</div> @endif
    </div>

    @if ($this->item)
        @php $r = $this->item; @endphp
        <x-ui.modal :title="$r->subject" close="close()" max="max-w-xl" wire:key="sup-modal">
            <div class="space-y-4 text-sm">
                <p class="text-xs text-gray-500">{{ $r->library->name }} ({{ $r->library->code }}) · {{ $r->requester?->name }} · {{ $r->created_at->format('d M Y, H:i') }}</p>
                <p class="whitespace-pre-line rounded-lg bg-gray-50 p-3 text-gray-800">{{ $r->message }}</p>
                @if ($r->status === 'resolved')
                    <div class="rounded-lg border border-brand-200 bg-brand-50/60 p-3"><p class="text-xs font-medium text-brand-800">Answered by {{ $r->responder?->name ?? '—' }} · {{ $r->responded_at?->format('d M Y, H:i') }}</p><p class="mt-1 whitespace-pre-line text-gray-800">{{ $r->response }}</p></div>
                    <div class="flex justify-end"><x-ui.button variant="secondary" wire:click="close">Close</x-ui.button></div>
                @else
                    <form wire:submit="respond" class="space-y-3">
                        <x-ui.textarea name="response" label="Your reply" required rows="5" wire:model="response" />
                        <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="close">Cancel</x-ui.button><x-ui.button type="submit">Send reply &amp; resolve</x-ui.button></div>
                    </form>
                @endif
            </div>
        </x-ui.modal>
    @endif
</div>
