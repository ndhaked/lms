<?php

use App\Models\WhatsappTemplate;
use App\Support\LibrarySettings;
use App\Support\MessageTemplates;
use App\Support\Messaging;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    #[Url]
    public string $channel = 'sms';

    /** @var array<int, array{name: string, message: string}> WhatsApp rows being edited, by id */
    public array $rows = [];

    /** @var array<string, array{sms: bool, whatsapp: bool}> which automatic messages are on */
    public array $triggers = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('billing.view'), 403);
        $this->channel = in_array($this->channel, ['sms', 'whatsapp'], true) ? $this->channel : 'sms';
        $this->loadRows();
        foreach (array_keys(Messaging::EVENTS) as $event) {
            $this->triggers[$event] = ['sms' => Messaging::enabled($event, 'sms'), 'whatsapp' => Messaging::enabled($event, 'whatsapp')];
        }
    }

    /** Switch one automatic message on or off for a channel; it is saved straight away. */
    public function toggle(string $event, string $channel): void
    {
        $this->authorizeEdit();
        abort_unless(isset(Messaging::EVENTS[$event]) && in_array($channel, ['sms', 'whatsapp'], true), 404);

        $this->triggers[$event][$channel] = ! ($this->triggers[$event][$channel] ?? false);
        LibrarySettings::update(['message_triggers' => $this->triggers]);
    }

    protected function loadRows(): void
    {
        $this->rows = WhatsappTemplate::orderBy('sort')->orderBy('id')->get()->mapWithKeys(fn ($t) => [$t->id => ['name' => $t->name, 'message' => $t->message]])->all();
    }

    protected function authorizeEdit(): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
    }

    #[Computed]
    public function sms(): array
    {
        return MessageTemplates::sms();
    }

    public function add(): void
    {
        $this->authorizeEdit();
        abort_if(WhatsappTemplate::count() >= 30, 422, 'You can keep up to 30 WhatsApp templates.');

        WhatsappTemplate::create(['name' => 'New template', 'message' => 'Hi {name}, ', 'sort' => (int) WhatsappTemplate::max('sort') + 1]);
        $this->loadRows();
    }

    public function save(int $id): void
    {
        $this->authorizeEdit();
        $t = WhatsappTemplate::findOrFail($id);
        $this->validate(["rows.{$id}.name" => ['required', 'string', 'max:80'], "rows.{$id}.message" => ['required', 'string', 'max:1000']],
            ["rows.{$id}.name.required" => 'Give the template a name.', "rows.{$id}.message.required" => 'Write the message.'], ["rows.{$id}.name" => 'name', "rows.{$id}.message" => 'message']);

        $t->update(['name' => trim($this->rows[$id]['name']), 'message' => trim($this->rows[$id]['message'])]);
        $this->dispatch('notify', message: 'Template saved.');
    }

    public function delete(int $id): void
    {
        $this->authorizeEdit();
        WhatsappTemplate::findOrFail($id)->delete();
        unset($this->rows[$id]);
    }
}
?>

