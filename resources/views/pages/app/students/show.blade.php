<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Models\Student;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions;

    public int $studentId;

    public function mount(string $student): void
    {
        $this->authorizePermission('students.view');
        $this->studentId = Student::findOrFail($student)->id; // tenant-scoped
    }

    public function createLogin(): void
    {
        $this->authorizePermission('students.update');

        try {
            $user = app(\App\Actions\CreateStudentLogin::class)(Student::findOrFail($this->studentId));
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        unset($this->student);
        $this->dispatch('notify', message: $user->wasRecentlyCreated ? "Login created. A setup email was sent to {$user->email}." : 'Login linked to the existing account.');
    }

    #[Computed]
    public function student(): Student
    {
        return Student::with(['memberships.plan', 'activeAllocation.seat.hall', 'allocations.seat'])->findOrFail($this->studentId);
    }
}
?>

<div class="space-y-5">
    @php
        $s = $this->student;
        $current = $s->currentMembership();
        $slug = \App\Support\Tenancy::slug();
        $stateColor = fn ($st) => match ($st) { 'active' => 'green', 'upcoming' => 'blue', 'expired' => 'red', 'suspended' => 'amber', default => 'gray' };
    @endphp

    <x-slot:header><x-ui.page-title :title="$s->name" :subtitle="$s->student_code.' · registered '.$s->registered_on->format('d M Y')" /></x-slot:header>

    @if (session('notice'))
        <div class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-700">{{ session('notice') }}</div>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        <x-ui.badge :color="match ($s->status) { 'active' => 'green', 'suspended' => 'amber', default => 'gray' }" class="px-3 py-1 text-sm">{{ ucfirst($s->status) }}</x-ui.badge>
        @if ($this->can('students.update'))
            <a href="{{ route('app.students.edit', ['library' => $slug, 'student' => $s->id]) }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"><x-lm.icon name="pencil" class="h-4 w-4" /> Edit</a>
        @endif
        @if ($this->can('memberships.manage'))
            <a href="{{ route('app.memberships.index', ['library' => $slug, 'assign' => $s->id]) }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700"><x-lm.icon name="badge" class="h-4 w-4" /> Assign membership</a>
        @endif
        @if ($s->user_id)
            <x-ui.badge color="green">Has login</x-ui.badge>
        @elseif ($this->can('students.update') && $s->status === 'active')
            <x-ui.button variant="secondary" wire:click="createLogin" wire:confirm="Invite {{ $s->name }} to sign in? A setup link goes to {{ $s->email ?: 'their email (add one first)' }}.">
                <x-lm.icon name="user-plus" class="h-4 w-4" /> Create login
            </x-ui.button>
        @endif
        <a href="{{ route('app.students.index', ['library' => $slug]) }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-800">Back to students</a>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-xl border border-gray-200 bg-white p-5 lg:col-span-2">
            <h3 class="mb-4 text-sm font-semibold text-gray-900">Details</h3>
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                @foreach (['Phone' => $s->phone, 'Email' => $s->email, 'Address' => $s->address, 'Emergency contact' => trim($s->emergency_contact_name.' '.$s->emergency_contact_phone), 'Notes' => $s->notes] as $label => $value)
                    <div><dt class="text-xs font-medium text-gray-500">{{ $label }}</dt><dd class="mt-1 text-gray-900">{{ $value ?: '—' }}</dd></div>
                @endforeach
            </dl>
        </section>

        <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5">
            <div>
                <h3 class="mb-1 text-xs font-medium text-gray-500">Current membership</h3>
                @if ($current)
                    <p class="text-sm font-semibold text-gray-900">{{ $current->plan->name }}</p>
                    <p class="text-xs text-gray-500">Valid until {{ $current->ends_on->format('d M Y') }}</p>
                @else
                    <p class="text-sm text-gray-400">No active membership</p>
                @endif
            </div>
            <div>
                <h3 class="mb-1 text-xs font-medium text-gray-500">Seat</h3>
                @if ($s->activeAllocation)
                    <p class="text-sm font-semibold text-gray-900">{{ $s->activeAllocation->seat->code }}</p>
                    <p class="text-xs text-gray-500">{{ $s->activeAllocation->seat->hall?->name }} · since {{ $s->activeAllocation->allocated_at->format('d M Y') }}</p>
                @else
                    <p class="text-sm text-gray-400">Not allocated</p>
                @endif
            </div>
        </section>
    </div>

    <section class="rounded-xl border border-gray-200 bg-white p-5">
        <h3 class="mb-3 text-sm font-semibold text-gray-900">Membership history</h3>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-xs text-gray-500"><th class="pb-2 font-medium">Plan</th><th class="pb-2 font-medium">Starts</th><th class="pb-2 font-medium">Ends</th><th class="pb-2 text-right font-medium">Price</th><th class="pb-2 text-right font-medium">Discount</th><th class="pb-2 font-medium pl-4">Status</th></tr></thead>
            <tbody>
                @forelse ($s->memberships->sortByDesc('starts_on') as $m)
                    <tr class="border-t border-gray-100" wire:key="m-{{ $m->id }}">
                        <td class="py-2 font-medium">{{ $m->plan->name }}</td><td class="py-2">{{ $m->starts_on->format('d M Y') }}</td><td class="py-2">{{ $m->ends_on->format('d M Y') }}</td>
                        <td class="py-2 text-right">₹{{ number_format((float) $m->price, 2) }}</td><td class="py-2 text-right">₹{{ number_format((float) $m->discount, 2) }}</td>
                        <td class="py-2 pl-4"><x-ui.badge :color="$stateColor($m->effectiveStatus())">{{ ucfirst($m->effectiveStatus()) }}</x-ui.badge></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 text-center text-gray-400">No memberships yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-5">
        <h3 class="mb-3 text-sm font-semibold text-gray-900">Seat history</h3>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-xs text-gray-500"><th class="pb-2 font-medium">Seat</th><th class="pb-2 font-medium">Allocated</th><th class="pb-2 font-medium">Released</th><th class="pb-2 font-medium">Reason</th></tr></thead>
            <tbody>
                @forelse ($s->allocations->sortByDesc('allocated_at') as $a)
                    <tr class="border-t border-gray-100" wire:key="al-{{ $a->id }}">
                        <td class="py-2 font-medium">{{ $a->seat->code }}</td><td class="py-2">{{ $a->allocated_at->format('d M Y, H:i') }}</td>
                        <td class="py-2">{{ $a->released_at?->format('d M Y, H:i') ?? 'Current' }}</td><td class="py-2 text-gray-500">{{ $a->release_reason ? str_replace('_', ' ', $a->release_reason) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-400">No seat history.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
