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
            'password_confirmation' => ['required'],
        ], [
            'email.required' => __('Please enter your email address.'),
            'email.email' => __('Please enter a valid email address.'),
            'password.required' => __('Please enter a new password.'),
            'password.confirmed' => __('The password confirmation does not match.'),
            'password.min' => __('The password must be at least 8 characters.'),
            'password_confirmation.required' => __('Please confirm your new password.'),
        ]);

        $status = Password::reset(
            ['email' => $this->email, 'password' => $this->password, 'password_confirmation' => $this->password_confirmation, 'token' => $this->token],
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                // a new password ends every mobile app sign-in too
                $user->devices()->delete();
                $user->tokens()->delete();
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

<main class="login-center-card-shell">
    <div class="login-form-card">
        <h2>Set new password</h2>
        <p class="form-subtitle">Choose a secure password with at least 8 characters.</p>

        <form wire:submit="save" novalidate>
            <div class="command-input {{ $errors->has('email') ? 'has-error' : '' }}">
                <span class="command-input-icon">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                    </svg>
                </span>
                <input wire:model="email" type="email" name="email" autocomplete="email" placeholder="Email address" />
                @error('email')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="command-input {{ $errors->has('password') ? 'has-error' : '' }}">
                <span class="command-input-icon">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                </span>
                <input wire:model="password" type="password" name="password" autocomplete="new-password" placeholder="New password" />
                @error('password')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="command-input {{ $errors->has('password_confirmation') ? 'has-error' : '' }}">
                <span class="command-input-icon">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </span>
                <input wire:model="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" placeholder="Confirm new password" />
                @error('password_confirmation')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <button class="command-submit w-full justify-center" type="submit" wire:target="save">
                <span wire:loading.remove wire:target="save">Update password & sign in</span>
                <span wire:loading wire:target="save">Updating...</span>
            </button>
        </form>

        <div class="login-card-back">
            <a href="{{ route('login') }}" wire:navigate>
                &larr; Return to Sign in
            </a>
        </div>
    </div>
</main>
