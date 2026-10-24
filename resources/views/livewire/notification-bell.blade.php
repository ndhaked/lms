<div class="relative" x-data @keydown.escape.window="$wire.open && $wire.toggle()">
    <button type="button" wire:click="toggle" class="relative flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 hover:bg-gray-50" aria-label="Notifications">
        <x-lm.icon name="bell" class="h-5 w-5" />
        @if ($this->unread > 0)
            <span class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-none text-white">{{ $this->unread > 99 ? '99+' : $this->unread }}</span>
        @endif
    </button>

    @if ($open)
        <div class="absolute right-0 z-50 mt-2 w-80 rounded-xl border border-gray-200 bg-white shadow-lg" @click.outside="$wire.toggle()">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                <p class="text-sm font-semibold text-gray-900">Notifications</p>
                @if ($this->unread > 0) <button type="button" wire:click="markAllRead" class="text-xs font-medium text-brand-600 hover:text-brand-800">Mark all read</button> @endif
            </div>
            <ul class="max-h-96 divide-y divide-gray-100 overflow-y-auto">
                @forelse ($this->items as $n)
                    <li wire:key="n-{{ $n->id }}">
                        <button type="button" wire:click="read('{{ $n->id }}')" class="block w-full px-4 py-3 text-left hover:bg-gray-50 {{ $n->read_at ? '' : 'bg-brand-50/50' }}">
                            <span class="flex items-start gap-2">
                                @unless ($n->read_at) <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand-600"></span> @endunless
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-gray-900">{{ $n->data['title'] ?? 'Notification' }}</span>
                                    @if (! empty($n->data['body'])) <span class="mt-0.5 block text-xs text-gray-600">{{ \Illuminate\Support\Str::limit($n->data['body'], 120) }}</span> @endif
                                    <span class="mt-1 block text-[11px] text-gray-400">{{ $n->created_at->diffForHumans() }}</span>
                                </span>
                            </span>
                        </button>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-gray-400">You're all caught up.</li>
                @endforelse
            </ul>
        </div>
    @endif
</div>
