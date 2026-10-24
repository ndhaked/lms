<?php

use App\Actions\Books\SaveBook;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\AuditLog;
use App\Models\BookCategory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $parentId = '';

    public function mount(): void
    {
        $this->authorizePermission('books.manage');
    }

    #[Computed]
    public function tree()
    {
        $all = BookCategory::withCount('books')->orderBy('name')->get();

        return $all->whereNull('parent_id')->map(fn ($c) => $c->setRelation('kids', $all->where('parent_id', $c->id)->values()))->values();
    }

    #[Computed]
    public function parents()
    {
        return BookCategory::whereNull('parent_id')->orderBy('name')->get();
    }

    public function create(?int $parent = null): void
    {
        $this->authorizePermission('books.manage');
        $this->reset('editingId', 'name');
        $this->parentId = $parent ? (string) $parent : '';
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorizePermission('books.manage');
        $c = BookCategory::findOrFail($id);   // tenant-scoped
        $this->editingId = $c->id;
        $this->name = $c->name;
        $this->parentId = (string) ($c->parent_id ?? '');
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizePermission('books.manage');
        $this->validate(['name' => ['required', 'string', 'max:100'], 'parentId' => ['nullable', 'integer']]);

        $parent = $this->parentId !== '' ? BookCategory::whereNull('parent_id')->find((int) $this->parentId) : null;   // one level of subcategories
        if ($this->parentId !== '' && ! $parent) {
            $this->addError('parentId', 'Choose a main category.');

            return;
        }
        if ($this->editingId && $parent && $parent->id === $this->editingId) {
            $this->addError('parentId', 'A category cannot sit under itself.');

            return;
        }

        $name = trim($this->name);
        $clash = BookCategory::where('parent_id', $parent?->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->exists();
        if ($clash) {
            $this->addError('name', 'That name is already used here.');

            return;
        }

        if ($this->editingId) {
            $c = BookCategory::findOrFail($this->editingId);
            if ($parent && $c->children()->exists()) {
                $this->addError('parentId', 'This category has subcategories, so it cannot become one.');

                return;
            }
            $c->forceFill(['name' => $name, 'parent_id' => $parent?->id])->save();
        } else {
            $c = BookCategory::create(['name' => $name, 'parent_id' => $parent?->id]);
        }

        AuditLog::record($this->editingId ? 'book_category.updated' : 'book_category.created', $c, ['name' => $name]);
        $this->showForm = false;
        unset($this->tree, $this->parents);
        $this->dispatch('notify', message: 'Saved.');
    }

    public function delete(int $id): void
    {
        $this->authorizePermission('books.manage');
        $c = BookCategory::withCount('books')->findOrFail($id);

        if ($c->books_count > 0 || $c->children()->exists()) {
            $this->dispatch('notify', type: 'error', message: 'This category has books or subcategories. Move them first.');

            return;
        }

        AuditLog::record('book_category.deleted', null, ['name' => $c->name]);
        $c->delete();
        unset($this->tree, $this->parents);
        $this->dispatch('notify', message: 'Deleted.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
    }
}
?>

<div class="max-w-3xl space-y-5">
    <x-slot:header><x-ui.page-title title="Book categories" subtitle="Categories and subcategories" /></x-slot:header>

    @php $slug = \App\Support\Tenancy::slug(); @endphp

    <div class="flex items-center justify-between">
        <a href="{{ route('app.books.index', ['library' => $slug]) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-800">← All books</a>
        <x-ui.button wire:click="create()"><x-lm.icon name="plus" class="h-4 w-4" /> Add category</x-ui.button>
    </div>

    <div class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
        @forelse ($this->tree as $c)
            <div class="p-4" wire:key="cat-{{ $c->id }}">
                <div class="flex items-center justify-between gap-3">
                    <p class="font-medium text-gray-900">{{ $c->name }} <span class="text-xs font-normal text-gray-400">· {{ $c->books_count }} book{{ $c->books_count === 1 ? '' : 's' }}</span></p>
                    <div class="flex gap-3 text-sm">
                        <button wire:click="create({{ $c->id }})" class="font-medium text-brand-600 hover:text-brand-800">Add subcategory</button>
                        <button wire:click="edit({{ $c->id }})" class="font-medium text-gray-600 hover:text-gray-900">Rename</button>
                        <button wire:click="delete({{ $c->id }})" wire:confirm="Delete “{{ $c->name }}”?" class="font-medium text-red-600 hover:text-red-800">Delete</button>
                    </div>
                </div>
                @if ($c->kids->isNotEmpty())
                    <ul class="mt-3 space-y-1.5 border-l-2 border-gray-100 pl-4">
                        @foreach ($c->kids as $k)
                            <li class="flex items-center justify-between gap-3 text-sm" wire:key="sub-{{ $k->id }}">
                                <span class="text-gray-700">{{ $k->name }} <span class="text-xs text-gray-400">· {{ $k->books_count }}</span></span>
                                <span class="flex gap-3"><button wire:click="edit({{ $k->id }})" class="text-gray-600 hover:text-gray-900">Rename</button><button wire:click="delete({{ $k->id }})" wire:confirm="Delete “{{ $k->name }}”?" class="text-red-600 hover:text-red-800">Delete</button></span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @empty
            <p class="p-10 text-center text-sm text-gray-400">No categories yet. Add one, or they are created for you when you import a sheet.</p>
        @endforelse
    </div>

    @if ($showForm)
        <x-ui.modal :title="$editingId ? 'Edit category' : 'Add category'" max="max-w-md" wire:key="cat-modal">
            <form wire:submit="save" class="space-y-4">
                <x-ui.input name="name" label="Name" required wire:model="name" />
                <x-ui.select name="parentId" label="Under" wire:model="parentId">
                    <option value="">Main category</option>
                    @foreach ($this->parents as $p) @if ($p->id !== $editingId) <option value="{{ $p->id }}">{{ $p->name }}</option> @endif @endforeach
                </x-ui.select>
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Save</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