<div class="space-y-5">
    <x-slot:header><x-ui.page-title :title="$channel === 'sms' ? 'SMS templates' : 'WhatsApp templates'" subtitle="Ready-made messages for students and parents" /></x-slot:header>

    @php
        $canEdit = auth()->user()->can('settings.manage');
        $smsList = $this->sms;
    @endphp

    <section class="flex flex-wrap items-start justify-between gap-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <div>
            <p class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-brand-700"><x-lm.icon name="chat" class="h-3.5 w-3.5" /> Communication</p>
            <h2 class="mt-3 text-3xl font-extrabold text-gray-900">{{ $channel === 'sms' ? 'SMS' : 'WhatsApp' }} Template</h2>
            <p class="mt-1 text-sm text-gray-500">Templates for SMS and WhatsApp messages. Placeholders such as <code class="rounded bg-gray-100 px-1">{name}</code> <code class="rounded bg-gray-100 px-1">{library}</code> <code class="rounded bg-gray-100 px-1">{date}</code> <code class="rounded bg-gray-100 px-1">{amount}</code> <code class="rounded bg-gray-100 px-1">{plan}</code> are filled in when a message is sent.</p>
        </div>
    </section>

    <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><h3 class="text-lg font-extrabold text-gray-900">Automatic messages</h3><p class="text-xs text-gray-500">Switch on the messages the system should send by itself. Each one uses the template of the same name below and costs credits.</p></div>
            @php $conn = collect(['sms' => 'SMS', 'whatsapp' => 'WhatsApp'])->filter(fn ($l, $c) => \App\Support\Messaging::connected($c)); @endphp
            @if ($conn->isEmpty())
                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800">Messaging is not connected yet</span>
            @else
                <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">Connected: {{ $conn->implode(' and ') }}</span>
            @endif        </div>
        <div class="mt-4 divide-y divide-gray-100">
            @foreach (\App\Support\Messaging::EVENTS as $event => [$label, $about, $smsName, $waName])
                <div class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="trg-{{ $event }}">
                    <div class="min-w-0"><p class="text-sm font-bold text-gray-900">{{ $label }}</p><p class="text-xs text-gray-500">{{ $about }}</p></div>
                    <div class="flex items-center gap-5 text-sm">
                        @foreach (['sms' => 'SMS', 'whatsapp' => 'WhatsApp'] as $ch => $chLabel)
                            <label class="flex items-center gap-2 {{ $canEdit ? 'cursor-pointer' : 'opacity-60' }}">
                                <input type="checkbox" @checked($triggers[$event][$ch] ?? false) @disabled(! $canEdit) wire:click="toggle('{{ $event }}', '{{ $ch }}')" class="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                <span class="font-medium text-gray-700">{{ $chLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <div class="rounded-2xl border border-gray-200 bg-white p-3 shadow-sm">
        <div class="flex rounded-2xl border border-gray-200 bg-gray-50 p-1">
            @foreach (['sms' => ['SMS Templates', count($smsList)], 'whatsapp' => ['WhatsApp Templates', count($rows)]] as $key => [$l, $n])
                <button type="button" wire:click="$set('channel', '{{ $key }}')" wire:key="tt-{{ $key }}" class="flex flex-1 items-center justify-between rounded-xl px-6 py-3 text-sm font-bold {{ $channel === $key ? 'bg-gradient-to-r from-teal-800 to-slate-900 text-white shadow' : 'text-gray-600 hover:text-gray-900' }}">
                    <span>{{ $l }}</span><span class="flex h-6 min-w-6 items-center justify-center rounded-full px-1.5 text-xs {{ $channel === $key ? 'bg-white/20' : 'bg-gray-200 text-gray-700' }}">{{ $n }}</span>
                </button>
            @endforeach
        </div>
    </div>

    @if ($channel === 'sms')
        <div>
            <h3 class="text-lg font-extrabold text-gray-900">SMS Templates</h3>
            <p class="text-xs text-gray-500">System templates used in SMS sending flows.</p>
        </div>
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gradient-to-r from-teal-800 to-slate-900 text-left text-xs font-bold uppercase tracking-wide text-white"><tr><th class="px-4 py-3">#</th><th class="px-4 py-3">Template</th><th class="px-4 py-3">Preview</th><th class="px-4 py-3">Status</th></tr></thead>
                    <tbody>
                        @foreach ($smsList as $i => $t)
                            <tr class="border-t border-gray-100 align-top" wire:key="sms-{{ $i }}">
                                <td class="px-4 py-3 text-gray-500">{{ $i + 1 }}</td>
                                <td class="px-4 py-3"><span class="flex items-center gap-3 font-bold text-gray-900"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-50 text-brand-700"><x-lm.icon name="chat" class="h-4 w-4" /></span>{{ $t['name'] }}</span></td>
                                <td class="px-4 py-3 text-gray-700">{{ $t['message'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3"><span class="rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700">Not Editable</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div><h3 class="text-lg font-extrabold text-gray-900">WhatsApp Templates</h3><p class="text-xs text-gray-500">Edit, add, or remove WhatsApp messages from here.</p></div>
            @if ($canEdit)<button type="button" wire:click="add" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white shadow hover:bg-emerald-700">+ Add WhatsApp</button>@endif
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            @forelse ($rows as $id => $row)
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm" wire:key="wa-{{ $id }}">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <input type="text" wire:model="rows.{{ $id }}.name" @disabled(! $canEdit) class="min-w-0 flex-1 rounded-lg border-gray-200 bg-gray-50 py-1.5 text-sm font-semibold text-gray-800 focus:border-brand-500 focus:ring-brand-500" aria-label="Template name">
                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">WhatsApp</span>
                    </div>
                    <label class="block text-sm font-medium text-gray-700">Message</label>
                    <textarea wire:model="rows.{{ $id }}.message" rows="9" @disabled(! $canEdit) class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500 disabled:bg-gray-50"></textarea>
                    @error("rows.{$id}.name") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    @error("rows.{$id}.message") <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    @if ($canEdit)
                        <div class="mt-3 flex justify-end gap-2">
                            <button type="button" wire:click="save({{ $id }})" class="rounded-lg bg-brand-600 px-4 py-1.5 text-sm font-bold text-white hover:bg-brand-700">Save</button>
                            <button type="button" wire:click="delete({{ $id }})" wire:confirm="Delete this template?" class="rounded-lg border border-red-300 px-4 py-1.5 text-sm font-bold text-red-600 hover:bg-red-50">Delete</button>
                        </div>
                    @endif
                </div>
            @empty
                <p class="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-400 lg:col-span-2">No WhatsApp templates yet. @if ($canEdit)Press “Add WhatsApp” to write your first one.@endif</p>
            @endforelse
        </div>
    @endif
</div>
