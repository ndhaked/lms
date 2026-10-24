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
        $this->validate(['email' => ['required', 'email']]);

        // Always show the same message so the form cannot be used to discover which emails exist.
        Password::sendResetLink(['email' => $this->email]);
        $this->sent = true;
    }
}
?>

<div>
    <h2 class="text-lg font-semibold text-slate-900">Reset your password</h2>
    <p class="mb-6 mt-1 text-sm text-slate-500">Enter your email and we will send you a link to choose a new password.</p>

    @if ($sent)
        <p class="rounded-lg bg-brand-50 px-3 py-3 text-sm text-brand-700">If that email belongs to an account, a reset link is on its way.</p>
    @else
        <form wire:submit="send" class="space-y-5">
            <x-ui.input name="email" label="Email address" type="email" wire:model="email" autofocus />
            <x-ui.button type="submit" class="w-full py-3">Send reset link</x-ui.button>
        </form>
    @endif

    <p class="mt-6 text-center text-sm"><a href="{{ route('login') }}" wire:navigate class="font-medium text-brand-600 hover:text-brand-700">Back to sign in</a></p>
</div>
