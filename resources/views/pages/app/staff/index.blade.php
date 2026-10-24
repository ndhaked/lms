<?php

use App\Actions\SendAccountSetup;
use App\Livewire\Concerns\AuthorizesActions;
use App\Models\AuditLog;
use App\Models\StudyHall;
use App\Models\User;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions;

    public string $search = '';

    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public array $selectedRoles = [];
    public array $selectedHalls = [];

    public ?int $activityFor = null;

    public function mount(): void
    {
        $this->authorizePermission('staff.manage');
    }

    // ---- data ----
    #[Computed]
    public function roles()
    {
        // Roles are stored per library; only this library's roles may be assigned.
        return Role::where('guard_name', 'web')->where('library_id', Tenancy::currentId())->orderBy('name')->get();
    }

    /** Roles an Owner-only role needs the actor to be an Owner to hand out. */
    protected function actorIsOwner(): bool
    {
        return auth()->user()->hasRole(LibraryRoles::OWNER);
    }

    #[Computed]
    public function halls()
    {
        return StudyHall::orderBy('name')->get(['id', 'name', 'supervisor_id']);
    }

    #[Computed]
    public function staff()
    {
        $library = Tenancy::library();

        return $library->users()->with('roles')->orderBy('users.name')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('users.name', 'like', "%{$this->search}%")->orWhere('users.email', 'like', "%{$this->search}%")))
            ->get()
            // students with only the Student role are managed from the student profile
            ->reject(fn ($u) => $u->roles->isNotEmpty() && $u->roles->every(fn ($r) => $r->name === LibraryRoles::STUDENT))
            ->values();
    }

    protected function member(int $id): User
    {
        return Tenancy::library()->users()->where('users.id', $id)->firstOrFail(); // only people of this library
    }

    protected function activeOwners(): int
    {
        return Tenancy::library()->users()->wherePivot('is_active', true)->get()->filter(fn ($u) => $u->hasRole(LibraryRoles::OWNER))->count();
    }

    // ---- add / edit ----
    public function create(): void
    {
        \App\Support\Entitlements::assertWritable();
        $this->authorizePermission('staff.manage');
        $this->reset(['editingId', 'name', 'email', 'phone', 'selectedRoles', 'selectedHalls']);
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizePermission('staff.manage');
        $u = $this->member($id);

        $this->editingId = $u->id;
        $this->fill(['name' => $u->name, 'email' => $u->email, 'phone' => (string) $u->phone,
            'selectedRoles' => $u->roles->pluck('name')->all(),
            'selectedHalls' => StudyHall::where('supervisor_id', $u->id)->pluck('id')->map(fn ($i) => (string) $i)->all()]);
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        \App\Support\Entitlements::assertWritable();
        $this->authorizePermission('staff.manage');

        $roleNames = $this->roles->pluck('name')->all();
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'selectedRoles' => ['array', 'min:1'],
            'selectedRoles.*' => [Rule::in($roleNames)],
            'selectedHalls' => ['array'],
            'selectedHalls.*' => [Rule::exists('study_halls', 'id')->where('library_id', Tenancy::currentId())],
        ], ['selectedRoles.min' => 'Choose at least one role.'], ['selectedRoles' => 'roles']);

        // Only an owner may hand out (or take away) the Owner role.
        $touchesOwner = in_array(LibraryRoles::OWNER, $this->selectedRoles, true);
        if ($this->editingId) {
            $touchesOwner = $touchesOwner || $this->member($this->editingId)->hasRole(LibraryRoles::OWNER);
        }
        if ($touchesOwner && ! $this->actorIsOwner()) {
            $this->addError('selectedRoles', 'Only a library owner can give or change the Owner role.');

            return;
        }

        if ($this->editingId) {
            $user = $this->member($this->editingId);
            $removingOwner = $user->hasRole(LibraryRoles::OWNER) && ! in_array(LibraryRoles::OWNER, $this->selectedRoles, true);
            if ($removingOwner && $user->pivot->is_active && $this->activeOwners() <= 1) {
                $this->addError('selectedRoles', 'A library must keep at least one active owner.');

                return;
            }
            $user->update(['name' => $this->name, 'phone' => $this->phone ?: null]);
            $isNew = false;
        } else {
            $existing = User::where('email', $this->email)->first();
            if ($existing && Tenancy::library()->users()->where('users.id', $existing->id)->exists()) {
                $this->addError('email', 'This person already has access to the library.');

                return;
            }
            try {
                \App\Support\Entitlements::assertCanAdd('staff');   // plan limit
            } catch (\App\Exceptions\BusinessRuleException $e) {
                $this->addError('email', $e->getMessage());

                return;
            }

            // An existing login keeps its own password; a new one gets a setup link instead of a shared password.
            $user = $existing ?? User::create(['name' => $this->name, 'email' => $this->email, 'phone' => $this->phone ?: null, 'password' => Str::random(40)]);
            $isNew = ! $existing;
            Tenancy::library()->users()->attach($user->id, ['is_active' => true]);
        }

        DB::transaction(function () use ($user) {
            $before = $user->roles()->pluck('name')->all();
            $user->syncRoles($this->selectedRoles);

            // Hall supervision: this person supervises exactly the ticked halls.
            StudyHall::where('supervisor_id', $user->id)->whereNotIn('id', $this->selectedHalls)->update(['supervisor_id' => null]);
            StudyHall::whereIn('id', $this->selectedHalls)->update(['supervisor_id' => $user->id]);

            AuditLog::record($this->editingId ? 'staff.updated' : 'staff.added', $user, ['roles_before' => $before, 'roles_after' => $this->selectedRoles]);
        });

        if ($isNew) {
            app(SendAccountSetup::class)($user);
        }

        $this->showModal = false;
        unset($this->staff, $this->halls);
        $this->dispatch('notify', message: $isNew ? "Added. A setup email was sent to {$user->email}." : 'Saved.');
    }

    // ---- access ----
    public function toggleAccess(int $id): void
    {
        \App\Support\Entitlements::assertWritable();
        $this->authorizePermission('staff.manage');
        $u = $this->member($id);

        if ($u->id === auth()->id()) {
            $this->dispatch('notify', type: 'error', message: 'You cannot disable your own access.');

            return;
        }

        $enable = ! $u->pivot->is_active;
        if (! $enable && $u->hasRole(LibraryRoles::OWNER) && $this->activeOwners() <= 1) {
            $this->dispatch('notify', type: 'error', message: 'A library must keep at least one active owner.');

            return;
        }
        if (! $enable && $u->hasRole(LibraryRoles::OWNER) && ! $this->actorIsOwner()) {
            $this->dispatch('notify', type: 'error', message: 'Only a library owner can disable an owner.');

            return;
        }

        Tenancy::library()->users()->updateExistingPivot($u->id, ['is_active' => $enable]);
        AuditLog::record($enable ? 'staff.enabled' : 'staff.disabled', $u);

        unset($this->staff);
        $this->dispatch('notify', message: $enable ? 'Access restored.' : 'Access disabled. They are signed out of this library.');
    }

    public function resetAccess(int $id): void
    {
        \App\Support\Entitlements::assertWritable();
        $this->authorizePermission('staff.manage');
        $u = $this->member($id);

        app(SendAccountSetup::class)($u);
        AuditLog::record('staff.access_reset_sent', $u, ['to' => $u->email]);
        $this->dispatch('notify', message: "A password link was sent to {$u->email}.");
    }

    // ---- activity ----
    #[Computed]
    public function activity()
    {
        return $this->activityFor
            ? AuditLog::where('library_id', Tenancy::currentId())->where('user_id', $this->activityFor)->latest('created_at')->latest('id')->limit(25)->get()
            : collect();
    }

    public function showActivity(int $id): void
    {
        $this->authorizePermission('staff.manage');
        $this->member($id);
        $this->activityFor = $id;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->activityFor = null;
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Staff" subtitle="People who work at this library" /></x-slot:header>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name or email…" class="w-64 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
        <x-ui.button wire:click="create"><x-lm.icon name="plus" class="h-4 w-4" /> Add staff</x-ui.button>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Name</th><th class="px-4 py-3 font-medium">Roles</th><th class="px-4 py-3 font-medium">Halls supervised</th><th class="px-4 py-3 font-medium">Access</th><th class="px-4 py-3 text-right font-medium">Actions</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->staff as $u)
                        @php $halls = $this->halls->where('supervisor_id', $u->id)->pluck('name'); @endphp
                        <tr wire:key="staff-{{ $u->id }}" class="{{ $u->pivot->is_active ? '' : 'bg-gray-50/70 text-gray-400' }}">
                            <td class="px-4 py-3"><div class="font-medium {{ $u->pivot->is_active ? 'text-gray-900' : '' }}">{{ $u->name }}@if ($u->id === auth()->id()) <span class="text-xs text-gray-400">(you)</span>@endif</div><div class="text-xs text-gray-400">{{ $u->email }}@if ($u->phone) · {{ $u->phone }}@endif</div></td>
                            <td class="px-4 py-3">@forelse ($u->roles as $r)<x-ui.badge color="{{ $r->name === 'Library Owner' ? 'purple' : 'blue' }}" class="mr-1">{{ $r->name }}</x-ui.badge>@empty<span class="text-xs text-gray-400">No role</span>@endforelse</td>
                            <td class="px-4 py-3 text-gray-600">{{ $halls->isEmpty() ? '—' : $halls->implode(', ') }}</td>
                            <td class="px-4 py-3"><x-ui.badge :color="$u->pivot->is_active ? 'green' : 'gray'">{{ $u->pivot->is_active ? 'Active' : 'Disabled' }}</x-ui.badge></td>
                            <td class="space-x-3 px-4 py-3 text-right">
                                <button wire:click="edit({{ $u->id }})" class="font-medium text-brand-600 hover:text-brand-800">Edit</button>
                                <button wire:click="showActivity({{ $u->id }})" class="font-medium text-gray-500 hover:text-gray-800">Activity</button>
                                <button wire:click="resetAccess({{ $u->id }})" wire:confirm="Email {{ $u->name }} a link to set a new password?" class="font-medium text-gray-500 hover:text-gray-800">Reset access</button>
                                @if ($u->id !== auth()->id())
                                    <button wire:click="toggleAccess({{ $u->id }})" wire:confirm="{{ $u->pivot->is_active ? 'Disable access for '.$u->name.'?' : 'Restore access for '.$u->name.'?' }}" class="font-medium {{ $u->pivot->is_active ? 'text-red-600 hover:text-red-800' : 'text-brand-600 hover:text-brand-800' }}">{{ $u->pivot->is_active ? 'Disable' : 'Enable' }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">No staff found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($showModal)
        <x-ui.modal :title="$editingId ? 'Edit staff' : 'Add staff'" max="max-w-xl" wire:key="staff-modal">
            <form wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="name" label="Full name" required wire:model="name" />
                    <x-ui.input name="phone" label="Phone" type="tel" wire:model="phone" />
                    <x-ui.input name="email" label="Email" type="email" required wire:model="email" :disabled="(bool) $editingId" class="sm:col-span-2"
                        :hint="$editingId ? 'The login email cannot be changed.' : 'They receive a link to choose their own password.'" />
                </div>

                <div>
                    <p class="mb-1.5 text-sm font-medium text-gray-700">Roles <span class="text-red-500">*</span></p>
                    <div class="grid gap-1.5 sm:grid-cols-2">
                        @foreach ($this->roles as $role)
                            <label wire:key="r-{{ $role->id }}" class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700"><input type="checkbox" wire:model="selectedRoles" value="{{ $role->name }}" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> {{ $role->name }}</label>
                        @endforeach
                    </div>
                    @error('selectedRoles') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                @if ($this->halls->isNotEmpty())
                    <div>
                        <p class="mb-1.5 text-sm font-medium text-gray-700">Halls / floors they supervise</p>
                        <div class="grid gap-1.5 sm:grid-cols-2">
                            @foreach ($this->halls as $hall)
                                <label wire:key="h-{{ $hall->id }}" class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700"><input type="checkbox" wire:model="selectedHalls" value="{{ $hall->id }}" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> {{ $hall->name }}</label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Save</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    @if ($activityFor)
        <x-ui.modal title="Recent activity" max="max-w-2xl" wire:key="act-modal">
            <ul class="divide-y divide-gray-100 text-sm">
                @forelse ($this->activity as $log)
                    <li class="flex items-center justify-between py-2" wire:key="al-{{ $log->id }}"><x-ui.badge>{{ $log->action }}</x-ui.badge><span class="text-xs text-gray-400">{{ $log->created_at->format('d M Y, H:i') }}</span></li>
                @empty
                    <li class="py-6 text-center text-gray-400">No recorded activity.</li>
                @endforelse
            </ul>
        </x-ui.modal>
    @endif
</div>
