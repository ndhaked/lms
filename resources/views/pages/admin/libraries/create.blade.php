<?php

use App\Actions\RegisterLibrary;
use App\Models\Library;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::panel')] class extends Component
{
    public string $name = '';
    public string $address = '';
    public string $phone = '';
    public string $email = '';
    public string $timezone = 'Asia/Kolkata';
    public string $owner_name = '';
    public string $owner_email = '';
    public string $owner_phone = '';
    public string $status = Library::PENDING;
    public bool $send_setup = true;
    public string $notes = '';
    public string $plan_id = '';
    public string $cycle = 'monthly';
    public bool $trial = false;
    public string $free_days = '0';        // free access granted straight away (the website offer)
    public ?int $leadId = null;
    public string $referral_code = '';     // the code of the library that referred this one

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('libraries.manage'), 403);
        $this->timezone = (string) \App\Support\BillingSettings::get('default_timezone');

        // Coming from a website request: start with what the visitor told us.
        if ($lead = \App\Models\Lead::whereIn('status', ['new', 'contacted'])->find((int) request()->query('lead'))) {
            $this->leadId = $lead->id;
            $this->fill([
                'name' => $lead->library_name, 'email' => $lead->email, 'phone' => $lead->phone,
                'owner_name' => $lead->name, 'owner_email' => $lead->email, 'owner_phone' => $lead->phone,
                'notes' => "From the website ({$lead->type} request #{$lead->id}).".($lead->message ? "\n".$lead->message : ''),
                'referral_code' => (string) $lead->referral_code,
                'status' => Library::ACTIVE, 'plan_id' => (string) ($lead->subscription_plan_id ?? ''),
                'free_days' => $lead->type === 'trial' ? (string) (int) \App\Support\BillingSettings::get('offer_free_days') : '0',
            ]);
            if ($this->plan_id === '' && $this->free_days !== '0') {
                $this->plan_id = (string) (\App\Models\SubscriptionPlan::where('status', 'active')->orderBy('price_monthly')->skip(1)->value('id') ?? \App\Models\SubscriptionPlan::where('status', 'active')->orderBy('price_monthly')->value('id') ?? '');
            }
        }
    }

    #[\Livewire\Attributes\Computed]
    public function plans()
    {
        return \App\Models\SubscriptionPlan::where('status', 'active')->orderBy('price_monthly')->get();
    }

    public function updatedPlanId(): void
    {
        $plan = $this->plans->firstWhere('id', (int) $this->plan_id);
        if ($plan && ! in_array($this->cycle, $plan->cycles(), true)) {
            $this->cycle = $plan->cycles()[0] ?? 'monthly';
        }
        $this->trial = (bool) ($plan && $plan->trial_days > 0);
    }

    public function save(): void
    {
        abort_unless(auth()->user()->hasPlatformPermission('libraries.manage'), 403);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_phone' => ['required', 'string', 'max:30'],
            'status' => ['required', 'in:'.Library::PENDING.','.Library::ACTIVE],
            'notes' => ['nullable', 'string', 'max:5000'],
            'referral_code' => ['nullable', 'string', \Illuminate\Validation\Rule::exists('libraries', 'code')],
            'plan_id' => ['nullable', \Illuminate\Validation\Rule::exists('subscription_plans', 'id')->where('status', 'active')],
            'cycle' => ['required', 'in:monthly,quarterly,annual'],
            'trial' => ['boolean'],
            'free_days' => ['required', 'integer', 'between:0,730'],
        ]);

        $plan = $this->plan_id !== '' ? \App\Models\SubscriptionPlan::find($this->plan_id) : null;
        if ($plan && ! in_array($this->cycle, $plan->cycles(), true)) {
            $this->addError('cycle', "{$plan->name} is not sold on that billing cycle.");

            return;
        }
        if ($plan && $this->trial && $plan->trial_days < 1) {
            $this->addError('trial', "{$plan->name} has no trial period.");

            return;
        }

        if ((int) $this->free_days > 0 && ! $plan) {
            $this->addError('plan_id', 'Choose the plan whose limits apply during the free period.');

            return;
        }

        $library = app(RegisterLibrary::class)(
            [
                'name' => $data['name'],
                'address' => $data['address'] ?: null,
                'phone' => $data['phone'] ?: null,
                'email' => $data['email'] ?: null,
                'timezone' => $data['timezone'],
                'status' => $data['status'],
            ],
            ['name' => $data['owner_name'], 'email' => $data['owner_email'], 'phone' => $data['owner_phone']],
            $data['notes'] ?: null,
            $this->send_setup,
            $plan ? ['plan' => $plan, 'cycle' => $this->cycle, 'trial' => $this->trial] : null,
        );

        if (filled($this->referral_code)) {
            $library->forceFill(['referred_by_id' => Library::where('code', $this->referral_code)->value('id')])->save();
        }

        if ((int) $this->free_days > 0 && $plan) {
            app(\App\Actions\Billing\GrantFreePeriod::class)($library, $plan, (int) $this->free_days, $this->leadId ? "Website offer (request #{$this->leadId})" : 'Free period at registration');
        }

        if ($this->leadId) {
            \App\Models\Lead::whereKey($this->leadId)->update(['status' => 'converted', 'library_id' => $library->id, 'handled_by' => auth()->id()]);
        }

        session()->flash('notice', "{$library->name} registered as {$library->code}.".((int) $this->free_days > 0 ? " {$this->free_days} days of free access started." : ''));
        $this->redirect(route('admin.libraries.show', $library), navigate: true);
    }
}
?>

