<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::guest')] class extends Component
{
    public string $email = '';
    public string $password = '';
    public bool $remember = false;

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many login attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        // Inactive accounts cannot sign in at all.
        $ok = Auth::attempt(['email' => $this->email, 'password' => $this->password, 'is_active' => true], $this->remember);

        if (! $ok) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['email' => __('These credentials do not match our records.')]);
        }

        RateLimiter::clear($key);
        session()->regenerate();

        $this->redirectIntended(route('home'), navigate: true);
    }
}
?>

<div>
    <h2 class="text-lg font-semibold text-slate-900">Welcome back</h2>
    <p class="mb-6 mt-1 text-sm text-slate-500">Sign in to manage your library.</p>

    <form wire:submit="login" class="space-y-5">
        <x-ui.input name="email" label="Email address" type="email" wire:model="email" autofocus autocomplete="username" placeholder="you@library.com" />

        <div x-data="{ show: false }" class="relative">
            <x-ui.input name="password" label="Password" wire:model="password" autocomplete="current-password" x-bind:type="show ? 'text' : 'password'" type="password" />
            <button type="button" x-on:click="show = ! show" class="absolute right-3 top-8 text-slate-400 hover:text-slate-600" aria-label="Show or hide password">
                <x-lm.icon name="eye" class="h-5 w-5" />
            </button>
        </div>

        <div class="flex items-center justify-between text-sm">
            <label class="flex items-center gap-2 text-slate-600">
                <input wire:model="remember" type="checkbox" class="rounded border-slate-300 text-brand-600 shadow-sm focus:ring-brand-500"> Remember me
            </label>
            <a href="{{ route('password.request') }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-700">Forgot password?</a>
        </div>

        <x-ui.button type="submit" class="w-full py-3" wire:target="login">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in&hellip;</span>
        </x-ui.button>
    </form>

    @if (session('status'))
        <p class="mt-4 rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-700">{{ session('status') }}</p>
    @endif
</div>
