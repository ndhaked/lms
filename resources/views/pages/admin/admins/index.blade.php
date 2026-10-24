<?php

use App\Actions\SendAccountSetup;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\PlatformPermissions;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public bool $fullAccess = false;
    public array $permissions = [];

    public ?int $activityFor = null;

    public function mount(): void
    {
        $this->authorizeManage();
    }

    protected function authorizeManage(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('admins.manage'), 403);
    }

    #[Computed]
    public function admins()
    {
        return User::where('is_super_admin', true)->orderBy('name')->get();
    }

    /** What the signed-in admin may hand out: everything if unrestricted, otherwise only what they hold. */
    #[Computed]
    public function grantable(): array
    {
        $me = auth()->user();

        return $me->platform_permissions === null ? PlatformPermissions::keys() : array_values(array_intersect(PlatformPermissions::keys(), $me->platform_permissions));
    }

    protected function iAmUnrestricted(): bool
    {
        return auth()->user()->platform_permissions === null;
    }

    protected function target(int $id): User
    {
        return User::where('is_super_admin', true)->findOrFail($id);   // only platform administrators
    }

    /** Active admins who could still manage admins, excluding $exceptId. */
    protected function otherAdminManagers(int $exceptId): int
    {
        return $this->admins->filter(fn ($u) => $u->id !== $exceptId && $u->is_active && $u->hasPlatformPermission('admins.manage'))->count();
    }

    public function create(): void
    {
        $this->authorizeManage();
        $this->reset(['editingId', 'name', 'email', 'phone', 'fullAccess', 'permissions']);
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $u = $this->target($id);

        $this->editingId = $u->id;
        $this->fill(['name' => $u->name, 'email' => $u->email, 'phone' => (string) $u->phone,
            'fullAccess' => $u->platform_permissions === null, 'permissions' => $u->platform_permissions ?? []]);
        $this->resetErrorBag();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizeManage();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'phone' => ['nullable', 'string', 'max:30'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(PlatformPermissions::keys())],
        ];
        $this->validate($rules);

        // Nobody can give away more than they hold, and only a full-access admin can create another one.
        if ($this->fullAccess && ! $this->iAmUnrestricted()) {
            $this->addError('permissions', 'Only a full-access administrator can grant full access.');

            return;
        }
        if (! $this->fullAccess && array_diff($this->permissions, $this->grantable)) {
            $this->addError('permissions', 'You cannot grant permissions you do not have yourself.');

            return;
        }
        if (! $this->fullAccess && $this->permissions === []) {
            $this->addError('permissions', 'Choose at least one permission.');

            return;
        }

        $list = $this->fullAccess ? null : array_values($this->permissions);

        if ($this->editingId) {
            $user = $this->target($this->editingId);

            // Do not leave the platform without anyone who can manage administrators.
            $keepsManage = $list === null || in_array('admins.manage', $list, true);
            if ($user->hasPlatformPermission('admins.manage') && ! $keepsManage && $this->otherAdminManagers($user->id) === 0) {
                $this->addError('permissions', 'At least one active administrator must keep the "manage administrators" permission.');

                return;
            }
            // A restricted admin cannot edit a full-access admin (that would be an escalation path).
            if ($user->platform_permissions === null && ! $this->iAmUnrestricted()) {
                $this->addError('permissions', 'Only a full-access administrator can change another full-access administrator.');

                return;
            }

            $before = $user->platform_permissions;
            $user->forceFill(['name' => $this->name, 'phone' => $this->phone ?: null, 'platform_permissions' => $list])->save();
            AuditLog::record('platform_admin.updated', $user, ['from' => $before === null ? 'full access' : $before, 'to' => $list === null ? 'full access' : $list], null);
            $message = 'Administrator updated.';
        } else {
            $user = User::create(['name' => $this->name, 'email' => $this->email, 'phone' => $this->phone ?: null, 'password' => Str::random(40)]);
            $user->forceFill(['is_super_admin' => true, 'platform_permissions' => $list])->save();
            app(SendAccountSetup::class)($user);
            AuditLog::record('platform_admin.created', $user, ['permissions' => $list === null ? 'full access' : $list], null);
            $message = "Administrator added. A setup email was sent to {$user->email}.";
        }

        $this->showModal = false;
        unset($this->admins);
        $this->dispatch('notify', message: $message);
    }

    public function toggle(int $id): void
    {
        $this->authorizeManage();
        $u = $this->target($id);

        if ($u->id === auth()->id()) {
            $this->dispatch('notify', type: 'error', message: 'You cannot disable your own account.');

            return;
        }
        if ($u->is_active && $u->hasPlatformPermission('admins.manage') && $this->otherAdminManagers($u->id) === 0) {
            $this->dispatch('notify', type: 'error', message: 'At least one active administrator must be able to manage administrators.');

            return;
        }
        if ($u->platform_permissions === null && ! $this->iAmUnrestricted()) {
            $this->dispatch('notify', type: 'error', message: 'Only a full-access administrator can disable another full-access administrator.');

            return;
        }

        $u->forceFill(['is_active' => ! $u->is_active])->save();
        AuditLog::record($u->is_active ? 'platform_admin.enabled' : 'platform_admin.disabled', $u, [], null);
        unset($this->admins);
        $this->dispatch('notify', message: $u->is_active ? 'Account enabled.' : 'Account disabled. They can no longer sign in.');
    }

    public function resetAccess(int $id): void
    {
        $this->authorizeManage();
        $u = $this->target($id);
        app(SendAccountSetup::class)($u);
        AuditLog::record('platform_admin.access_reset_sent', $u, ['to' => $u->email], null);
        $this->dispatch('notify', message: "A password link was sent to {$u->email}.");
    }

    #[Computed]
    public function activity()
    {
        return $this->activityFor ? AuditLog::where('user_id', $this->activityFor)->latest('created_at')->latest('id')->limit(30)->get() : collect();
    }

    public function showActivity(int $id): void
    {
        $this->authorizeManage();
        $this->target($id);
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
    <x-slot:header><x-ui.page-title title="Admin users" subtitle="People who run the platform" /></x-slot:header>

    <div class="flex justify-end"><x-ui.button wire:click="create"><x-lm.icon name="plus" class="h-4 w-4" /> Add administrator</x-ui.button></div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="px-4 py-3 font-medium">Name</th><th class="px-4 py-3 font-medium">Access</th><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3 text-right font-medium">Actions</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($this->admins as $a)
                        <tr wire:key="adm-{{ $a->id }}" class="{{ $a->is_active ? '' : 'bg-gray-50/70 text-gray-400' }}">
                            <td class="px-4 py-3"><div class="font-medium {{ $a->is_active ? 'text-gray-900' : '' }}">{{ $a->name }}@if ($a->id === auth()->id()) <span class="text-xs text-gray-400">(you)</span>@endif</div><div class="text-xs text-gray-400">{{ $a->email }}</div></td>
                            <td class="px-4 py-3">
                                @if ($a->platform_permissions === null) <x-ui.badge color="purple">Full access</x-ui.badge>
                                @else @foreach ($a->platform_permissions as $p) <x-ui.badge class="mb-0.5 mr-0.5">{{ $p }}</x-ui.badge> @endforeach @endif
                            </td>
                            <td class="px-4 py-3"><x-ui.badge :color="$a->is_active ? 'green' : 'gray'">{{ $a->is_active ? 'Active' : 'Disabled' }}</x-ui.badge></td>
                            <td class="space-x-3 px-4 py-3 text-right">
                                <button wire:click="edit({{ $a->id }})" class="font-medium text-brand-600 hover:text-brand-800">Edit</button>
                                <button wire:click="showActivity({{ $a->id }})" class="font-medium text-gray-500 hover:text-gray-800">Activity</button>
                                <button wire:click="resetAccess({{ $a->id }})" wire:confirm="Email {{ $a->name }} a link to set a new password?" class="font-medium text-gray-500 hover:text-gray-800">Reset access</button>
                                @if ($a->id !== auth()->id())
                                    <button wire:click="toggle({{ $a->id }})" wire:confirm="{{ $a->is_active ? 'Disable '.$a->name.'?' : 'Enable '.$a->name.'?' }}" class="font-medium {{ $a->is_active ? 'text-red-600 hover:text-red-800' : 'text-brand-600 hover:text-brand-800' }}">{{ $a->is_active ? 'Disable' : 'Enable' }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($showModal)
        <x-ui.modal :title="$editingId ? 'Edit administrator' : 'Add administrator'" max="max-w-xl" wire:key="adm-modal">
            <form wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="name" label="Full name" required wire:model="name" />
                    <x-ui.input name="phone" label="Phone" type="tel" wire:model="phone" />
                    <x-ui.input name="email" label="Email" type="email" required wire:model="email" :disabled="(bool) $editingId" class="sm:col-span-2" :hint="$editingId ? 'The login email cannot be changed.' : 'They receive a link to choose their own password.'" />
                </div>

                <div>
                    <p class="mb-1.5 text-sm font-medium text-gray-700">Permissions</p>
                    @if ($this->grantable === \App\Support\PlatformPermissions::keys() && auth()->user()->platform_permissions === null)
                        <label class="mb-2 flex items-center gap-2 rounded-lg border border-purple-200 bg-purple-50 px-3 py-2 text-sm text-purple-900"><input type="checkbox" wire:model.live="fullAccess" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> Full access (everything, including new permissions added later)</label>
                    @endif
                    @unless ($fullAccess)
                        <div class="grid gap-1.5">
                            @foreach (\App\Support\PlatformPermissions::ALL as $key => $label)
                                @continue(! in_array($key, $this->grantable, true))
                                <label wire:key="pp-{{ $key }}" class="flex items-start gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700"><input type="checkbox" wire:model="permissions" value="{{ $key }}" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"> <span>{{ $label }}</span></label>
                            @endforeach
                        </div>
                    @endunless
                    @error('permissions') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end gap-2"><x-ui.button variant="secondary" wire:click="closeModal">Cancel</x-ui.button><x-ui.button type="submit">Save</x-ui.button></div>
            </form>
        </x-ui.modal>
    @endif

    @if ($activityFor)
        <x-ui.modal title="Recent activity" max="max-w-2xl" wire:key="adm-act">
            <ul class="divide-y divide-gray-100 text-sm">
                @forelse ($this->activity as $log)
                    <li class="flex items-center justify-between py-2" wire:key="aa-{{ $log->id }}"><x-ui.badge>{{ $log->action }}</x-ui.badge><span class="text-xs text-gray-400">{{ $log->created_at->format('d M Y, H:i') }}</span></li>
                @empty
                    <li class="py-6 text-center text-gray-400">No recorded activity.</li>
                @endforelse
            </ul>
        </x-ui.modal>
    @endif
</div>
