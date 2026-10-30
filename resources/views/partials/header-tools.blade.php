@php
    $isPlatform = request()->routeIs('admin.*');
    $user = auth()->user();
    $role = $isPlatform ? 'Super Admin' : ($user->getRoleNames()->first() ?? 'Staff');
    $initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->join('');
@endphp

<div class="flex shrink-0 items-center gap-3">
    @if (! $isPlatform && $user->can('billing.view'))<livewire:message-counter />@endif
    <livewire:notification-bell />

    @if ($isPlatform)<livewire:theme-picker scope="admin" />@elseif ($user->can('settings.manage'))<livewire:theme-picker />@endif

    <span class="hidden rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700 sm:inline-flex">{{ $role }}</span>

    <div class="relative" x-data="{ open: false }">
        <button type="button" x-on:click="open = ! open" class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-50">
            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ mb_substr($initials, 0, 1) }}</span>
            <span class="hidden text-sm font-medium text-gray-700 sm:inline">{{ $user->name }}</span>
            <x-lm.icon name="chevron-down" class="h-4 w-4 text-gray-400" />
        </button>

        <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition
            class="absolute right-0 z-50 mt-2 w-56 rounded-lg bg-white py-1 shadow-lg ring-1 ring-black/5">
            <div class="border-b border-gray-100 px-4 py-2.5">
                <p class="truncate text-sm font-medium text-gray-900">{{ $user->name }}</p>
                <p class="truncate text-xs text-gray-500">{{ $user->email }}</p>
            </div>
            <a href="{{ $isPlatform ? route('admin.profile.edit') : route('app.profile.edit', ['library' => \App\Support\Tenancy::slug()]) }}" wire:navigate class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">My Profile</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Log out</button>
            </form>
        </div>
    </div>
</div>
