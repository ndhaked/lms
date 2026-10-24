<?php

use App\Actions\Books\ImportBooks;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\AuditLog;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithFileUploads;

    public $file = null;
    public string $onDuplicate = 'skip';
    public array $preview = [];       // first rows of the check, for display (the file itself is re-read on import)
    public array $counts = ['new' => 0, 'duplicate' => 0, 'error' => 0];
    public int $total = 0;
    public ?array $result = null;

    public function mount(): void
    {
        $this->authorizePermission('books.import');
    }

    public function updatedFile(): void
    {
        $this->authorizePermission('books.import');
        $this->reset('preview', 'counts', 'total', 'result');
        $this->validate(['file' => ['required', 'file', 'max:10240']], [], ['file' => 'file']);

        $ext = strtolower($this->file->getClientOriginalExtension());
        try {
            $all = app(ImportBooks::class)->preview(app(ImportBooks::class)->read($this->file->getRealPath(), $ext));
            $c = collect($all)->countBy('status');
            $this->counts = ['new' => $c['new'] ?? 0, 'duplicate' => $c['duplicate'] ?? 0, 'error' => $c['error'] ?? 0];
            $this->total = count($all);
            $this->preview = array_slice($all, 0, 300);
        } catch (BusinessRuleException $e) {
            $this->addError('file', $e->getMessage());
        } catch (\Throwable) {
            $this->addError('file', 'This file could not be read. Use the template: a .csv or .xlsx with a heading row.');
        }
    }

    public function runImport(): void
    {
        $this->authorizePermission('books.import');
        abort_unless($this->file, 422);
        $this->validate(['onDuplicate' => ['in:skip,add_copies']]);

        $rows = app(ImportBooks::class)->read($this->file->getRealPath(), strtolower($this->file->getClientOriginalExtension()));
        $this->result = app(ImportBooks::class)->import($rows, $this->onDuplicate);
        AuditLog::record('books.imported', null, ['created' => $this->result['created'], 'copies' => $this->result['copies'], 'skipped' => $this->result['skipped'], 'errors' => count($this->result['errors'])]);

        $this->reset('file', 'preview', 'counts', 'total');
        $this->dispatch('notify', message: "Imported {$this->result['created']} titles and {$this->result['copies']} copies.");
    }

    public function template()
    {
        $this->authorizePermission('books.import');

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ImportBooks::COLUMNS);
            fputcsv($out, ['Book', 'The Alchemist', '9780062315007', 'Paulo Coelho', 'HarperOne', '1st', 'English', '1993', 'Fiction', 'Novels', 'classic, journey', 'A shepherd boy travels in search of treasure.', 'A', '2', 'Fiction', 'Reading Room', '3', '', '350', 'Local Books', '2026-04-01', '']);
            fputcsv($out, ['E-book', 'Python Basics', '', 'A. Author', '', '', 'English', '2022', 'Computers', '', 'python', '', '', '', '', '', '', '', '', '', '', 'https://example.com/python-basics.pdf']);
            fclose($out);
        }, 'books-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
?>

<div class="max-w-5xl space-y-5">
    <x-slot:header><x-ui.page-title title="Import books" subtitle="From an Excel or CSV sheet" /></x-slot:header>

    @php $slug = \App\Support\Tenancy::slug(); $sum = $counts; @endphp

    <a href="{{ route('app.books.index', ['library' => $slug]) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-800">← All books</a>

    <section class="rounded-xl border border-gray-200 bg-white p-5">
        <h3 class="text-sm font-semibold text-gray-900">1. Prepare your sheet</h3>
        <p class="mt-1 text-sm text-gray-600">One row per title. Use the template headings. <strong>copies</strong> is how many copies to create (each gets an accession number). Categories are created if they do not exist. Up to {{ \App\Actions\Books\ImportBooks::MAX_ROWS }} rows at a time.</p>
        <button wire:click="template" class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Download template (CSV)</button>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-5">
        <h3 class="text-sm font-semibold text-gray-900">2. Upload and check</h3>
        <input type="file" wire:model="file" accept=".csv,.xlsx" class="mt-3 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700">
        <div wire:loading wire:target="file" class="mt-2 text-xs text-gray-500">Reading the file…</div>
        @error('file') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror

        @if ($preview)
            <div class="mt-4 flex flex-wrap gap-4 text-sm">
                <span><strong class="text-brand-700">{{ $sum['new'] }}</strong> new</span>
                <span><strong class="text-amber-600">{{ $sum['duplicate'] }}</strong> already in the catalogue</span>
                <span><strong class="{{ $sum['error'] ? 'text-red-600' : 'text-gray-500' }}">{{ $sum['error'] }}</strong> with problems</span>
            </div>
            <div class="mt-3 max-h-96 overflow-auto rounded-lg border border-gray-200">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-gray-50 text-left text-gray-500"><tr><th class="px-3 py-2 font-medium">Row</th><th class="px-3 py-2 font-medium">Title</th><th class="px-3 py-2 font-medium">ISBN</th><th class="px-3 py-2 font-medium">Result</th></tr></thead>
                    <tbody>
                        @foreach ($preview as $p)
                            <tr class="border-t border-gray-100" wire:key="pv-{{ $p['line'] }}">
                                <td class="px-3 py-1.5 text-gray-400">{{ $p['line'] }}</td><td class="px-3 py-1.5 text-gray-800">{{ $p['row']['title'] ?? '' }}</td><td class="px-3 py-1.5 text-gray-500">{{ $p['row']['isbn'] ?? '' }}</td>
                                <td class="px-3 py-1.5 {{ $p['status'] === 'error' ? 'text-red-600' : ($p['status'] === 'duplicate' ? 'text-amber-600' : 'text-brand-700') }}">{{ $p['message'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($total > 300) <p class="mt-1 text-xs text-gray-400">Showing the first 300 rows of {{ $total }}. All rows are imported.</p> @endif

            <div class="mt-4 space-y-3">
                <x-ui.select name="onDuplicate" label="If a title is already in the catalogue" wire:model="onDuplicate" class="max-w-md">
                    <option value="skip">Skip it</option>
                    <option value="add_copies">Add the row's copies to the existing title</option>
                </x-ui.select>
                <x-ui.button wire:click="runImport" wire:confirm="Import {{ $sum['new'] + ($onDuplicate === 'add_copies' ? $sum['duplicate'] : 0) }} rows now? Rows with problems are skipped.">Import</x-ui.button>
            </div>
        @endif
    </section>

    @if ($result)
        <section class="rounded-xl border border-brand-200 bg-brand-50/60 p-5 text-sm">
            <h3 class="font-semibold text-gray-900">Import finished</h3>
            <p class="mt-1 text-gray-700"><strong>{{ $result['created'] }}</strong> titles created · <strong>{{ $result['copies'] }}</strong> copies added · {{ $result['skipped'] }} skipped · <strong class="{{ $result['errors'] ? 'text-red-600' : '' }}">{{ count($result['errors']) }}</strong> rows failed</p>
            @if ($result['errors'])
                <ul class="mt-2 list-disc space-y-0.5 pl-5 text-xs text-red-700">@foreach ($result['errors'] as $e) <li>Row {{ $e['line'] }}: {{ $e['message'] }}</li> @endforeach</ul>
            @endif
        </section>
    @endif
</div>
