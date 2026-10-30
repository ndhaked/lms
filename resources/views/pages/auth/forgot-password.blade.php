<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::guest')] class extends Component
{
    public string $email = '';
    public bool $sent = false;

    public function send(): void
    {
        $this->validate(['email' => ['required', 'email']], [
            'email.required' => __('Please enter your account email address.'),
            'email.email' => __('Please enter a valid email address.'),
        ]);

        // Always show the same message so the form cannot be used to discover which emails exist.
        Password::sendResetLink(['email' => $this->email]);
        $this->sent = true;
    }
}
?>

<main class="login-center-card-shell">
    <div class="login-form-card">
        <h2>Reset password</h2>
        <p class="form-subtitle">Enter your account email to receive a secure recovery link.</p>

        @if ($sent)
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
                If that email belongs to an account, a reset link is on its way.
            </div>
        @else
            <form wire:submit="send" novalidate>
                <div class="command-input {{ $errors->has('email') ? 'has-error' : '' }}">
                    <span class="command-input-icon">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                        </svg>
                    </span>
                    <input
                        wire:model="email"
                        type="email"
                        name="email"
                        autocomplete="email"
                        placeholder="you@library.com"
                        autofocus
                    />
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <button class="command-submit w-full justify-center" type="submit" wire:target="send">
                    <span wire:loading.remove wire:target="send">Send reset link</span>
                    <span wire:loading wire:target="send">Sending...</span>
                </button>
            </form>
        @endif

        <div class="login-card-back">
            <a href="{{ route('login') }}" wire:navigate>
                &larr; Back to sign in
            </a>
        </div>
    </div>
</main>