<div class="max-w-4xl space-y-5">
    <x-slot:header><x-ui.page-title title="Register library" subtitle="Creates the library and its owner login" /></x-slot:header>

    <form wire:submit="save" class="space-y-5">
        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-4 text-sm font-semibold text-gray-900">Library</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="Library name" required wire:model="name" />
                <x-ui.input name="email" label="Library email" type="email" wire:model="email" />
                <x-ui.input name="phone" label="Library phone" type="tel" wire:model="phone" />
                <x-ui.select name="timezone" label="Time zone" wire:model="timezone">
                    @foreach (timezone_identifiers_list() as $tz) <option value="{{ $tz }}">{{ $tz }}</option> @endforeach
                </x-ui.select>
                <x-ui.textarea name="address" label="Address" wire:model="address" class="sm:col-span-2" />
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-gray-900">Owner &amp; account</h3>
            <p class="mb-4 mt-1 text-xs text-gray-500">The owner receives an email link to choose their own password. No password is created or shared.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="owner_name" label="Owner full name" required wire:model="owner_name" />
                <x-ui.input name="owner_email" label="Owner email" type="email" required wire:model="owner_email" />
                <x-ui.input name="owner_phone" label="Owner phone" type="tel" required wire:model="owner_phone" />
                <x-ui.select name="status" label="Initial account status" wire:model="status" hint="A pending library cannot sign in until you activate it.">
                    <option value="{{ \App\Models\Library::PENDING }}">Pending activation</option>
                    <option value="{{ \App\Models\Library::ACTIVE }}">Active</option>
                </x-ui.select>
                <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-2">
                    <input type="checkbox" wire:model="send_setup" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> Email account setup instructions now
                </label>
                <x-ui.input name="referral_code" label="Referred by (library code, optional)" placeholder="LIB-0001" wire:model="referral_code" hint="The referring library earns coins when this one pays." />
                <x-ui.textarea name="notes" label="Administrative notes (internal)" wire:model="notes" class="sm:col-span-2" />
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-gray-900">Subscription</h3>
            <p class="mb-4 mt-1 text-xs text-gray-500">Optional. Without a plan the library is unrestricted until you assign one.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select name="plan_id" label="Initial plan" wire:model.live="plan_id">
                    <option value="">No plan yet</option>
                    @foreach ($this->plans as $p) <option value="{{ $p->id }}">{{ $p->name }}@if ($p->priceFor('monthly')) — ₹{{ number_format((float) $p->priceFor('monthly')) }}/mo @endif</option> @endforeach
                </x-ui.select>
                @if ($plan_id !== '')
                    <x-ui.select name="free_days" label="Free access from today" wire:model="free_days" hint="Starts straight away with this plan's limits. Nothing is billed until it ends.">
                        <option value="0">None</option><option value="15">15 days</option><option value="30">1 month (30 days)</option><option value="60">2 months (60 days)</option><option value="90">3 months (90 days)</option>
                    </x-ui.select>
                    <x-ui.select name="cycle" label="Billing cycle" wire:model="cycle">
                        @foreach (($this->plans->firstWhere('id', (int) $plan_id)?->cycles() ?? []) as $c) <option value="{{ $c }}">{{ \App\Models\SubscriptionPlan::CYCLES[$c] }}</option> @endforeach
                    </x-ui.select>
                    @if (($this->plans->firstWhere('id', (int) $plan_id)?->trial_days ?? 0) > 0)
                        <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-2"><input type="checkbox" wire:model="trial" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> Start with a {{ $this->plans->firstWhere('id', (int) $plan_id)->trial_days }}-day free trial</label>
                    @else
                        <p class="text-xs text-gray-500 sm:col-span-2">This plan has no trial: the library stays pending until its first payment is approved.</p>
                    @endif
                    @error('trial') <p class="text-sm text-red-600 sm:col-span-2">{{ $message }}</p> @enderror
                @endif
            </div>
        </section>

        <div class="flex items-center gap-3">
            <x-ui.button type="submit" wire:target="save">
                <span wire:loading.remove wire:target="save">Register library</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-ui.button>
            <a href="{{ route('admin.libraries.index') }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-800">Cancel</a>
        </div>
    </form>
</div>
