<?php

use App\Models\AuditLog;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Support\BillingSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    #[Url]
    public string $tab = 'general';

    // general
    public string $app_name = '';
    public string $support_email = '';
    public string $support_phone = '';
    public string $default_timezone = 'Asia/Kolkata';
    public string $default_currency = 'INR';

    // subscription
    public string $default_trial_days = '14';
    public string $default_grace_days = '7';
    public string $renewal_reminder_days = '7';
    public string $expiry_policy = 'billing_only';
    public string $offer_free_days = '60';
    public bool $landing_enabled = true;
    public array $modulePrices = [];

    // payments
    public array $payment_methods = [];
    public string $payment_instructions = '';
    public bool $proof_required = true;
    public bool $require_exact_amount = true;

    // system
    public string $upload_limit_kb = '4096';
    public string $maintenance_notice = '';
    public string $retention_policy = '';

    public function mount(): void
    {
        $this->authorizeManage();

        $s = BillingSettings::all();
        $this->fill([
            'app_name' => (string) $s['app_name'], 'support_email' => (string) $s['support_email'], 'support_phone' => (string) $s['support_phone'],
            'default_timezone' => (string) $s['default_timezone'], 'default_currency' => (string) $s['default_currency'],
            'default_trial_days' => (string) $s['default_trial_days'], 'default_grace_days' => (string) $s['default_grace_days'],
            'renewal_reminder_days' => (string) $s['renewal_reminder_days'], 'expiry_policy' => (string) $s['expiry_policy'], 'offer_free_days' => (string) $s['offer_free_days'], 'landing_enabled' => (bool) $s['landing_enabled'],
            'modulePrices' => collect(SubscriptionPlan::MODULES)->map(fn ($l, $k) => ['monthly' => (string) ($s['module_prices'][$k]['monthly'] ?? ''), 'quarterly' => (string) ($s['module_prices'][$k]['quarterly'] ?? ''), 'annual' => (string) ($s['module_prices'][$k]['annual'] ?? '')])->all(),
            'payment_methods' => (array) $s['payment_methods'], 'payment_instructions' => (string) $s['payment_instructions'],
            'proof_required' => (bool) $s['proof_required'], 'require_exact_amount' => (bool) $s['require_exact_amount'],
            'upload_limit_kb' => (string) $s['upload_limit_kb'], 'maintenance_notice' => (string) $s['maintenance_notice'], 'retention_policy' => (string) $s['retention_policy'],
        ]);
    }

    protected function authorizeManage(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('settings.manage'), 403);
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetErrorBag();
    }

    protected function persist(string $section, array $values, array $strings = []): void
    {
        $values += $strings;
        $before = BillingSettings::all();
        $changed = array_keys(array_filter($values, fn ($v, $k) => ($before[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH));

        BillingSettings::set($values);
        if ($changed) {
            AuditLog::record('platform_settings.updated', null, ['section' => $section, 'changed' => $changed], null);
        }
        $this->dispatch('notify', message: 'Settings saved.');
    }

    public function saveGeneral(): void
    {
        $this->authorizeManage();
        $d = $this->validate([
            'app_name' => ['nullable', 'string', 'max:80'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:30'],
            'default_timezone' => ['required', 'timezone:all'],
            'default_currency' => ['required', 'string', 'max:8'],
        ]);
        $this->persist('general', array_map(fn ($v) => (string) $v, $d));
    }

    public function saveSubscription(): void
    {
        $this->authorizeManage();
        $d = $this->validate([
            'default_trial_days' => ['required', 'integer', 'between:0,365'],
            'default_grace_days' => ['required', 'integer', 'between:0,90'],
            'renewal_reminder_days' => ['required', 'integer', 'between:1,60'],
            'expiry_policy' => ['required', 'in:billing_only,read_only'],
            'offer_free_days' => ['required', 'integer', 'between:0,365'],
        ]);
        $this->persist('subscription', array_map('intval', \Illuminate\Support\Arr::except($d, ['expiry_policy'])), ['expiry_policy' => $d['expiry_policy'], 'landing_enabled' => $this->landing_enabled]);
    }

    public function saveModules(): void
    {
        $this->authorizeManage();
        $this->validate([
            'modulePrices' => ['array'],
            'modulePrices.*.monthly' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'modulePrices.*.quarterly' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'modulePrices.*.annual' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ], [], ['modulePrices.*.monthly' => 'monthly price', 'modulePrices.*.quarterly' => 'quarterly price', 'modulePrices.*.annual' => 'annual price']);

        $clean = [];
        foreach (array_keys(SubscriptionPlan::MODULES) as $key) {
            $row = array_filter((array) ($this->modulePrices[$key] ?? []), fn ($v) => $v !== null && $v !== '');
            if ($row) {
                $clean[$key] = array_map(fn ($v) => number_format((float) $v, 2, '.', ''), $row);
            }
        }
        $this->persist('modules', ['module_prices' => $clean]);
    }

    public function savePayments(): void
    {
        $this->authorizeManage();
        $d = $this->validate([
            'payment_methods' => ['array', 'min:1'],
            'payment_methods.*' => ['in:'.implode(',', array_keys(SubscriptionPayment::METHODS))],
            'payment_instructions' => ['nullable', 'string', 'max:2000'],
        ], ['payment_methods.min' => 'Enable at least one payment method.']);

        $this->persist('payments', [
            'payment_methods' => array_values($d['payment_methods']), 'payment_instructions' => (string) $d['payment_instructions'],
            'proof_required' => $this->proof_required, 'require_exact_amount' => $this->require_exact_amount,
        ]);
    }

    public function saveSystem(): void
    {
        $this->authorizeManage();
        $d = $this->validate([
            'upload_limit_kb' => ['required', 'integer', 'between:256,10240'],
            'maintenance_notice' => ['nullable', 'string', 'max:300'],
            'retention_policy' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->persist('system', ['upload_limit_kb' => (int) $d['upload_limit_kb'], 'maintenance_notice' => (string) $d['maintenance_notice'], 'retention_policy' => (string) $d['retention_policy']]);
    }
}
?>

<div class="max-w-4xl space-y-4">
    <x-slot:header><x-ui.page-title title="Platform settings" subtitle="Billing, payments and system" /></x-slot:header>

    @php $tabs = ['general' => 'General', 'subscription' => 'Subscriptions', 'modules' => 'Add-on modules', 'payments' => 'Payments', 'system' => 'System']; @endphp

    <div class="flex gap-1 overflow-x-auto border-b border-gray-200">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" wire:key="pt-{{ $key }}" class="whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium {{ $tab === $key ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-5">
        @if ($tab === 'general')
            <form wire:submit="saveGeneral" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="app_name" label="Application name" wire:model="app_name" hint="Shown on sign-in and invoices. Empty uses the default." />
                    <x-ui.input name="default_currency" label="Default currency" wire:model="default_currency" />
                    <x-ui.input name="support_email" label="Support email" type="email" wire:model="support_email" />
                    <x-ui.input name="support_phone" label="Support phone" type="tel" wire:model="support_phone" />
                    <x-ui.select name="default_timezone" label="Default time zone" wire:model="default_timezone" hint="Pre-selected when registering a library.">
                        @foreach (timezone_identifiers_list() as $tz) <option value="{{ $tz }}">{{ $tz }}</option> @endforeach
                    </x-ui.select>
                </div>
                <x-ui.button type="submit">Save general settings</x-ui.button>
            </form>

        @elseif ($tab === 'subscription')
            <form wire:submit="saveSubscription" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.input name="default_trial_days" label="Default trial (days)" type="number" min="0" wire:model="default_trial_days" hint="Pre-filled on new plans." />
                    <x-ui.input name="default_grace_days" label="Default grace period (days)" type="number" min="0" wire:model="default_grace_days" hint="Pre-filled on new plans." />
                    <x-ui.input name="renewal_reminder_days" label="Renewal reminder (days before)" type="number" min="1" wire:model="renewal_reminder_days" />
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.input name="offer_free_days" label="Website offer: free days" type="number" min="0" max="365" wire:model="offer_free_days" hint="60 shows “first 2 months free”. 0 hides the offer." />
                    <label class="flex items-start gap-3 self-end pb-2 text-sm text-gray-700 sm:col-span-2"><input type="checkbox" wire:model="landing_enabled" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Show the public website at “/”</span><br><span class="text-xs text-gray-500">Off: visitors go straight to the login page.</span></span></label>
                </div>
                <x-ui.select name="expiry_policy" label="When a subscription has expired (after its grace period)" wire:model="expiry_policy" hint="Suspended, cancelled and never-paid libraries are always locked.">
                    <option value="billing_only">Locked: only the Subscription page works</option>
                    <option value="read_only">Read-only: the library can view everything but change nothing</option>
                </x-ui.select>
                <div class="rounded-lg bg-gray-50 p-4 text-sm text-gray-700">
                    <p class="font-medium text-gray-900">Policies (fixed)</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                        <li><strong>Activation:</strong> a library becomes active when its first payment is approved, when a trial is started, or when platform staff activate it with a recorded reason.</li>
                        <li><strong>Expiry:</strong> what happens after the grace period is chosen above. Either way nothing is ever deleted or hidden.</li>
                        <li><strong>Renewal:</strong> an approved payment extends the subscription from its current end date (or from today if it has already lapsed). Plan edits apply at that renewal.</li>
                    </ul>
                </div>
                <x-ui.button type="submit">Save subscription settings</x-ui.button>
            </form>

        @elseif ($tab === 'modules')
            <form wire:submit="saveModules" class="space-y-4">
                <p class="text-sm text-gray-600">Standard prices for selling a module on top of a plan. They are prefilled when you enable a paid add-on for a library; you can still change the price for that library. Quarterly and annual left blank are charged as 3 × and 12 × monthly.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500"><th class="pb-2 font-medium">Module</th><th class="pb-2 font-medium">Monthly (₹)</th><th class="pb-2 font-medium">Quarterly (₹)</th><th class="pb-2 font-medium">Annual (₹)</th></tr></thead>
                        <tbody>
                            @foreach (\App\Models\SubscriptionPlan::MODULES as $key => $label)
                                <tr class="border-t border-gray-100" wire:key="mp-{{ $key }}">
                                    <td class="py-2 pr-3 font-medium text-gray-900">{{ $label }}</td>
                                    @foreach (['monthly', 'quarterly', 'annual'] as $cycle)
                                        <td class="py-2 pr-3">
                                            <input type="number" step="0.01" min="0" wire:model="modulePrices.{{ $key }}.{{ $cycle }}" class="w-28 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                            @error("modulePrices.$key.$cycle") <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">To include a module in a plan instead, tick it under Subscription Plans → Enabled modules.</p>
                <x-ui.button type="submit">Save add-on prices</x-ui.button>
            </form>

        @elseif ($tab === 'payments')
            <form wire:submit="savePayments" class="space-y-5">
                <div>
                    <p class="mb-2 text-sm font-medium text-gray-700">Accepted manual payment methods</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (\App\Models\SubscriptionPayment::METHODS as $k => $l)
                            <label wire:key="pm-{{ $k }}" class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700"><input type="checkbox" wire:model="payment_methods" value="{{ $k }}" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> {{ $l }}</label>
                        @endforeach
                    </div>
                    @error('payment_methods') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <x-ui.textarea name="payment_instructions" label="Payment instructions shown to libraries" rows="5" wire:model="payment_instructions" hint="Bank account, UPI ID and any steps. Libraries see this on their Subscription page." />
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="proof_required" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Require proof of payment</span><br><span class="text-xs text-gray-500">A screenshot or receipt must be attached.</span></span></label>
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="require_exact_amount" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Amount must match the plan price exactly</span><br><span class="text-xs text-gray-500">Off lets libraries enter any amount; you decide at approval.</span></span></label>
                <div class="rounded-lg bg-gray-50 p-4 text-xs text-gray-600"><p class="font-medium text-gray-800">Online payment gateway</p><p class="mt-1">Not configured. The billing tables are ready for a gateway to be added later (signed webhooks, idempotent processing). No gateway keys or secrets are stored here.</p></div>
                <x-ui.button type="submit">Save payment settings</x-ui.button>
            </form>

        @elseif ($tab === 'system')
            <form wire:submit="saveSystem" class="space-y-4">
                <x-ui.input name="upload_limit_kb" label="Payment-proof upload limit (KB)" type="number" min="256" max="10240" wire:model="upload_limit_kb" class="max-w-xs" />
                <x-ui.textarea name="maintenance_notice" label="Maintenance notice" wire:model="maintenance_notice" hint="Shown as a banner to everyone, on every page, until cleared." />
                <x-ui.textarea name="retention_policy" label="Data retention policy" wire:model="retention_policy" hint="Shown to library owners. Libraries' data is never deleted automatically." />
                <div class="rounded-lg bg-gray-50 p-4 text-xs text-gray-600"><p class="font-medium text-gray-800">Operations</p>
                    <p class="mt-1">Scheduled jobs: <code>subscriptions:sync</code> (00:30) and <code>library:send-reminders</code> (07:00). Backups: run <code>php artisan db:backup</code> (see DEPLOYMENT.md).</p></div>
                <x-ui.button type="submit">Save system settings</x-ui.button>
            </form>
        @endif
    </div>
</div>
