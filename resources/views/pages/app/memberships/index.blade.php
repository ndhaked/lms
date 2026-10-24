<?php

use App\Actions\AssignMembership;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\AuditLog;
use App\Models\MembershipPlan;
use App\Models\Student;
use App\Models\StudentMembership;
use Illuminate\Support\Carbon;
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
    public string $state = '';

    #[Url]
    public string $planFilter = '';

    // assign
    #[Url]
    public ?string $assign = null; // student id coming from a student profile
    public bool $showAssign = false;
    public string $studentSearch = '';
    public ?int $studentId = null;
    public string $planId = '';
    public string $startsOn = '';
    public string $discount = '0';
    public string $notes = '';

    // edit status
    public ?int $editingId = null;
    public string $editStatus = 'active';
    public string $editNotes = '';

    public function mount(): void
    {
        $this->authorizePermission('memberships.view');

        if ($this->assign && $this->can('memberships.manage') && Student::whereKey($this->assign)->exists()) {
            $this->openAssign((int) $this->assign);
        }
        $this->assign = null;
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'state', 'planFilter'], true)) {
            $this->resetPage();
        }
    }

    // ---- renewal requests from students ----
    public ?int $fulfilling = null;

    #[Computed]
    public function requests()
    {
        return $this->can('memberships.manage')
            ? \App\Models\RenewalRequest::with(['student', 'plan'])->where('status', 'pending')->latest('id')->limit(20)->get()
            : collect();
    }

    public function assignFromRequest(int $id): void
    {
        $this->authorizePermission('memberships.manage');
        $req = \App\Models\RenewalRequest::where('status', 'pending')->findOrFail($id);

        $this->openAssign($req->student_id);
        $this->planId = (string) ($req->plan?->status === 'active' ? $req->membership_plan_id : '');
        $this->fulfilling = $req->id;
    }

    public function dismissRequest(int $id): void
    {
        $this->authorizePermission('memberships.manage');
        $req = \App\Models\RenewalRequest::where('status', 'pending')->findOrFail($id);
        $req->forceFill(['status' => 'dismissed', 'handled_by' => auth()->id(), 'handled_at' => now()])->save();
        unset($this->requests);
        $this->dispatch('notify', message: 'Request dismissed.');
    }

    #[Computed]
    public function plans()
    {
        return MembershipPlan::orderBy('name')->get();
    }

    #[Computed]
    public function memberships()
    {
        return StudentMembership::query()
            ->with(['student', 'plan'])
            ->when($this->search !== '', fn ($q) => $q->whereHas('student', fn ($s) => $s
                ->where('name', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%")->orWhere('student_code', 'like', "%{$this->search}%")))
            ->when($this->planFilter !== '', fn ($q) => $q->where('membership_plan_id', $this->planFilter))
            ->when($this->state === 'active', fn ($q) => $q->current())
            ->when($this->state === 'upcoming', fn ($q) => $q->where('status', 'active')->whereDate('starts_on', '>', today()))
            ->when($this->state === 'expired', fn ($q) => $q->where('status', 'active')->whereDate('ends_on', '<', today()))
            ->when(in_array($this->state, ['suspended', 'cancelled'], true), fn ($q) => $q->where('status', $this->state))
            ->orderByDesc('ends_on')
            ->paginate(15);
    }

    #[Computed]
    public function studentResults()
    {
        return Student::query()
            ->where('status', Student::ACTIVE)
            ->when($this->studentSearch !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->studentSearch}%")
                ->orWhere('phone', 'like', "%{$this->studentSearch}%")
                ->orWhere('student_code', 'like', "%{$this->studentSearch}%")))
            ->orderBy('name')->limit(6)->get(['id', 'name', 'phone', 'student_code']);
    }

    #[Computed]
    public function chosenPlan(): ?MembershipPlan
    {
        return $this->planId !== '' ? MembershipPlan::find($this->planId) : null;
    }

    public function openAssign(?int $studentId = null): void
    {
        $this->authorizePermission('memberships.manage');
        $this->reset(['studentSearch', 'planId', 'discount', 'notes']);
        $this->studentId = $studentId;
        $this->startsOn = today()->toDateString();
        $this->discount = '0';
        $this->resetErrorBag();
        $this->showAssign = true;
    }

    public function saveAssign(): void
    {
        $this->authorizePermission('memberships.manage');
        $this->validate([
            'studentId' => ['required', 'integer'],
            'planId' => ['required', 'integer'],
            'startsOn' => ['required', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['studentId.required' => 'Choose a student.', 'planId.required' => 'Choose a plan.']);

        try {
            app(AssignMembership::class)(
                Student::findOrFail($this->studentId),       // tenant-scoped lookups: foreign ids are a 404
                MembershipPlan::findOrFail($this->planId),
                Carbon::parse($this->startsOn),
                $this->can('memberships.discount') ? ($this->discount ?: 0) : 0,
                $this->notes ?: null,
            );
        } catch (BusinessRuleException $e) {
            $this->addError('planId', $e->getMessage());

            return;
        }

        // Close the student's renewal request, if this assignment answers one.
        if ($this->fulfilling) {
            \App\Models\RenewalRequest::where('id', $this->fulfilling)->where('status', 'pending')
                ->update(['status' => 'fulfilled', 'handled_by' => auth()->id(), 'handled_at' => now()]);
            $this->fulfilling = null;
        }

        $this->showAssign = false;
        unset($this->memberships, $this->requests);
        $this->dispatch('notify', message: 'Membership assigned.');
    }

    public function renew(int $id): void
    {
        $this->authorizePermission('memberships.manage');
        $m = StudentMembership::with(['student', 'plan'])->findOrFail($id);

        try {
            // Library rule: a renewal either continues from the old end date or starts today.
            $start = \App\Support\LibrarySettings::get('renewal_starts_after_expiry') ? max(today(), $m->ends_on->copy()->addDay()) : today();
            app(AssignMembership::class)($m->student, $m->plan, $start);
        } catch (BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        unset($this->memberships);
        $this->dispatch('notify', message: 'Membership renewed.');
    }

    public function edit(int $id): void
    {
        $this->authorizePermission('memberships.manage');
        $m = StudentMembership::findOrFail($id);
        $this->editingId = $m->id;
        $this->editStatus = $m->status;
        $this->editNotes = (string) $m->notes;
    }

    public function saveEdit(): void
    {
        $this->authorizePermission('memberships.manage');
        $data = $this->validate(['editStatus' => ['required', 'in:active,suspended,cancelled'], 'editNotes' => ['nullable', 'string', 'max:1000']]);

        // Dates and price are fixed once sold; only status and notes can change here.
        $m = StudentMembership::findOrFail($this->editingId);
        $from = $m->status;
        $m->update(['status' => $data['editStatus'], 'notes' => $data['editNotes'] ?: null]);
        if ($from !== $m->status) {
            AuditLog::record('membership.status_changed', $m, ['from' => $from, 'to' => $m->status]);
        }

        $this->editingId = null;
        unset($this->memberships);
        $this->dispatch('notify', message: 'Membership updated.');
    }

    public function closeModal(): void
    {
        $this->showAssign = false;
        $this->editingId = null;
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Memberships" subtitle="Plans sold to students" /></x-slot:header>

    @php $stateColor = fn ($s) => match ($s) { 'active' => 'green', 'upcoming' => 'blue', 'expired' => 'red', 'suspended' => 'amber', default => 'gray' }; @endphp

    @if ($this->requests->isNotEmpty())
        <section class="rounded-xl border border-blue-200 bg-blue-50/60 p-4">
            <h3 class="mb-2 text-sm font-semibold text-blue-900">Renewal requests from students ({{ $this->requests->count() }})</h3>
            <ul class="divide-y divide-blue-100 text-sm">
                @foreach ($this->requests as $r)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="rq-{{ $r->id }}">
                        <span><span class="font-medium text-gray-900">{{ $r->student->name }}</span> <span class="text-xs text-gray-500">{{ $r->student->student_code }} · {{ $r->created_at->diffForHumans() }}</span>
                            @if ($r->plan) <span class="ml-1 text-gray-700">wants {{ $r->plan->name }}</span> @endif
                            @if ($r->message) <span class="block text-xs text-gray-600">“{{ $r->message }}”</span> @endif</span>
                        <span class="space-x-3"><button wire:click="assignFromRequest({{ $r->id }})" class="font-medium text-brand-700 hover:text-brand-900">Assign membership</button><button wire:click="dismissRequest({{ $r->id }})" class="text-gray-500 hover:text-gray-800">Dismiss</button></span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search student…" class="w-56 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <select wire:model.live="state" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">Any status</option>
                @foreach (['active' => 'Active', 'upcoming' => 'Upcoming', 'expired' => 'Expired', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'] as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach
            </select>
            <select wire:model.live="planFilter" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">All plans</option>
                @foreach ($this->plans as $p) <option value="{{ $p->id }}">{{ $p->name }}</option> @endforeach
            </select>
        </div>
        @if ($this->can('memberships.manage'))
            <x-ui.button wire:click="openAssign"><x-lm.icon name="plus" class="h-4 w-4" /> Assign membership</x-ui.button>
        @endif
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Student</th><th class="px-4 py-3 font-medium">Plan</th><th class="px-4 py-3 font-medium">Starts</th><th class="px-4 py-3 font-medium">Ends</th><th class="px-4 py-3 text-right font-medium">Price</th><th class="px-4 py-3 text-right font-medium">Discount</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->memberships as $m)
                        <tr wire:key="mem-{{ $m->id }}">
                            <td class="px-4 py-3"><div class="font-medium text-gray-900">{{ $m->student->name }}</div><div class="text-xs text-gray-400">{{ $m->student->student_code }}</div></td>
                            <td class="px-4 py-3 text-gray-700">{{ $m->plan->name }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $m->starts_on->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $m->ends_on->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-right">₹{{ number_format((float) $m->price, 2) }}</td>
                            <td class="px-4 py-3 text-right text-gray-600">₹{{ number_format((float) $m->discount, 2) }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$stateColor($m->effectiveStatus())">{{ ucfirst($m->effectiveStatus()) }}</x-ui.badge></td>
                            <td class="space-x-3 px-4 py-3 text-right">
                                @if ($this->can('memberships.manage'))
                                    @if ($m->status === 'active') <button wire:click="renew({{ $m->id }})" wire:confirm="Renew on the same plan at today's plan price?" class="font-medium text-brand-600 hover:text-brand-800">Renew</button> @endif
                                    <button wire:click="edit({{ $m->id }})" class="font-medium text-gray-500 hover:text-gray-800">Edit</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">No memberships found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->memberships->hasPages()) <div class="border-t border-gray-100 px-4 py-3">{{ $this->memberships->links() }}</div> @endif
    </div>

    @if ($showAssign)
        <x-ui.modal title="Assign membership" max="max-w-xl" wire:key="assign-modal">
            <form wire:submit="saveAssign" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Student <span class="text-red-500">*</span></label>
                    <input wire:model.live.debounce.250ms="studentSearch" type="search" placeholder="Search name, phone or student ID…" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                    <ul class="mt-2 space-y-1">
                        @foreach ($this->studentResults as $st)
                            <li wire:key="sr-{{ $st->id }}">
                                <label class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm {{ $studentId === $st->id ? 'border-brand-400 bg-brand-50' : 'border-gray-200 hover:bg-gray-50' }}">
                                    <input type="radio" wire:model.live="studentId" value="{{ $st->id }}" class="text-brand-600 focus:ring-brand-500">
                                    <span class="flex-1 font-medium">{{ $st->name }}</span><span class="text-xs text-gray-400">{{ $st->student_code }} · {{ $st->phone }}</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    @error('studentId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select name="planId" label="Plan" required wire:model.live="planId">
                        <option value="">Choose…</option>
                        @foreach ($this->plans->where('status', 'active') as $p) <option value="{{ $p->id }}">{{ $p->name }} — ₹{{ number_format((float) $p->price) }}</option> @endforeach
                    </x-ui.select>
                    <x-ui.input name="startsOn" label="Start date" type="date" required wire:model.live="startsOn" />
                </div>

                @if ($this->chosenPlan && $startsOn)
                    <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">Ends on <strong>{{ $this->chosenPlan->endDateFor(\Illuminate\Support\Carbon::parse($startsOn))->format('d M Y') }}</strong> · price ₹{{ number_format((float) $this->chosenPlan->price, 2) }}. Creating a membership does not mark it as paid.</p>
                @endif

                @if ($this->can('memberships.discount'))
                    <x-ui.input name="discount" label="Discount (₹)" type="number" step="0.01" min="0" wire:model="discount" />
                @endif
                <x-ui.textarea name="notes" label="Notes" wire:model="notes" />

                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Assign</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    @if ($editingId)
        <x-ui.modal title="Edit membership" wire:key="edit-modal">
            <form wire:submit="saveEdit" class="space-y-4">
                <x-ui.select name="editStatus" label="Status" wire:model="editStatus"><option value="active">Active</option><option value="suspended">Suspended</option><option value="cancelled">Cancelled</option></x-ui.select>
                <x-ui.textarea name="editNotes" label="Notes" wire:model="editNotes" />
                <p class="text-xs text-gray-500">Dates and price are fixed once a membership is sold.</p>
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Save</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
