<div x-on:open-theme.window="$wire.set('open', true)">
    <button type="button" wire:click="$set('open', true)" class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 hover:text-brand-700" title="Theme" aria-label="Choose theme">
        <x-lm.icon name="adjust" class="h-5 w-5" />
    </button>

    @if ($open)
        <x-ui.modal title="Choose Theme" close="$set('open', false)" max="max-w-md" wire:key="theme-modal">
            <p class="-mt-2 mb-4 text-xs text-gray-500">{{ $note }}</p>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach ($choices as $key)
                    @php $t = \App\Support\Themes::ALL[$key]; @endphp
                    <button type="button" x-on:click="document.documentElement.dataset.theme = '{{ $key }}'; $wire.choose('{{ $key }}')" wire:key="theme-{{ $key }}"
                        class="relative rounded-xl border p-3 text-left hover:border-brand-400 {{ $current === $key ? 'border-brand-600 ring-2 ring-brand-200' : 'border-gray-200' }}">
                        <span class="block h-10 rounded-lg" style="background: {{ \App\Support\Themes::swatch($key) }}"></span>
                        <span class="mt-2 block text-sm font-semibold text-gray-900">{{ $t['label'] }}</span>
                        @if ($current === $key)<span class="absolute right-2 top-2 flex h-5 w-5 items-center justify-center rounded-full bg-gray-900 text-[10px] text-white">✓</span>@endif
                    </button>
                @endforeach
            </div>
        </x-ui.modal>
    @endif
</div>
