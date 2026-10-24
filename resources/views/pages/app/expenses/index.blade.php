<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Support\Tenancy;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    public bool $showModal = false;
    public ?int $editingId = null;
    public string $categoryId = '';
    public string $description = '';
    public string $amount = '';
    public string $expenseDate = '';
    public string $method = 'cash';
    public string $vendor = '';
    public $attachment = null;
    public ?string $existingAttachment = null;
    public string $newCategory = '';

    public function mount(): void
    {
        $this->authorizePermission('expenses.view');
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'category', 'from', 'until'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function categories()
    {
        return ExpenseCategory::orderBy('name')->get();
    }

    protected function filtered()
    {
        return Expense::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('description', 'like', "%{$this->search}%")->orWhere('vendor', 'like', "%{$this->search}%")))
            ->when($this->category !== '', fn ($q) => $q->where('expense_category_id', $this->category))
            ->when($this->from !== '', fn ($q) => $q->whereDate('expense_date', '>=', $this->from))
            ->when($this->until !== '', fn ($q) => $q->whereDate('expense_date', '<=', $this->until));
    }

    #[Computed]
    public function expenses()
    {
        return $this->filtered()->with(['category', 'recorder'])->latest('expense_date')->latest('id')->paginate(15);
    }

    #[Computed]
    public function filteredTotal(): string
    {
        return number_format((float) $this->filtered()->sum('amount'), 2);
    }

    #[Computed]
    public function monthTotal(): string
    {
        return number_format((float) Expense::whereBetween('expense_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('amount'), 2);
    }

    public function create(): void
    {
        $this->authorizePermission('expenses.manage');
        $this->reset(['editingId', 'categoryId', 'description', 'amount', 'vendor', 'attachment', 'existingAttachment', 'newCategory']);
        $this->expenseDate = today()->toDateString();
        $this->method = 'cash';
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizePermission('expenses.manage');
        $e = Expense::findOrFail($id);

        $this->editingId = $e->id;
        $this->fill([
            'categoryId' => (string) $e->expense_category_id, 'description' => $e->description, 'amount' => (string) $e->amount,
            'expenseDate' => $e->expense_date->toDateString(), 'method' => $e->method, 'vendor' => (string) $e->vendor,
        ]);
        $this->existingAttachment = $e->attachment_path;
        $this->attachment = null;
        $this->newCategory = '';
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function addCategory(): void
    {
        $this->authorizePermission('expenses.manage');
        $this->validate([
            'newCategory' => ['required', 'string', 'max:100', Rule::unique('expense_categories', 'name')->where('library_id', Tenancy::currentId())],
        ], [], ['newCategory' => 'category name']);

        $c = ExpenseCategory::create(['name' => $this->newCategory]);
        $this->categoryId = (string) $c->id;
        $this->newCategory = '';
        unset($this->categories);
    }

    public function save(): void
    {
        $this->authorizePermission('expenses.manage');

        $data = $this->validate([
            // the category must belong to this library
            'categoryId' => ['required', Rule::exists('expense_categories', 'id')->where('library_id', Tenancy::currentId())],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'expenseDate' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'in:'.implode(',', array_keys(Payment::METHODS))],
            'vendor' => ['nullable', 'string', 'max:255'],
            // receipts are private files: images or PDF only, 4 MB max
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ], [], ['categoryId' => 'category', 'expenseDate' => 'date']);

        $payload = [
            'expense_category_id' => $data['categoryId'], 'description' => $data['description'], 'amount' => $data['amount'],
            'expense_date' => $data['expenseDate'], 'method' => $data['method'], 'vendor' => $data['vendor'] ?: null,
        ];

        $expense = $this->editingId ? Expense::findOrFail($this->editingId) : new Expense;
        $expense->fill($payload);

        if ($this->attachment) {
            $expense->attachment_path = $this->attachment->store('expenses/'.Tenancy::currentId(), 'local');
        }
        if (! $expense->exists) {
            $expense->recorded_by = auth()->id();
        }

        $changed = array_keys($expense->getDirty());
        $isNew = ! $expense->exists;
        $expense->save();

        AuditLog::record($isNew ? 'expense.recorded' : 'expense.updated', $expense, $isNew
            ? ['amount' => (string) $expense->amount, 'category' => $expense->expense_category_id]
            : ['changed' => $changed]);

        $this->showModal = false;
        unset($this->expenses, $this->filteredTotal, $this->monthTotal);
        $this->dispatch('notify', message: 'Expense saved.');
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Expenses" subtitle="Money spent running the library" /></x-slot:header>

    @php $slug = \App\Support\Tenancy::slug(); @endphp

    <div class="grid gap-4 sm:grid-cols-2">
        <x-ui.stat label="Spent this month" :value="'₹'.$this->monthTotal" icon="receipt" tone="orange" />
        <x-ui.stat label="Total for current filters" :value="'₹'.$this->filteredTotal" icon="cash" tone="blue" />
    </div>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="flex flex-wrap items-end gap-3">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Description or vendor…" class="w-56 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <select wire:model.live="category" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">All categories</option>
                @foreach ($this->categories as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
            </select>
            <label class="text-xs text-gray-500">From <input type="date" wire:model.live="from" class="ml-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></label>
            <label class="text-xs text-gray-500">Until <input type="date" wire:model.live="until" class="ml-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></label>
        </div>
        @if ($this->can('expenses.manage'))
            <x-ui.button wire:click="create"><x-lm.icon name="plus" class="h-4 w-4" /> Add expense</x-ui.button>
        @endif
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Date</th><th class="px-4 py-3 font-medium">Description</th><th class="px-4 py-3 font-medium">Category</th><th class="px-4 py-3 font-medium">Vendor</th><th class="px-4 py-3 font-medium">Method</th><th class="px-4 py-3 text-right font-medium">Amount</th><th class="px-4 py-3 font-medium">By</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->expenses as $e)
                        <tr wire:key="exp-{{ $e->id }}">
                            <td class="px-4 py-3 text-gray-600">{{ $e->expense_date->format('d M Y') }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $e->description }}</td>
                            <td class="px-4 py-3"><x-ui.badge>{{ $e->category->name }}</x-ui.badge></td>
                            <td class="px-4 py-3 text-gray-600">{{ $e->vendor ?: '—' }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ \App\Models\Payment::METHODS[$e->method] ?? $e->method }}</td>
                            <td class="px-4 py-3 text-right font-semibold">₹{{ number_format((float) $e->amount, 2) }}</td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ $e->recorder?->name ?? '—' }}</td>
                            <td class="space-x-3 px-4 py-3 text-right">
                                @if ($e->attachment_path) <a href="{{ route('app.expenses.attachment', ['library' => $slug, 'expense' => $e->id]) }}" target="_blank" class="font-medium text-brand-600 hover:text-brand-800">Receipt</a> @endif
                                @if ($this->can('expenses.manage')) <button wire:click="edit({{ $e->id }})" class="font-medium text-gray-500 hover:text-gray-800">Edit</button> @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">No expenses recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->expenses->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->expenses->links() }}</div> @endif
    </div>

    @if ($showModal)
        <x-ui.modal :title="$editingId ? 'Edit expense' : 'Add expense'" max="max-w-xl" wire:key="exp-modal">
            <form wire:submit="save" class="space-y-4">
                <div>
                    <x-ui.select name="categoryId" label="Category" required wire:model="categoryId">
                        <option value="">Choose…</option>
                        @foreach ($this->categories as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
                    </x-ui.select>
                    <div class="mt-2 flex items-start gap-2">
                        <div class="flex-1"><input wire:model="newCategory" type="text" placeholder="…or add a new category" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">@error('newCategory') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror</div>
                        <x-ui.button variant="secondary" wire:click="addCategory">Add</x-ui.button>
                    </div>
                </div>
                <x-ui.input name="description" label="Description" required wire:model="description" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="amount" label="Amount (₹)" type="number" step="0.01" min="0" required wire:model="amount" />
                    <x-ui.input name="expenseDate" label="Date" type="date" required wire:model="expenseDate" />
                    <x-ui.select name="method" label="Paid by" wire:model="method">@foreach (\App\Models\Payment::METHODS as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach</x-ui.select>
                    <x-ui.input name="vendor" label="Vendor / recipient" wire:model="vendor" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Receipt (JPG, PNG or PDF, up to 4 MB)</label>
                    <input type="file" wire:model="attachment" accept=".jpg,.jpeg,.png,.pdf" class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700">
                    @error('attachment') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    @if ($existingAttachment && ! $attachment) <p class="mt-1 text-xs text-gray-500">A receipt is already attached; choosing a file replaces it.</p> @endif
                </div>
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit" wire:target="save,attachment">Save expense</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
