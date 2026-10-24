<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::guest')] class extends Component
{
    public string $token = '';

    #[Url]
    public string $email = '';

    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function save(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            ['email' => $this->email, 'password' => $this->password, 'password_confirmation' => $this->password_confirmation, 'token' => $this->token],
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', __($status));

            return;
        }

        session()->flash('status', 'Your password has been set. You can sign in now.');
        $this->redirect(route('login'), navigate: true);
    }
}
?>

<div>
    <h2 class="text-lg font-semibold text-slate-900">Choose your password</h2>
    <p class="mb-6 mt-1 text-sm text-slate-500">Use at least 8 characters. Only you will know this password.</p>

    <form wire:submit="save" class="space-y-5">
        <x-ui.input name="email" label="Email address" type="email" wire:model="email" />
        <x-ui.input name="password" label="New password" type="password" wire:model="password" autocomplete="new-password" />
        <x-ui.input name="password_confirmation" label="Confirm password" type="password" wire:model="password_confirmation" autocomplete="new-password" />
        <x-ui.button type="submit" class="w-full py-3">Save password</x-ui.button>
    </form>
</div>
