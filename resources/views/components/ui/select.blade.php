@props(['label' => null, 'name', 'hint' => null, 'required' => false])

<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-gray-700">{{ $label }}@if ($required)<span class="text-red-500"> *</span>@endif</label>
    @endif
    <select id="{{ $name }}" {{ $attributes->except('class') }}
        class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 disabled:bg-gray-50 disabled:text-gray-500">
        {{ $slot }}
    </select>
    @if ($hint) <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p> @endif
    @error($name) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
</div>
