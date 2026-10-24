<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Models\AuditLog;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions;

    public ?int $roleId = null;
    public array $granted = [];

    public bool $showCreate = false;
    public string $newName = '';

    public function mount(): void
    {
        $this->authorizePermission('roles.manage');
        $this->select($this->roles->first()?->id);
    }

    #[Computed]
    public function roles()
    {
        // This library's own copies of the roles (permissions are edited per library, never platform-wide).
        return Role::where('guard_name', 'web')->where('library_id', Tenancy::currentId())->withCount('users')->orderBy('name')->get();
    }

    #[Computed]
    public function role(): ?Role
    {
        return $this->roleId ? Role::where('library_id', Tenancy::currentId())->find($this->roleId) : null;
    }

    public function select(?int $id): void
    {
        $this->roleId = $id;
        unset($this->role);
        $this->granted = $this->role?->permissions()->pluck('name')->all() ?? [];
        $this->resetErrorBag();
    }

    protected function locked(?Role $role): bool
    {
        return $role?->name === LibraryRoles::OWNER; // the owner always keeps everything
    }

    public function save(): void
    {
        \App\Support\Entitlements::assertWritable();
        $this->authorizePermission('roles.manage');
        $role = $this->role;
        abort_unless($role, 404);
        abort_if($this->locked($role), 403);

        $valid = collect(LibraryRoles::groups())->flatMap(fn ($g) => array_keys($g))->all();
        $this->validate(['granted' => ['array'], 'granted.*' => [Rule::in($valid)]]);

        $before = $role->permissions()->pluck('name')->sort()->values()->all();
        $role->syncPermissions($this->granted);
        $after = $role->permissions()->pluck('name')->sort()->values()->all();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($before !== $after) {
            AuditLog::record('role.permissions_changed', $role, [
                'role' => $role->name, 'added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after)),
            ]);
        }

        $this->dispatch('notify', message: "{$role->name} updated.");
    }

    public function resetToDefault(): void
    {
        \App\Support\Entitlements::assertWritable();
        $this->authorizePermission('roles.manage');
        $role = $this->role;
        abort_unless($role && in_array($role->name, LibraryRoles::defaults(), true), 404);
        abort_if($this->locked($role), 403);

        $this->granted = LibraryRoles::map()[$role->name];
    }

    public function createRole(): void
    {
        \App\Support\Entitlements::assertWritable();
        $this->authorizePermission('roles.manage');
        $this->validate([
            'newName' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->where('library_id', Tenancy::currentId())->where('guard_name', 'web')],
        ], [], ['newName' => 'role name']);

        $role = Role::create(['name' => $this->newName, 'guard_name' => 'web']);
        AuditLog::record('role.created', $role, ['role' => $role->name]);

        $this->reset(['showCreate', 'newName']);
        unset($this->roles);
        $this->select($role->id);
    }

    public function deleteRole(): void
    {
        \App\Support\Entitlements::assertWritable();
        $this->authorizePermission('roles.manage');
        $role = $this->role;
        abort_unless($role, 404);

        if (in_array($role->name, LibraryRoles::defaults(), true)) {
            $this->dispatch('notify', type: 'error', message: 'Built-in roles cannot be deleted.');

            return;
        }
        if ($role->users()->exists()) {
            $this->dispatch('notify', type: 'error', message: 'Remove everyone from this role first.');

            return;
        }

        AuditLog::record('role.deleted', $role, ['role' => $role->name]);
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        unset($this->roles);
        $this->select($this->roles->first()?->id);
        $this->dispatch('notify', message: 'Role deleted.');
    }

    public function closeModal(): void
    {
        $this->showCreate = false;
    }
}
?>

<div class="space-y-4">
    <x-slot:header><x-ui.page-title title="Roles & permissions" subtitle="Choose what each role can do in this library" /></x-slot:header>

    @php
        $role = $this->role;
        $isLocked = $role?->name === \App\Support\LibraryRoles::OWNER;
        $isDefault = $role && in_array($role->name, \App\Support\LibraryRoles::defaults(), true);
    @endphp

    <div class="grid gap-4 lg:grid-cols-[16rem_1fr]">
        <aside class="space-y-2 rounded-xl border border-gray-200 bg-white p-3 lg:self-start">
            @foreach ($this->roles as $r)
                <button type="button" wire:click="select({{ $r->id }})" wire:key="role-{{ $r->id }}"
                    class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm {{ $roleId === $r->id ? 'bg-brand-100/70 font-semibold text-brand-700' : 'text-gray-600 hover:bg-gray-50' }}">
                    <span>{{ $r->name }}</span><span class="text-xs text-gray-400">{{ $r->users_count }}</span>
                </button>
            @endforeach
            <button type="button" wire:click="$set('showCreate', true)" class="mt-1 flex w-full items-center gap-2 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-500 hover:border-brand-400 hover:text-brand-700"><x-lm.icon name="plus" class="h-4 w-4" /> New role</button>
        </aside>

        <div class="space-y-4">
            @if ($role)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white p-5">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">{{ $role->name }}</h2>
                        <p class="text-xs text-gray-500">{{ $isLocked ? 'The owner always has every permission and cannot be restricted.' : ($isDefault ? 'Built-in role. You can change what it allows.' : 'Custom role.') }}</p>
                    </div>
                    @unless ($isLocked)
                        <div class="flex gap-2">
                            @if ($isDefault) <x-ui.button variant="secondary" wire:click="resetToDefault">Restore defaults</x-ui.button> @else <x-ui.button variant="danger" wire:click="deleteRole" wire:confirm="Delete this role?">Delete role</x-ui.button> @endif
                            <x-ui.button wire:click="save">Save permissions</x-ui.button>
                        </div>
                    @endunless
                </div>

                @foreach (\App\Support\LibraryRoles::groups() as $group => $permissions)
                    <section class="rounded-xl border border-gray-200 bg-white p-5" wire:key="grp-{{ $group }}">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ $group }}</h3>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($permissions as $key => $label)
                                <label class="flex items-start gap-3 rounded-lg border px-3 py-2 text-sm {{ $isLocked ? 'border-gray-100 bg-gray-50 text-gray-500' : 'border-gray-200 text-gray-700' }}">
                                    <input type="checkbox" wire:model="granted" value="{{ $key }}" @disabled($isLocked) @checked($isLocked || in_array($key, $granted, true)) class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            @endif
        </div>
    </div>

    @if ($showCreate)
        <x-ui.modal title="New role" max="max-w-md" wire:key="role-modal">
            <form wire:submit="createRole" class="space-y-4">
                <x-ui.input name="newName" label="Role name" required wire:model="newName" hint="You choose its permissions after creating it." />
                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Create</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif
</div>
