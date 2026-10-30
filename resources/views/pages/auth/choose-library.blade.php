<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::guest')] class extends Component
{
    #[Computed]
    public function libraries()
    {
        return auth()->user()->accessibleLibraries();
    }
}
?>

<main class="login-center-card-shell">
    <div class="login-form-card" style="border-radius: 26px; box-shadow: 0 24px 70px rgba(0,0,0,0.5);">
        <h2>Choose a library</h2>
        <p class="form-subtitle">Select which command branch you want to open.</p>

        <ul class="space-y-2.5">
            @forelse ($this->libraries as $library)
                <li>
                    @if ($library->isActive())
                        <a href="{{ route('app.dashboard', ['library' => $library->slug]) }}" wire:navigate
                            class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50/80 px-4 py-3.5 text-sm font-semibold text-slate-800 transition hover:border-blue-400 hover:bg-blue-50">
                            <span class="flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                {{ $library->name }}
                            </span>
                            <span class="rounded-md bg-white px-2 py-0.5 text-xs font-bold text-slate-500 shadow-sm border border-slate-200">{{ $library->code }}</span>
                        </a>
                    @else
                        <div class="flex items-center justify-between rounded-xl border border-slate-100 bg-slate-50 px-4 py-3 text-sm text-slate-400">
                            {{ $library->name }} <span class="text-xs">{{ \App\Models\Library::STATUSES[$library->status] }}</span>
                        </div>
                    @endif
                </li>
            @empty
                <li class="rounded-xl bg-amber-50 p-4 text-sm text-amber-800 border border-amber-200">
                    Your account is not linked to any library. Contact your administrator.
                </li>
            @endforelse
        </ul>

        <form method="POST" action="{{ route('logout') }}" class="mt-6 border-t border-slate-200 pt-4 text-center">
            @csrf
            <button class="text-xs font-bold text-slate-500 hover:text-slate-800">
                Sign out of session
            </button>
        </form>
    </div>
</main>
