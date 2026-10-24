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

<div>
    <h2 class="text-lg font-semibold text-slate-900">Choose a library</h2>
    <p class="mb-5 mt-1 text-sm text-slate-500">You belong to more than one library.</p>

    <ul class="space-y-2">
        @forelse ($this->libraries as $library)
            <li>
                @if ($library->isActive())
                    <a href="{{ route('app.dashboard', ['library' => $library->slug]) }}" wire:navigate
                        class="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3 text-sm font-medium text-gray-800 hover:border-brand-300 hover:bg-brand-50">
                        {{ $library->name }} <span class="text-xs text-gray-400">{{ $library->code }}</span>
                    </a>
                @else
                    <div class="flex items-center justify-between rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-400">
                        {{ $library->name }} <span class="text-xs">{{ \App\Models\Library::STATUSES[$library->status] }}</span>
                    </div>
                @endif
            </li>
        @empty
            <li class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700">Your account is not linked to any library. Contact your administrator.</li>
        @endforelse
    </ul>

    <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
        @csrf
        <button class="text-sm font-medium text-gray-500 hover:text-gray-700">Sign out</button>
    </form>
</div>
