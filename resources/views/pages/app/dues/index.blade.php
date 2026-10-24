<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Models\StudentMembership;
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
    public string $filter = ''; // '' = all | overdue (membership already ended)

    public function mount(): void
    {
        $this->authorizePermission('payments.view');
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'filter'], true)) {
            $this->resetPage();
        }
    }

    protected function base()
    {
        return StudentMembership::owing()
            ->when($this->search !== '', fn ($q) => $q->whereHas('student', fn ($s) => $s
                ->where('name', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%")->orWhere('student_code', 'like', "%{$this->search}%")))
            ->when($this->filter === 'overdue', fn ($q) => $q->whereDate('student_memberships.ends_on', '<', today()));
    }

    #[Computed]
    public function rows()
    {
        return $this->base()->withBalance()->with(['student', 'plan'])->orderBy('student_memberships.ends_on')->paginate(15);
    }

    #[Computed]
    public function total(): string
    {
        return number_format((float) $this->base()->withBalance()->get()->sum('balance'), 2);
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Outstanding dues" subtitle="Memberships that are not fully paid" /></x-slot:header>

    @php $slug = \App\Support\Tenancy::slug(); @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search student…" class="w-60 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <select wire:model.live="filter" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">All dues</option><option value="overdue">Overdue (membership ended)</option>
            </select>
        </div>
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">Total outstanding: <strong>₹{{ $this->total }}</strong></div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Student</th><th class="px-4 py-3 font-medium">Plan</th><th class="px-4 py-3 font-medium">Period</th><th class="px-4 py-3 text-right font-medium">Charge</th><th class="px-4 py-3 text-right font-medium">Paid</th><th class="px-4 py-3 text-right font-medium">Balance</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->rows as $m)
                        <tr wire:key="due-{{ $m->id }}">
                            <td class="px-4 py-3"><a href="{{ route('app.students.show', ['library' => $slug, 'student' => $m->student_id]) }}" wire:navigate class="font-medium text-gray-900 hover:text-brand-700">{{ $m->student->name }}</a><div class="text-xs text-gray-400">{{ $m->student->student_code }} · {{ $m->student->phone }}</div></td>
                            <td class="px-4 py-3 text-gray-700">{{ $m->plan->name }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $m->starts_on->format('d M') }} – {{ $m->ends_on->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-right">₹{{ number_format((float) $m->charge, 2) }}</td>
                            <td class="px-4 py-3 text-right text-gray-600">₹{{ number_format((float) $m->paid, 2) }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-red-600">₹{{ number_format((float) $m->balance, 2) }}</td>
                            <td class="px-4 py-3">
                                @if ($m->ends_on->lt(today())) <x-ui.badge color="red">Overdue</x-ui.badge> @else <x-ui.badge color="amber">Due</x-ui.badge> @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($this->can('payments.record'))
                                    <a href="{{ route('app.payments.index', ['library' => $slug, 'pay' => $m->id]) }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-800">Record payment</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Nothing outstanding. 🎉</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->rows->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->rows->links() }}</div> @endif
    </div>
</div>
