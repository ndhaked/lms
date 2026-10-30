@php
    $isPlatform = request()->routeIs('admin.*');
    $user = auth()->user();
    $library = $currentLibrary ?? \App\Support\Tenancy::library();
    $nav = $isPlatform ? \App\Support\Navigation::platform() : ($library ? \App\Support\Navigation::library($library) : []);
    $brandTitle = $isPlatform ? 'Platform Admin' : ($library?->name ?? config('app.name'));
    $brandSub = $isPlatform ? 'Platform Console' : 'Library Dashboard';
    $switchable = $isPlatform ? collect() : $user->accessibleLibraries();
    $role = $isPlatform ? 'Super Admin' : ($user->getRoleNames()->first() ?? 'Staff');
    $initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->join('');
@endphp
<!DOCTYPE html>
@php $themeKey = $isPlatform ? \App\Support\Themes::adminKey($user) : ($library ? \App\Support\Themes::key() : null); @endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"@if ($themeKey) data-theme="{{ $themeKey }}"@endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{{ $title ?? 'Dashboard' }} - {{ $brandTitle }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @if ($themeKey)<style>{!! \App\Support\Themes::cssAll() !!}</style>@endif
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-gray-50 text-gray-900" x-data="{ sidebarOpen: false }">
        <div class="min-h-screen flex">

            {{-- ===== Sidebar ===== --}}
            <aside class="lm-sidebar fixed inset-y-0 left-0 z-30 flex w-[17rem] flex-col border-r border-gray-200 bg-white transition-transform duration-200 ease-in-out lg:sticky lg:top-0 lg:h-screen lg:inset-auto lg:translate-x-0"
                :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">

                {{-- brand block + library switcher --}}
                <div class="relative flex h-16 shrink-0 items-center gap-2.5 border-b border-gray-200 px-4" x-data="{ open: false }">
                    @if (! $isPlatform && $library?->logo_path)
                        <img src="{{ asset('storage/'.$library->logo_path) }}" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-gray-200">
                    @else
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-600 text-white">
                            <x-lm.icon name="book" class="h-6 w-6" />
                        </span>
                    @endif
                    <span class="min-w-0 flex-1 leading-tight">
                        <span class="block truncate text-[15px] font-semibold text-gray-900">{{ $brandTitle }}</span>
                        <span class="mt-0.5 block truncate text-[11px] text-gray-500">{{ $brandSub }}</span>
                    </span>
                    @if ($switchable->isNotEmpty())
                        <button type="button" x-on:click="open = ! open" aria-label="Switch library"
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                            <x-lm.icon name="chevron-down" class="h-4 w-4" />
                        </button>
                        <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition
                            class="lm-pop absolute left-3 right-3 top-full z-50 mt-1 rounded-xl border border-gray-200 bg-white py-1 shadow-lg">
                            <p class="px-3.5 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Libraries</p>
                            @foreach ($switchable as $item)
                                <a href="{{ route('app.dashboard', ['library' => $item->slug]) }}" wire:navigate
                                    class="flex items-center gap-2 px-3.5 py-2 text-sm hover:bg-gray-50 {{ $item->is($library) ? 'font-semibold text-brand-700' : 'text-gray-700' }}">
                                    <span class="flex-1 truncate">{{ $item->name }}</span>
                                    @if ($item->is($library)) <span class="h-1.5 w-1.5 rounded-full bg-brand-600"></span> @endif
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- profile card: shown by the coloured themes only --}}
                @if ($isPlatform || $library)
                    <div class="lm-profile mx-3 mt-3 hidden shrink-0 rounded-2xl p-3 text-white">
                        <div class="flex items-center gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white text-lg font-bold" style="color: var(--sb-a)">{{ mb_substr($initials, 0, 1) }}</span>
                            <span class="min-w-0 leading-tight"><span class="block truncate text-sm font-semibold">{{ $user->name }}</span><span class="block truncate text-[11px] opacity-80">{{ $user->email }}</span></span>
                        </div>
                        <div class="mt-3 rounded-xl bg-white/15 px-3 py-2 text-[11px] leading-snug">
                            @if ($isPlatform)<p class="text-xs font-semibold">{{ \App\Support\BillingSettings::get('app_name') ?: config('app.name') }}</p><p class="opacity-85">Platform console</p>@else
                            <p class="text-xs font-semibold">{{ $library?->name }}</p>
                            @if ($library?->code)<p class="opacity-85">ID ({{ $library->code }})</p>@endif
                            @if (($subscription ?? null)?->expires_on)<p class="opacity-85">Expires on {{ $subscription->expires_on->format('d-m-Y') }}</p>@endif
                            @endif
                        </div>
                    </div>
                @endif

                {{-- navigation --}}
                <nav class="flex-1 space-y-1 overflow-y-auto px-3 pb-4 pt-2 text-sm">
                    @foreach ($nav as $item)
                        @if (! $isPlatform && $item['route'] === 'app.students.index')
                            @php
                                $stuOpen = request()->routeIs('app.students.*');
                                $stuView = $stuOpen && request()->routeIs('app.students.index') ? (string) request('view') : '';
                                $stuStatus = $stuOpen && request()->routeIs('app.students.index') ? (string) request('status') : '';
                                $stuLinks = [
                                    ['Total Members', route('app.students.index', ['library' => $library->slug]), $stuOpen && ! request()->routeIs('app.students.index') ? false : ($stuView === '' && $stuStatus === '')],
                                    ['VIP Members', route('app.students.index', ['library' => $library->slug, 'view' => 'vip']), $stuView === 'vip'],
                                    ['Blocked Members', route('app.students.index', ['library' => $library->slug, 'status' => 'suspended']), $stuStatus === 'suspended'],
                                    ['Left Members', route('app.students.index', ['library' => $library->slug, 'status' => 'inactive']), $stuStatus === 'inactive'],
                                    ['Frozen Members', route('app.students.index', ['library' => $library->slug, 'view' => 'frozen']), $stuView === 'frozen'],
                                ];
                            @endphp
                            <div class="lm-group" x-data="{ open: {{ $stuOpen ? 'true' : 'false' }} }">
                                <button type="button" x-on:click="open = ! open" class="group flex w-full items-center gap-3 rounded-[0.65rem] px-3 py-2.5 text-left font-medium transition {{ $stuOpen ? 'bg-brand-100/70 font-semibold text-brand-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                                    <x-lm.icon :name="$item['icon']" class="h-5 w-5 shrink-0 {{ $stuOpen ? 'text-brand-600' : 'text-gray-400 group-hover:text-gray-500' }}" />
                                    <span class="flex-1">{{ $item['label'] }}</span>
                                    <x-lm.icon name="chevron-down" class="h-4 w-4 shrink-0 transition" x-bind:class="open ? 'rotate-180' : ''" />
                                </button>
                                <div x-show="open" x-cloak x-collapse class="lm-sub ml-4 mt-1 space-y-0.5 border-l border-gray-200 pl-2">
                                    @foreach ($stuLinks as [$subLabel, $subHref, $subOn])
                                        <a href="{{ $subHref }}" wire:navigate class="flex items-center gap-3 rounded-lg px-3 py-2 text-[13px] font-medium transition {{ $subOn ? 'font-semibold text-brand-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                                            <x-lm.icon name="users" class="h-4 w-4 shrink-0 {{ $subOn ? 'text-brand-600' : 'text-gray-400' }}" />{{ $subLabel }}
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                            @continue
                        @endif
                        @php $active = $item['active'] && request()->routeIs(...explode('|', $item['active'])); @endphp
                        @if ($item['soon'])
                            <span class="flex cursor-default items-center gap-3 rounded-[0.65rem] px-3 py-2.5 font-medium text-gray-400" title="Coming in a later phase">
                                <x-lm.icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                                <span class="flex-1">{{ $item['label'] }}</span>
                                <span class="rounded-full border border-gray-200 px-2 py-0.5 text-[10px] font-medium text-gray-400">Soon</span>
                            </span>
                        @else
                            <a href="{{ route($item['route'], $item['params']) }}" wire:navigate
                                class="group flex items-center gap-3 rounded-[0.65rem] px-3 py-2.5 font-medium transition {{ $active ? 'bg-brand-100/70 font-semibold text-brand-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                                <x-lm.icon :name="$item['icon']" class="h-5 w-5 shrink-0 {{ $active ? 'text-brand-600' : 'text-gray-400 group-hover:text-gray-500' }}" />
                                <span class="flex-1">{{ $item['label'] }}</span>
                                @if (! empty($item['badge']))
                                    <span class="rounded-full bg-red-500 px-2 py-0.5 text-[10px] font-semibold leading-none text-white">{{ $item['badge'] }}</span>
                                @endif
                            </a>
                        @endif
                    @endforeach
                    @if ($isPlatform)
                        <p class="px-3 pb-1 pt-4 text-[10px] font-bold uppercase tracking-wider text-gray-400">More</p>
                        <button type="button" x-on:click="$dispatch('open-theme')" class="group flex w-full items-center gap-3 rounded-[0.65rem] px-3 py-2.5 text-left font-medium text-gray-600 transition hover:bg-gray-50 hover:text-gray-900">
                            <x-lm.icon name="adjust" class="h-5 w-5 shrink-0 text-gray-400 group-hover:text-gray-500" />
                            <span class="flex-1">Theme</span>
                        </button>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="group flex w-full items-center gap-3 rounded-[0.65rem] px-3 py-2.5 text-left font-medium text-gray-600 transition hover:bg-gray-50 hover:text-gray-900">
                                <x-lm.icon name="logout" class="h-5 w-5 shrink-0 text-gray-400 group-hover:text-gray-500" />
                                <span class="flex-1">Logout</span>
                            </button>
                        </form>
                    @endif
                    @if (! $isPlatform && $library)
                        @php
                            $slug = $library->slug;
                            $more = [
                                ['Refer & Earn', route('app.refer.index', $slug), 'ticket', $user->can('billing.view')],
                                ['Need Help?', route('app.help.index', $slug), 'chat', $user->can('billing.view')],
                                ['Generate QR Code', route('app.qr.index', $slug), 'squares-plus', $user->can('students.view')],
                                ['Contact Us', route('app.contact.index', $slug), 'mail', true],
                            ];
                        @endphp
                        @if ($user->can('billing.view'))
                            @php $msgOpen = request()->routeIs('app.templates.*', 'app.messages.*'); @endphp
                            <p class="px-3 pb-1 pt-4 text-[10px] font-bold uppercase tracking-wider text-gray-400">Communication</p>
                            <div class="lm-group" x-data="{ open: {{ $msgOpen ? 'true' : 'false' }} }">
                                <button type="button" x-on:click="open = ! open" class="group flex w-full items-center gap-3 rounded-[0.65rem] px-3 py-2.5 text-left font-medium transition {{ $msgOpen ? 'bg-brand-100/70 font-semibold text-brand-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                                    <x-lm.icon name="chat" class="h-5 w-5 shrink-0 {{ $msgOpen ? 'text-brand-600' : 'text-gray-400 group-hover:text-gray-500' }}" />
                                    <span class="flex-1">Messages</span>
                                    <x-lm.icon name="chevron-down" class="h-4 w-4 shrink-0 transition" x-bind:class="open ? 'rotate-180' : ''" />
                                </button>
                                <div x-show="open" x-cloak x-collapse class="lm-sub ml-4 mt-1 space-y-0.5 border-l border-gray-200 pl-2">
                                    @foreach ([['Message Templates', route('app.templates.index', $slug), 'app.templates.*', 'note'], ['Message History', route('app.messages.index', $slug), 'app.messages.*', 'chat']] as [$subLabel, $subHref, $subRoute, $subIcon])
                                        @php $subOn = request()->routeIs($subRoute); @endphp
                                        <a href="{{ $subHref }}" wire:navigate class="flex items-center gap-3 rounded-lg px-3 py-2 text-[13px] font-medium transition {{ $subOn ? 'font-semibold text-brand-700' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                                            <x-lm.icon :name="$subIcon" class="h-4 w-4 shrink-0 {{ $subOn ? 'text-brand-600' : 'text-gray-400' }}" />{{ $subLabel }}
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        <p class="px-3 pb-1 pt-4 text-[10px] font-bold uppercase tracking-wider text-gray-400">Support &amp; More</p>
                        @foreach ($more as [$label, $href, $icon, $show])
                            @continue(! $show)
                            @php $on = request()->url() === $href; @endphp
                            <a href="{{ $href }}" wire:navigate class="group flex items-center gap-3 rounded-[0.65rem] px-3 py-2.5 font-medium transition {{ $on ? 'bg-brand-100/70 font-semibold text-brand-600' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
                                <x-lm.icon :name="$icon" class="h-5 w-5 shrink-0 {{ $on ? 'text-brand-600' : 'text-gray-400 group-hover:text-gray-500' }}" />
                                <span class="flex-1">{{ $label }}</span>
                            </a>
                        @endforeach
                        @if ($user->can('settings.manage'))
                            <button type="button" x-on:click="$dispatch('open-theme')" class="group flex w-full items-center gap-3 rounded-[0.65rem] px-3 py-2.5 text-left font-medium text-gray-600 transition hover:bg-gray-50 hover:text-gray-900">
                                <x-lm.icon name="adjust" class="h-5 w-5 shrink-0 text-gray-400 group-hover:text-gray-500" />
                                <span class="flex-1">Theme</span>
                            </button>
                        @endif
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="group flex w-full items-center gap-3 rounded-[0.65rem] px-3 py-2.5 text-left font-medium text-gray-600 transition hover:bg-gray-50 hover:text-gray-900">
                                <x-lm.icon name="logout" class="h-5 w-5 shrink-0 text-gray-400 group-hover:text-gray-500" />
                                <span class="flex-1">Logout</span>
                            </button>
                        </form>
                        <p class="px-3 pt-2 text-center text-[11px] text-gray-400">Version {{ config('app.version', '1.0.0') }}</p>
                    @endif
                </nav>

                {{-- today's summary (library panel) --}}
                @if (! $isPlatform)
                    @include('partials.sidebar-summary')
                @endif
            </aside>

            <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false" class="fixed inset-0 z-20 bg-black/30 lg:hidden"></div>

            {{-- ===== Main column ===== --}}
            <div class="flex min-w-0 flex-1 flex-col">
                {{-- top header: full-width white bar, page title left, role + user menu right --}}
                <header class="sticky top-0 z-10 flex h-16 shrink-0 items-center justify-between gap-4 border-b border-gray-200 bg-white px-4 lg:px-8">
                    <div class="flex min-w-0 items-center gap-3">
                        <button type="button" @click="sidebarOpen = ! sidebarOpen" class="text-gray-500 hover:text-gray-700 lg:hidden" aria-label="Menu">
                            <x-lm.icon name="menu" class="h-6 w-6" />
                        </button>
                        <div class="min-w-0 truncate">{{ $header ?? '' }}</div>
                    </div>
                    @include('partials.header-tools')
                </header>

                <main class="flex-1 space-y-5 p-4 lg:p-8">
                    @if (filled($notice = \App\Support\BillingSettings::get('maintenance_notice')))
                        <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm text-blue-900">{{ $notice }}</div>
                    @endif

                    @if (! $isPlatform && ($subscriptionState ?? null) === 'readonly' && ! request()->routeIs('app.subscription.*'))
                        <a href="{{ route('app.subscription.index', ['library' => $library->slug]) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
                            <span>Your subscription has expired: this library is <strong>read-only</strong>. You can view everything but not change it.</span>
                            <span class="font-semibold underline">Renew</span>
                        </a>
                    @endif

                    @if (! $isPlatform && ($subscription ?? null) && ! request()->routeIs('app.subscription.*'))
                        @php $eff = $subscription->effectiveStatus(); $left = $subscription->daysLeft(); @endphp
                        @if ($eff === 'grace' || ($eff === 'trial' && $left !== null && $left <= 7) || ($eff === 'active' && $left !== null && $left <= 7))
                            <a href="{{ route('app.subscription.index', ['library' => $library->slug]) }}" wire:navigate
                                class="flex items-center justify-between gap-3 rounded-lg border px-4 py-2.5 text-sm {{ $eff === 'grace' ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-900' }}">
                                <span>
                                    @if ($eff === 'grace') Your subscription has ended. Access continues until {{ $subscription->graceEndsOn()->format('d M Y') }}.
                                    @elseif ($eff === 'trial') Your free trial ends in {{ max($left, 0) }} day(s).
                                    @else Your subscription expires in {{ max($left, 0) }} day(s). @endif
                                </span>
                                <span class="font-semibold underline">Renew</span>
                            </a>
                        @endif
                    @endif

                    {{ $slot }}
                </main>
            </div>
        </div>

        {{-- toasts --}}
        <div x-data="{ items: [], push(e) { const id = Date.now() + Math.random(); this.items.push({ id, type: e.detail.type ?? 'success', message: e.detail.message }); setTimeout(() => this.items = this.items.filter(i => i.id !== id), 5000); } }"
            @notify.window="push($event)" class="pointer-events-none fixed right-4 top-4 z-50 flex w-80 flex-col gap-2">
            <template x-for="item in items" :key="item.id">
                <div class="pointer-events-auto rounded-lg border bg-white px-4 py-3 text-sm shadow-lg"
                    :class="item.type === 'error' ? 'border-red-200 text-red-700' : 'border-brand-200 text-gray-800'" x-text="item.message"></div>
            </template>
        </div>

        @livewireScripts
    </body>
</html>
