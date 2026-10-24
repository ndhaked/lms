<div id="offer-form">
    @if ($done)
        <div class="rounded-2xl border border-brand-200 bg-brand-50 p-8 text-center">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-brand-600 text-white"><x-lm.icon name="check-circle" class="h-8 w-8" /></span>
            <h3 class="mt-4 text-xl font-bold text-gray-900">{{ $type === 'demo' ? 'Your demo is requested' : 'You are on the list' }}</h3>
            <p class="mt-2 text-sm text-gray-600">{{ $doneNote ?? 'Thank you! Our team will call or message you within one working day'.($type === 'trial' ? ' to set up your library with '.($offer ?? 'a free trial').' free.' : ' to confirm the time.') }}</p>
            <button type="button" wire:click="again" class="mt-5 text-sm font-semibold text-brand-700 hover:text-brand-800">Send another request</button>
        </div>
    @else
        <form wire:submit="submit" class="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-xl shadow-gray-200/60 sm:p-8" novalidate>
            <div class="grid grid-cols-2 gap-1 rounded-xl bg-gray-100 p-1 text-sm font-semibold">
                @if ($offer)
                    <button type="button" wire:click="setType('trial')" class="rounded-lg px-3 py-2.5 {{ $type === 'trial' ? 'bg-white text-brand-700 shadow' : 'text-gray-600 hover:text-gray-900' }}">Get {{ $offer }} free</button>
                @else
                    <button type="button" wire:click="setType('trial')" class="rounded-lg px-3 py-2.5 {{ $type === 'trial' ? 'bg-white text-brand-700 shadow' : 'text-gray-600 hover:text-gray-900' }}">Start now</button>
                @endif
                <button type="button" wire:click="setType('demo')" class="rounded-lg px-3 py-2.5 {{ $type === 'demo' ? 'bg-white text-brand-700 shadow' : 'text-gray-600 hover:text-gray-900' }}">Book a demo</button>
            </div>

            <p class="text-sm text-gray-600">
                @if ($type === 'demo') We will show you the whole system on a call or at your library, and answer your questions. It takes about 30 minutes.
                @else We set up your library for you, <strong>no card or payment needed</strong>@if ($offer): your first {{ $offer }} are free.@else . @endif You only pay if you decide to continue. @endif
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="lf-name" class="block text-sm font-medium text-gray-700">Your name <span class="text-red-500">*</span></label>
                    <input id="lf-name" type="text" wire:model="name" autocomplete="name" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="lf-lib" class="block text-sm font-medium text-gray-700">Library / study room name <span class="text-red-500">*</span></label>
                    <input id="lf-lib" type="text" wire:model="library_name" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('library_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="lf-phone" class="block text-sm font-medium text-gray-700">Phone / WhatsApp <span class="text-red-500">*</span></label>
                    <input id="lf-phone" type="tel" wire:model="phone" autocomplete="tel" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="lf-email" class="block text-sm font-medium text-gray-700">Email <span class="text-red-500">*</span></label>
                    <input id="lf-email" type="email" wire:model="email" autocomplete="email" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="lf-city" class="block text-sm font-medium text-gray-700">City</label>
                    <input id="lf-city" type="text" wire:model="city" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div>
                    <label for="lf-size" class="block text-sm font-medium text-gray-700">How many students?</label>
                    <select id="lf-size" wire:model="students" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Choose…</option>
                        @foreach (\App\Models\Lead::SIZES as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="lf-plan" class="block text-sm font-medium text-gray-700">Plan you are interested in</label>
                    <select id="lf-plan" wire:model="plan" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Not sure yet, help me choose</option>
                        @foreach ($plans as $p) <option value="{{ $p->id }}">{{ $p->name }}</option> @endforeach
                    </select>
                </div>
                @if ($type === 'demo')
                    <div>
                        <label for="lf-date" class="block text-sm font-medium text-gray-700">Preferred date</label>
                        <input id="lf-date" type="date" wire:model="preferred_date" min="{{ today()->toDateString() }}" max="{{ today()->addDays(60)->toDateString() }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                        @error('preferred_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="lf-time" class="block text-sm font-medium text-gray-700">Preferred time</label>
                        <select id="lf-time" wire:model="preferred_time" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="">Any time</option>
                            @foreach (\App\Models\Lead::TIMES as $k => $l) <option value="{{ $k }}">{{ $l }}</option> @endforeach
                        </select>
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <label for="lf-msg" class="block text-sm font-medium text-gray-700">Anything we should know? <span class="font-normal text-gray-400">(optional)</span></label>
                    <textarea id="lf-msg" wire:model="message" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></textarea>
                </div>
            </div>

            {{-- honeypot: hidden from people, bots fill it in --}}
            <div class="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden="true"><label>Website<input type="text" tabindex="-1" autocomplete="off" wire:model="website"></label></div>

            <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-5 py-3.5 text-base font-semibold text-white shadow-lg shadow-brand-600/30 transition hover:bg-brand-700 disabled:opacity-70">
                <span wire:loading.remove wire:target="submit">{{ $type === 'demo' ? 'Book my demo' : ($offer ? 'Claim my '.$offer.' free' : 'Get started') }}</span>
                <span wire:loading wire:target="submit">Sending…</span>
            </button>
            <p class="text-center text-xs text-gray-500">No payment details needed. We will only use your details to contact you about this request.</p>
        </form>
    @endif
</div>
