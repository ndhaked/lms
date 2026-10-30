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
    public bool $isMember = false;
    public string $libraryCode = '';

    public function quickFill(string $role): void
    {
        if ($role === 'admin') {
            $this->email = 'admin@example.com';
            $this->password = 'password';
            $this->isMember = false;
        } elseif ($role === 'owner') {
            $this->email = 'owner@example.com';
            $this->password = 'password';
            $this->isMember = false;
        } elseif ($role === 'student') {
            $this->email = 'student@example.com';
            $this->password = 'password';
            $this->isMember = true;
            $this->libraryCode = 'DEMO';
        }
        $this->resetValidation();
    }

    public function toggleMember(bool $status): void
    {
        $this->isMember = $status;
        if (! $status && $this->email === 'student@example.com') {
            $this->email = '';
            $this->password = '';
        } elseif ($status && empty($this->email)) {
            $this->email = 'student@example.com';
            $this->password = 'password';
            $this->libraryCode = 'DEMO';
        }
    }

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => __('Please enter your username or email address.'),
            'password.required' => __('Please enter your password.'),
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

        $this->redirectIntended(route('workspace'), navigate: true);
    }
}
?>

<main class="login-command-wrap">
    <!-- Left Section: Command Center Telemetry Hero -->
    <section class="login-intro-card">
        <div class="login-intro-top">
            <div class="login-intro-logo">
                <x-lm.icon name="book" class="h-8 w-8 text-white" />
            </div>
            <div>
                <span class="portal-badge">
                    <span class="pulse-dot"></span>
                    Admin Portal
                </span>
                <small class="portal-subtitle">Library Command Center</small>
            </div>
        </div>

        <h1>Every book, seat and member, <em>on the record.</em></h1>
        <p>Pull the right drawer, find the right card. Run your library desk the way great librarians always have: organised, calm and one step ahead.</p>

        <!-- Card catalog cabinet: drawers pull open one after another -->
        <div class="login-library-animation" aria-hidden="true">
            @foreach (['A–C', 'D–F', 'G–I', 'J–L', 'M–O', 'P–R', 'S–U', 'V–Z'] as $i => $range)
                <div class="drawer" style="--i: {{ $i }}">
                    <span class="drawer-card"></span>
                    <div class="drawer-face">
                        <span class="drawer-label">{{ $range }}</span>
                        <span class="drawer-pull"></span>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- 3-Card Interactive Telemetry Grid -->
        <div class="login-mini-grid">
            <article>
                <div class="grid-icon-box">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
                <strong>Fast access</strong>
                <span>Jump straight into daily library operations.</span>
            </article>

            <article>
                <div class="grid-icon-box">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                    </svg>
                </div>
                <strong>Live signal</strong>
                <span>See seats, revenue, and attendance at a glance.</span>
            </article>

            <article>
                <div class="grid-icon-box">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </div>
                <strong>Member flow</strong>
                <span>Track plans, renewals, dues, and activity trends.</span>
            </article>
        </div>

        <div class="login-note">
            "A library is not a luxury but one of the necessities of life."
        </div>
    </section>

    <!-- Right Section: Command Login Card -->
    <section class="login-form-shell">
        <div class="login-form-card" x-data="{ showPass: false }">
            <h2>{{ $isMember ? "Borrower's card" : 'Staff check-in' }}</h2>
            <p class="form-subtitle">Use your staff credentials or member access to open the dashboard.</p>

            <!-- Segment Switcher Tabs -->
            <div class="auth-segment" aria-label="Authentication tabs">
                <button type="button" wire:click="toggleMember(false)" class="{{ ! $isMember ? 'active' : '' }}">
                    Staff / Admin
                </button>
                <button type="button" wire:click="toggleMember(true)" class="{{ $isMember ? 'active' : '' }}">
                    Student Member
                </button>
            </div>

            <form wire:submit="login" novalidate>
                @if (session('status'))
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold text-emerald-700">
                        {{ session('status') }}
                    </div>
                @endif

                <!-- Username/Email Field -->
                <div class="command-input {{ $errors->has('email') ? 'has-error' : '' }}">
                    <span class="command-input-icon">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </span>
                    <input
                        wire:model="email"
                        type="text"
                        name="email"
                        autocomplete="username"
                        placeholder="{{ $isMember ? 'Student Email / Username' : 'Username / Email address' }}"
                        autofocus
                    />
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Password Field -->
                <div class="command-input password-field {{ $errors->has('password') ? 'has-error' : '' }}">
                    <span class="command-input-icon">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </span>
                    <input
                        wire:model="password"
                        x-bind:type="showPass ? 'text' : 'password'"
                        name="password"
                        autocomplete="current-password"
                        placeholder="Password"
                    />
                    <button
                        type="button"
                        class="command-password-toggle"
                        x-on:click="showPass = ! showPass"
                        aria-label="Toggle password visibility"
                    >
                        <svg x-show="! showPass" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        <svg x-show="showPass" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                        </svg>
                    </button>
                    @error('password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Optional Library Code for Members -->
                @if ($isMember)
                    <div class="command-input">
                        <span class="command-input-icon">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                        </span>
                        <input
                            wire:model="libraryCode"
                            type="text"
                            placeholder="Library Code (e.g. DEMO)"
                        />
                    </div>
                @endif

                <!-- Options Row -->
                <div class="command-options">
                    <label>
                        <input wire:model="remember" type="checkbox" id="remember" />
                        <span>Keep me signed in</span>
                    </label>
                    <label>
                        <input wire:model.live="isMember" type="checkbox" id="isMember" />
                        <span>I'm Member</span>
                    </label>
                </div>

                <!-- Action Row -->
                <div class="command-action-row">
                    <a href="{{ route('password.request') }}" wire:navigate>
                        I forgot my password
                    </a>
                    <button class="command-submit" type="submit" wire:target="login">
                        <span wire:loading.remove wire:target="login" class="inline-flex items-center gap-1.5">
                            Sign in
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </span>
                        <span wire:loading.flex wire:target="login" class="items-center gap-1.5">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Verifying...
                        </span>
                    </button>
                </div>
            </form>

            <!-- Quick Auto-Fill Demo Accounts -->
            <div class="demo-pill-tray">
                <div class="demo-pill-tray-header">
                    <span>Quick Demo Logins</span>
                    <span class="text-xs font-normal text-slate-400">Click to fill</span>
                </div>
                <div class="demo-pills">
                    <button type="button" wire:click="quickFill('owner')" class="demo-pill-btn" title="Sign in as Library Owner">
                        🏢 <span>Demo Owner</span>
                    </button>
                    <button type="button" wire:click="quickFill('admin')" class="demo-pill-btn" title="Sign in as Platform Super Admin">
                        👑 <span>Super Admin</span>
                    </button>
                    <button type="button" wire:click="quickFill('student')" class="demo-pill-btn" title="Sign in as Student Member">
                        🎓 <span>Student</span>
                    </button>
                </div>
            </div>
        </div>

        <footer>
            2026 &copy; {{ \App\Support\BillingSettings::get('app_name') ?: config('app.name') }} Management Software
        </footer>
    </section>
</main>
