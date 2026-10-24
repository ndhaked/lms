<?php

use App\Actions\Books\StockTake;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\BookCategory;
use App\Models\InventoryAudit;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions;

    public bool $showStart = false;
    public string $name = '';
    public string $scopeType = 'all';
    public string $scopeCategory = '';
    public string $scopeShelf = '';

    public function mount(): void
    {
        $this->authorizePermission('books.audit');
    }

    #[Computed]
    public function audits()
    {
        return InventoryAudit::with('starter')->latest('id')->limit(30)->get();
    }

    #[Computed]
    public function open(): ?InventoryAudit
    {
        return InventoryAudit::where('status', InventoryAudit::OPEN)->first();
    }

    #[Computed]
    public function categories()
    {
        return BookCategory::whereNull('parent_id')->orderBy('name')->get();
    }

    #[Computed]
    public function shelves()
    {
        return \App\Models\Book::where('type', 'physical')->whereNotNull('shelf')->distinct()->orderBy('shelf')->pluck('shelf');
    }

    public function openStart(): void
    {
        $this->authorizePermission('books.audit');
        $this->reset('scopeType', 'scopeCategory', 'scopeShelf');
        $this->name = 'Stock-take '.now()->format('d M Y');
        $this->resetErrorBag();
        $this->showStart = true;
    }

    public function start()
    {
        $this->authorizePermission('books.audit');
        $this->validate(['name' => ['required', 'string', 'max:120'], 'scopeType' => ['in:all,category,shelf']]);

        try {
            $audit = app(StockTake::class)->start($this->name, $this->scopeType, match ($this->scopeType) { 'category' => $this->scopeCategory, 'shelf' => $this->scopeShelf, default => null });
        } catch (BusinessRuleException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        return $this->redirect(route('app.books.audits.show', ['library' => \App\Support\Tenancy::slug(), 'audit' => $audit->id]), navigate: true);
    }

    public function closeModal(): void
    {
        $this->showStart = false;
    }
}
?>

<div class="max-w-4xl space-y-5">
    <x-slot:header><x-ui.page-title title="Stock-take" subtitle="Count the shelves and check the register" /></x-slot:header>

    @php $slug = \App\Support\Tenancy::slug(); @endphp

    <div class="flex items-center justify-between">
        <a href="{{ route('app.books.index', ['library' => $slug]) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-800">← All books</a>
        @if ($this->open)
            <a href="{{ route('app.books.audits.show', ['library' => $slug, 'audit' => $this->open->id]) }}" wire:navigate class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Continue “{{ $this->open->name }}”</a>
        @else
            <x-ui.button wire:click="openStart"><x-lm.icon name="plus" class="h-4 w-4" /> Start a stock-take</x-ui.button>
        @endif
    </div>

    <p class="rounded-lg bg-gray-50 p-4 text-sm text-gray-600">A stock-take checks the register against the shelves. You scan every copy you find; at the end, copies the register says are on the shelf but were not scanned are <strong>missing</strong>, and copies that turn up although the register says they are out or lost are <strong>unexpected</strong>. Nothing in the register changes until you close it, and then only what you choose.</p>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead><tr class="bg-gray-50 text-left text-xs text-gray-500"><th class="px-4 py-3 font-medium">Stock-take</th><th class="px-4 py-3 font-medium">Counted</th><th class="px-4 py-3 text-right font-medium">Found</th><th class="px-4 py-3 text-right font-medium">Missing</th><th class="px-4 py-3 text-right font-medium">Unexpected</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th></tr></thead>
            <tbody>
                @forelse ($this->audits as $a)
                    <tr class="border-t border-gray-100" wire:key="au-{{ $a->id }}">
                        <td class="px-4 py-3"><p class="font-medium text-gray-900">{{ $a->name }}</p><p class="text-xs text-gray-500">{{ $a->started_at->format('d M Y') }} · {{ $a->starter?->name }}</p></td>
                        <td class="px-4 py-3 text-gray-600">{{ $a->scopeLabel() }} · {{ number_format($a->expected_count) }} copies</td>
                        <td class="px-4 py-3 text-right">{{ number_format($a->found_count) }}</td>
                        <td class="px-4 py-3 text-right {{ $a->status === 'completed' && $a->missing_count ? 'font-semibold text-red-600' : 'text-gray-500' }}">{{ $a->status === 'completed' ? number_format($a->missing_count) : '—' }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($a->unexpected_count) }}</td>
                        <td class="px-4 py-3"><x-ui.badge :color="match ($a->status) { 'open' => 'blue', 'completed' => 'green', default => 'gray' }">{{ ucfirst($a->status) }}</x-ui.badge></td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('app.books.audits.show', ['library' => $slug, 'audit' => $a->id]) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-800">{{ $a->isOpen() ? 'Continue' : 'View' }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-400">No stock-takes yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showStart)
        <x-ui.modal title="Start a stock-take" max="max-w-md" wire:key="start-modal">
            <form wire:submit="start" class="space-y-4">
                <x-ui.input name="name" label="Name" required wire:model="name" />
                <x-ui.select name="scopeType" label="What will you count?" wire:model.live="scopeType">
                    <option value="all">The whole library</option><option value="category">One category</option><option value="shelf">One shelf</option>
                </x-ui.select>
                @if ($scopeType === 'category')
                    <x-ui.select name="scopeCategory" label="Category" required wire:model="scopeCategory"><option value="">Choose…</option>@foreach ($this->categories as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach</x-ui.select>
                @elseif ($scopeType === 'shelf')
                    <x-ui.select name="scopeShelf" label="Shelf" required wire:model="scopeShelf"><option value="">Choose…</option>@foreach ($this->shelves as $s) <option value="{{ $s }}">{{ $s }}</option> @endforeach</x-ui.select>
                @endif
                <p class="text-xs text-gray-500">Books that are out on loan, lost or written off are not expected on the shelf, so they are not on the list to count.</p>
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Start counting</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
