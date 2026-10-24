<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $category = '';

    public function mount(): void
    {
        abort_unless($this->can('ebooks.view') || ($this->can('ebooks.read') && $this->can('self.view')), 403);
    }

    public function updated(string $name): void
    {
        $this->resetPage();
    }

    protected function isStaff(): bool
    {
        return $this->can('ebooks.view');
    }

    /** A student may read only while their membership runs, if the library requires it. */
    #[Computed]
    public function blockedReason(): ?string
    {
        if ($this->isStaff()) {
            return null;
        }

        $student = Student::where('user_id', auth()->id())->first();
        if (! $student) {
            return 'No student record is linked to your login.';
        }
        if (LibrarySettings::get('ebook_require_membership') && ! StudentMembership::current()->where('student_id', $student->id)->exists()) {
            return 'You need a running membership to read e-books. Ask the library to renew yours.';
        }

        return null;
    }

    #[Computed]
    public function categories()
    {
        return BookCategory::with('parent')->whereHas('books', fn ($q) => $q->where('type', Book::EBOOK)->where('status', 'active'))->get()->sortBy(fn ($c) => $c->fullName())->values();
    }

    #[Computed]
    public function rows()
    {
        return Book::where('type', Book::EBOOK)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('licence_expires_on')->orWhereDate('licence_expires_on', '>=', today()))
            ->where(fn ($q) => $q->whereNotNull('ebook_path')->orWhereNotNull('external_url'))
            ->with('category.parent')
            ->search($this->search)
            ->when($this->category !== '', fn ($q) => $q->where('category_id', (int) $this->category))
            ->orderBy('title')->paginate(12);
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title title="E-Books" subtitle="Digital books you can read online" /></x-slot:header>

    @php $slug = \App\Support\Tenancy::slug(); @endphp

    @if ($this->blockedReason)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">{{ $this->blockedReason }}</div>
    @else
        <div class="flex flex-wrap gap-3">
            <div class="relative min-w-64 flex-1">
                <x-lm.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search e-books by title, author or tag…" class="w-full rounded-lg border-gray-300 py-2 pl-9 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            </div>
            <select wire:model.live="category" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">All categories</option>
                @foreach ($this->categories as $c) <option value="{{ $c->id }}">{{ $c->fullName() }}</option> @endforeach
            </select>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($this->rows as $b)
                <div class="flex gap-4 rounded-xl border border-gray-200 bg-white p-4" wire:key="eb-{{ $b->id }}">
                    @if ($b->cover_path)
                        <img src="{{ route('app.books.cover', ['library' => $slug, 'book' => $b->id]) }}" alt="" loading="lazy" class="h-28 w-20 shrink-0 rounded-md object-cover shadow-sm">
                    @else
                        <span class="flex h-28 w-20 shrink-0 items-center justify-center rounded-md bg-brand-50 text-brand-500"><x-lm.icon name="tablet" class="h-8 w-8" /></span>
                    @endif
                    <div class="flex min-w-0 flex-1 flex-col">
                        <p class="line-clamp-2 font-medium text-gray-900">{{ $b->title }}</p>
                        <p class="truncate text-xs text-gray-500">{{ $b->author ?? '' }}</p>
                        <p class="mt-0.5 text-xs text-gray-400">{{ $b->category?->fullName() }}</p>
                        <div class="mt-auto flex gap-3 pt-2 text-sm">
                            <a href="{{ route('app.ebooks.open', ['library' => $slug, 'book' => $b->id, 'action' => 'view']) }}" target="_blank" class="font-semibold text-brand-600 hover:text-brand-800">Read</a>
                            @if ($b->hasFile() && $b->allow_download)
                                <a href="{{ route('app.ebooks.open', ['library' => $slug, 'book' => $b->id, 'action' => 'download']) }}" class="font-medium text-gray-600 hover:text-gray-900">Download</a>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="col-span-full rounded-xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-400">{{ $search !== '' ? 'No e-books match your search.' : 'No e-books available yet.' }}</p>
            @endforelse
        </div>

        {{ $this->rows->links() }}
    @endif
</div>
