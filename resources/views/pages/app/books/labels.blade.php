<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Book;
use App\Models\BookCopy;
use App\Support\Code128;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions;

    public const MAX_LABELS = 300;

    #[Url]
    public string $mode = 'recent';     // book | range | recent

    #[Url]
    public string $book = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $recent = '30';

    #[Url]
    public string $code = 'barcode';    // barcode | qr | both

    public function mount(): void
    {
        $this->authorizePermission('books.copies');
        if ($this->book !== '') {
            $this->mode = 'book';
        }
    }

    #[Computed]
    public function titles()
    {
        return Book::where('type', Book::PHYSICAL)->whereHas('copies')->orderBy('title')->limit(500)->get(['id', 'title', 'isbn']);
    }

    #[Computed]
    public function copies()
    {
        $q = BookCopy::with('book:id,title')->where('status', '!=', BookCopy::WITHDRAWN);

        return match ($this->mode) {
            'book' => $this->book === '' ? collect() : $q->where('book_id', (int) $this->book)->orderBy('accession_no')->limit(self::MAX_LABELS)->get(),
            'range' => ($this->from === '' && $this->until === '') ? collect() : $q
                ->when($this->from !== '', fn ($x) => $x->where('accession_no', '>=', $this->from))
                ->when($this->until !== '', fn ($x) => $x->where('accession_no', '<=', $this->until))
                ->orderBy('accession_no')->limit(self::MAX_LABELS)->get(),
            default => $q->latest('id')->limit(min(self::MAX_LABELS, max(1, (int) $this->recent)))->get()->sortBy('accession_no')->values(),
        };
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="Barcode labels" subtitle="Print labels for book copies" /></x-slot:header>

    @php $slug = \App\Support\Tenancy::slug(); $copies = $this->copies; @endphp

    <style>
        @media print {
            body * { visibility: hidden; }
            #label-sheet, #label-sheet * { visibility: visible; }
            #label-sheet { position: absolute; left: 0; top: 0; width: 100%; border: 0; padding: 0; }
            .label-cell { break-inside: avoid; }
        }
    </style>

    <div class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 print:hidden">
        <a href="{{ route('app.books.index', ['library' => $slug]) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-800">← All books</a>
        <div class="flex flex-wrap items-end gap-3">
            <x-ui.select name="code" label="Label shows" wire:model.live="code" class="w-44">
                <option value="barcode">Barcode</option><option value="qr">QR code</option><option value="both">Barcode and QR</option>
            </x-ui.select>
            <x-ui.select name="mode" label="Print labels for" wire:model.live="mode" class="w-56">
                <option value="recent">The latest copies added</option><option value="book">One book</option><option value="range">An accession range</option>
            </x-ui.select>
            @if ($mode === 'book')
                <x-ui.select name="book" label="Book" wire:model.live="book" class="w-72">
                    <option value="">Choose…</option>
                    @foreach ($this->titles as $t) <option value="{{ $t->id }}">{{ $t->title }}</option> @endforeach
                </x-ui.select>
            @elseif ($mode === 'range')
                <x-ui.input name="from" label="From accession no." wire:model.live.debounce.400ms="from" class="w-44" />
                <x-ui.input name="until" label="To accession no." wire:model.live.debounce.400ms="until" class="w-44" />
            @else
                <x-ui.input name="recent" label="How many" type="number" min="1" max="{{ self::MAX_LABELS }}" wire:model.live.debounce.400ms="recent" class="w-28" />
            @endif
            <x-ui.button type="button" onclick="window.print()" :disabled="$copies->isEmpty()"><x-lm.icon name="printer" class="h-4 w-4" /> Print {{ $copies->count() ?: '' }} label{{ $copies->count() === 1 ? '' : 's' }}</x-ui.button>
        </div>
        <p class="text-xs text-gray-500">Up to {{ self::MAX_LABELS }} labels at a time. The barcode is the accession number, so any barcode scanner reads it.</p>
    </div>

    <div id="label-sheet" class="rounded-xl border border-gray-200 bg-white p-4">
        @if ($copies->isEmpty())
            <p class="py-10 text-center text-sm text-gray-400">Choose what to print. The labels appear here.</p>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($copies as $c)
                    <div class="label-cell rounded-md border border-gray-300 p-2 text-center" wire:key="lb-{{ $c->id }}">
                        <p class="truncate text-[10px] font-medium text-gray-700">{{ $c->book->title }}</p>
                        <div class="mt-1 flex items-center justify-center gap-2">
                            @if ($code !== 'qr' && \App\Support\Code128::supports($c->barcode))
                                <div class="{{ $code === 'both' ? 'w-3/5' : 'w-full' }}">{!! \App\Support\Code128::svg($c->barcode, 36, 2) !!}</div>
                            @endif
                            @if ($code !== 'barcode')
                                <div class="{{ $code === 'both' ? 'w-2/5' : 'w-20' }} [&>svg]:h-auto [&>svg]:w-full">{!! \App\Support\Qr::svg($c->barcode, 96) !!}</div>
                            @endif
                        </div>
                        <p class="mt-0.5 font-mono text-[11px] font-semibold tracking-wide text-gray-900">{{ $c->accession_no }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
