<?php

use App\Models\AuditLog;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** "My Profile": any signed-in person (Super Admin or library user) updates their own name, phone and password. */
new #[Layout('layouts::panel')] class extends Component
{
    public string $name = '';
    public string $phone = '';

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        $u = auth()->user();
        $this->name = $u->name;
        $this->phone = (string) $u->phone;
    }

    public function saveDetails(): void
    {
        $d = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'regex:/^[0-9+\-() ]{7,20}$/'],
        ], ['phone.regex' => 'Enter a valid phone number.']);

        $u = auth()->user();
        $u->forceFill(['name' => trim($d['name']), 'phone' => trim((string) $d['phone']) ?: null]);
        $changed = array_keys($u->getDirty());
        $u->save();

        if ($changed) {
            AuditLog::record('profile.updated', $u, ['changed' => $changed], Tenancy::currentId());
        }
        $this->dispatch('notify', message: 'Profile updated.');
    }

    public function savePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [], ['current_password' => 'current password']);

        $u = auth()->user();
        if (! Hash::check($this->current_password, $u->password)) {
            $this->addError('current_password', 'That is not your current password.');

            return;
        }
        if (Hash::check($this->password, $u->password)) {
            $this->addError('password', 'Choose a password you have not used just now.');

            return;
        }

        $u->forceFill(['password' => $this->password, 'remember_token' => Str::random(60)])->save();
        $u->devices()->delete();   // and signs the mobile app out everywhere
        $u->tokens()->delete();
        AuditLog::record('profile.password_changed', $u, [], Tenancy::currentId());

        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->dispatch('notify', message: 'Password updated.');
    }
}
?>

<div class="max-w-3xl space-y-5">
    <x-slot:header><x-ui.page-title title="My profile" subtitle="Your account details and password" /></x-slot:header>

    @php
        $u = auth()->user();
        $type = request()->routeIs('admin.*') ? 'Super Admin' : ($u->getRoleNames()->implode(', ') ?: 'Staff');
    @endphp

    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-brand-700"><x-lm.icon name="user-group" class="h-4 w-4" /> Account</p>
        <h2 class="mt-1 text-3xl font-extrabold text-gray-900">My Profile</h2>
        <p class="mt-1 text-sm text-gray-500">View your account details and update your password.</p>
    </div>

    <form wire:submit="saveDetails" class="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-semibold text-gray-900">Personal details</h3>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.input name="name" label="Name" required wire:model="name" />
            <x-ui.input name="email" label="Email" value="{{ $u->email }}" disabled hint="Your email is your sign-in name, so it cannot be changed here." />
            <x-ui.input name="phone" label="Mobile" type="tel" wire:model="phone" />
            <x-ui.input name="account_type" label="Account type" value="{{ $type }}" disabled />
        </div>
        <div class="flex justify-end"><x-ui.button type="submit" wire:target="saveDetails">Save details</x-ui.button></div>
    </form>

    <form wire:submit="savePassword" class="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-semibold text-gray-900">Update password</h3>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.input name="current_password" label="Current password" type="password" autocomplete="current-password" required wire:model="current_password" class="sm:col-span-2" />
            <x-ui.input name="password" label="New password" type="password" autocomplete="new-password" required wire:model="password" hint="At least 8 characters." />
            <x-ui.input name="password_confirmation" label="Re-enter new password" type="password" autocomplete="new-password" required wire:model="password_confirmation" />
        </div>
        <div class="flex justify-end"><x-ui.button type="submit" wire:target="savePassword">Update password</x-ui.button></div>
    </form>
</div>
