<?php

use App\Actions\Books\StockTake;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\BookCopy;
use App\Models\InventoryAudit;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithPagination;

    public int $auditId;

    #[Url]
    public string $view = 'missing';           // missing | unexpected | unknown

    public string $scan = '';
    public array $feed = [];                   // the latest scans, newest first: [['result', 'message']]

    public bool $showClose = false;
    public bool $markLost = false;
    public bool $markFound = true;
    public string $note = '';
    public ?array $closed = null;

    public function mount(string $audit): void
    {
        $this->authorizePermission('books.audit');
        $this->auditId = InventoryAudit::findOrFail($audit)->id;   // tenant-scoped
    }

    #[Computed]
    public function audit(): InventoryAudit
    {
        return InventoryAudit::with('starter')->findOrFail($this->auditId);
    }

    public function updatedView(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function remaining(): int
    {
        return $this->audit->isOpen() ? StockTake::missing($this->audit)->count() : (int) $this->audit->missing_count;
    }

    #[Computed]
    public function rows()
    {
        $q = $this->view === 'unexpected' ? StockTake::unexpected($this->audit) : StockTake::missing($this->audit);

        return $this->view === 'unknown' ? null : $q->orderBy('id')->paginate(25);
    }

    public function count(): void
    {
        $this->authorizePermission('books.audit');
        $code = $this->scan;
        $this->scan = '';
        if (trim($code) === '') {
            return;
        }

        try {
            $r = app(StockTake::class)->scan($this->audit, $code);
            array_unshift($this->feed, ['result' => $r['result'], 'message' => $r['message']]);
            $this->feed = array_slice($this->feed, 0, 8);
        } catch (BusinessRuleException $e) {
            array_unshift($this->feed, ['result' => 'error', 'message' => $e->getMessage()]);
        }

        unset($this->audit, $this->remaining, $this->rows);
        $this->dispatch('focus-scan');
    }

    public function close(): void
    {
        $this->authorizePermission('books.audit');

        try {
            $this->closed = app(StockTake::class)->complete($this->audit, $this->markLost, $this->markFound, $this->note);
        } catch (BusinessRuleException $e) {
            $this->addError('note', $e->getMessage());

            return;
        }

        $this->showClose = false;
        unset($this->audit, $this->remaining, $this->rows);
    }

    public function cancelAudit()
    {
        $this->authorizePermission('books.audit');

        try {
            app(StockTake::class)->cancel($this->audit);
        } catch (BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        return $this->redirect(route('app.books.audits', ['library' => \App\Support\Tenancy::slug()]), navigate: true);
    }

    public function closeModal(): void
    {
        $this->showClose = false;
    }
}
?>

<div class="space-y-5" x-data @focus-scan.window="$nextTick(() => $refs.scan?.focus())">
    <x-slot:header><x-ui.page-title :title="$this->audit->name" subtitle="Stock-take" /></x-slot:header>

    @php
        $a = $this->audit;
        $slug = \App\Support\Tenancy::slug();
        $pct = $a->expected_count ? round($a->found_count / $a->expected_count * 100) : 0;
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('app.books.audits', ['library' => $slug]) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-800">← All stock-takes</a>
        <div class="flex gap-2">
            <a href="{{ route('app.books.audits.export', ['library' => $slug, 'audit' => $a->id]) }}" class="rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Download CSV</a>
            @if ($a->isOpen())
                <x-ui.button variant="secondary" wire:click="cancelAudit" wire:confirm="Cancel this stock-take? Nothing in the register is changed.">Cancel</x-ui.button>
                <x-ui.button wire:click="$set('showClose', true)">Finish…</x-ui.button>
            @endif
        </div>
    </div>

    @if ($closed)
        <div class="rounded-xl border border-brand-200 bg-brand-50 p-4 text-sm text-brand-800">
            <p class="font-semibold">Stock-take finished.</p>
            <p class="mt-0.5">{{ $closed['missing'] }} missing.@if ($closed['marked_lost']) {{ $closed['marked_lost'] }} marked as lost in the register.@endif @if ($closed['found_again']) {{ $closed['found_again'] }} lost book(s) found and put back on the shelf.@endif @if ($closed['skipped']) {{ $closed['skipped'] }} left alone because they changed since.@endif</p>
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat label="To count" :value="number_format($a->expected_count)" icon="book" tone="blue" :hint="$a->scopeLabel()" />
        <x-ui.stat label="Counted" :value="number_format($a->found_count)" icon="check-circle" tone="green" :hint="$pct.'% done'" />
        <x-ui.stat :label="$a->isOpen() ? 'Not counted yet' : 'Missing'" :value="number_format($this->remaining)" icon="alert" :tone="$this->remaining > 0 ? 'red' : 'green'" :hint="$a->isOpen() ? 'So far' : 'At the end'" />
        <x-ui.stat label="Unexpected" :value="number_format($a->unexpected_count)" icon="alert" tone="orange" hint="Found, but the register disagrees" />
    </div>

    @if ($a->isOpen())
        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <input type="text" x-ref="scan" autofocus wire:model="scan" wire:keydown.enter="count" placeholder="Scan each copy's barcode and press Enter" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            @if ($feed)
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($feed as $i => $f)
                        <li wire:key="fd-{{ $i }}-{{ md5($f['message']) }}" class="rounded-md px-3 py-1.5 {{ match ($f['result']) { 'ok' => 'bg-brand-50 text-brand-800', 'already', 'outside' => 'bg-gray-50 text-gray-600', 'unexpected' => 'bg-amber-50 text-amber-800', default => 'bg-red-50 text-red-700' } }}">{{ $f['message'] }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    @else
        <p class="rounded-lg bg-gray-50 px-4 py-2.5 text-sm text-gray-600">{{ ucfirst($a->status) }} on {{ $a->closed_at?->format('d M Y H:i') }}.@if ($a->note) {{ $a->note }}@endif</p>
    @endif

    <div class="flex gap-2 border-b border-gray-200">
        @foreach (['missing' => ($a->isOpen() ? 'Not counted yet' : 'Missing'), 'unexpected' => 'Unexpected', 'unknown' => 'Unknown codes'] as $key => $label)
            <button wire:click="$set('view', '{{ $key }}')" wire:key="av-{{ $key }}" class="border-b-2 px-4 py-2.5 text-sm font-medium {{ $view === $key ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($view === 'unknown')
        <div class="rounded-xl border border-gray-200 bg-white p-5 text-sm">
            @forelse ((array) $a->unknown_codes as $code) <span class="mr-2 inline-block rounded bg-gray-100 px-2 py-1 font-mono text-xs text-gray-700" wire:key="uk-{{ $loop->index }}">{{ $code }}</span> @empty <p class="text-center text-gray-400">No unknown codes.</p> @endforelse
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead><tr class="bg-gray-50 text-left text-xs text-gray-500"><th class="px-4 py-3 font-medium">Accession no.</th><th class="px-4 py-3 font-medium">Book</th><th class="px-4 py-3 font-medium">Shelf</th><th class="px-4 py-3 font-medium">Register says</th></tr></thead>
                <tbody>
                    @forelse ($this->rows as $i)
                        <tr class="border-t border-gray-100" wire:key="ar-{{ $i->id }}">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $i->copy->accession_no }}</td>
                            <td class="px-4 py-3"><p class="text-gray-900">{{ $i->copy->book->title }}</p><p class="text-xs text-gray-500">{{ $i->copy->book->author }}</p></td>
                            <td class="px-4 py-3 text-gray-600">{{ $i->copy->book->locationText() ?: '—' }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="in_array($i->status_at_start, ['lost'], true) ? 'red' : (in_array($i->status_at_start, ['issued'], true) ? 'blue' : 'gray')">{{ \App\Models\BookCopy::STATUSES[$i->status_at_start] ?? $i->status_at_start }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-gray-400">{{ $view === 'missing' ? ($a->isOpen() ? 'Everything has been counted.' : 'Nothing is missing.') : 'Nothing unexpected.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $this->rows?->links() }}
    @endif

    @if ($showClose)
        <x-ui.modal title="Finish the stock-take" max="max-w-lg" wire:key="close-modal">
            <form wire:submit="close" class="space-y-4">
                <p class="text-sm text-gray-700">{{ number_format($this->remaining) }} copies were not counted. {{ number_format($a->unexpected_count) }} turned up that the register did not expect.</p>
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="markLost" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Mark the {{ number_format($this->remaining) }} uncounted copies as Lost</span><br><span class="text-xs text-gray-500">Only copies still shown as available are changed. Leave unticked to just record the result.</span></span></label>
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="markFound" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Put found copies that were marked Lost back on the shelf</span></span></label>
                <x-ui.textarea name="note" label="Note (optional)" wire:model="note" rows="2" />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Keep counting</x-ui.button><x-ui.button type="submit">Finish</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
