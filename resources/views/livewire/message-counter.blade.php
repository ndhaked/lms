<div x-data="{ open: false, ch: 'sms' }" x-on:keydown.escape.window="open = false" class="flex items-center gap-2">
    @foreach (['sms' => 'SMS credits', 'whatsapp' => 'WhatsApp credits'] as $key => $title)
        <button type="button" x-on:click="ch = '{{ $key }}'; open = true" title="{{ $title }}" aria-label="{{ $title }}" class="relative flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 hover:text-brand-700">
            @if ($key === 'sms')
                <x-lm.icon name="chat" class="h-5 w-5" />
            @else
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-5 w-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21l1.65-4.9A8.5 8.5 0 1 1 8 19.4L3 21z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 8.5c0 3.3 2.7 6 6 6l1-1.3-1.9-1-.9.8c-.9-.3-1.7-1.1-2-2l.8-.9-1-1.9L9 8.5z"/></svg>
            @endif
            <span class="absolute -right-1.5 -top-1.5 min-w-[1.25rem] rounded-full bg-gray-200 px-1 text-center text-[10px] font-bold leading-5 text-gray-700">{{ $counters[$key]['left'] > 999 ? '999+' : $counters[$key]['left'] }}</span>
        </button>
    @endforeach

    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 pb-10 pt-28" x-on:click.self="open = false">
            <div class="w-full max-w-lg overflow-hidden rounded-3xl bg-white shadow-2xl" x-on:click.outside="open = false">
                <div class="flex items-center justify-between gap-3 bg-gradient-to-r from-orange-500 to-red-500 px-5 py-4 text-white">
                    <div class="min-w-0 whitespace-nowrap">
                        @foreach ($counters as $key => $c)
                            <div x-show="ch === '{{ $key }}'" x-cloak><p class="text-lg font-extrabold">{{ $c['label'] }} Counter</p><p class="text-xs text-white/85">{{ $c['label'] }} credit details</p></div>
                        @endforeach
                    </div>
                    <div class="flex shrink-0 items-center gap-2 whitespace-nowrap">
                        <button type="button" x-on:click="ch = 'sms'" x-bind:class="ch === 'sms' ? 'bg-white text-red-600' : 'bg-white/20 text-white'" class="rounded-full px-3 py-1.5 text-xs font-bold">SMS Logs</button>
                        <button type="button" x-on:click="ch = 'whatsapp'" x-bind:class="ch === 'whatsapp' ? 'bg-white text-red-600' : 'bg-white/20 text-white'" class="rounded-full px-3 py-1.5 text-xs font-bold">WhatsApp Logs</button>
                        <button type="button" x-on:click="open = false" class="text-2xl leading-none text-white/90 hover:text-white" aria-label="Close">×</button>
                    </div>
                </div>

                @foreach ($counters as $key => $c)
                    <div x-show="ch === '{{ $key }}'" x-cloak class="space-y-3 p-5">
                        @foreach ([['Credit Messages', $c['credit'], 'border-rose-200 bg-rose-50', 'bg-red-500', 'mail'], ['Left Messages', $c['left'], 'border-emerald-200 bg-emerald-50', 'bg-emerald-500', 'upload'], ['Used Messages', $c['used'], 'border-rose-200 bg-rose-50', 'bg-red-500', 'chat']] as [$label, $value, $box, $chip, $icon])
                            <div class="flex items-center gap-4 rounded-2xl border {{ $box }} p-4">
                                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl {{ $chip }} text-white"><x-lm.icon :name="$icon" class="h-6 w-6" /></span>
                                <div><p class="text-sm font-bold text-gray-800">{{ $label }}</p><p class="text-3xl font-extrabold text-gray-900">{{ number_format($value) }}</p></div>
                            </div>
                        @endforeach
                        <div class="h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-2 rounded-full bg-gradient-to-r from-amber-400 to-orange-500" style="width: {{ $c['used'] > 0 ? max(3, $c['pct']) : 0 }}%"></div></div>
                        <div class="grid grid-cols-[1fr_auto] gap-3 pt-1">
                            <a href="{{ route('app.messages.index', ['library' => $slug, 'channel' => $key]) }}" wire:navigate class="flex items-center justify-center gap-2 rounded-xl border border-rose-200 py-3 text-sm font-bold text-red-600 hover:bg-rose-50">▤ View Log</a>
                            <button type="button" x-on:click="open = false" class="rounded-xl bg-gray-100 px-8 py-3 text-sm font-bold text-gray-800 hover:bg-gray-200">Close</button>
                        </div>
                        <p class="text-center text-xs text-gray-500">Running low? <a href="{{ route('app.subscription.plans', ['library' => $slug, 'tab' => $key]) }}" wire:navigate class="font-semibold text-red-600 hover:underline">Buy {{ $c['label'] }} credits</a></p>
                    </div>
                @endforeach
            </div>
        </div>
    </template>
</div>
