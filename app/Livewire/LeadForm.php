<?php

namespace App\Livewire;

use App\Models\Lead;
use App\Models\SubscriptionPlan;
use App\Support\BillingSettings;
use App\Support\Notifier;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/** The sign-up box on the public website: claim the free months, or book a demo. */
class LeadForm extends Component
{
    public string $type = 'trial';          // trial | demo
    public string $plan = '';

    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $library_name = '';
    public string $city = '';
    public string $students = '';
    public string $preferred_date = '';
    public string $preferred_time = '';
    public string $message = '';
    public string $website = '';            // honeypot: people never see or fill this

    public bool $done = false;
    public ?string $doneNote = null;

    public function mount(): void
    {
        $plan = (string) request()->query('plan', '');
        if (ctype_digit($plan) && SubscriptionPlan::where('status', 'active')->whereKey((int) $plan)->exists()) {
            $this->plan = $plan;
        }
        if (request()->query('type') === 'demo') {
            $this->type = 'demo';
        }
    }

    public function setType(string $type): void
    {
        if (in_array($type, ['trial', 'demo'], true)) {
            $this->type = $type;
            $this->resetErrorBag();
        }
    }

    public function submit(): void
    {
        // people paste with stray spaces; clean that up before checking
        foreach (['name', 'email', 'phone', 'library_name', 'city', 'message'] as $field) {
            $this->{$field} = trim($this->{$field});
        }

        $data = $this->validate([
            'type' => ['required', 'in:trial,demo'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'phone' => ['required', 'regex:/^[0-9+\-() ]{7,20}$/'],
            'library_name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'students' => ['nullable', 'in:'.implode(',', array_keys(Lead::SIZES))],
            'plan' => ['nullable', 'integer'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today', 'before_or_equal:'.today()->addDays(60)->toDateString()],
            'preferred_time' => ['nullable', 'in:'.implode(',', array_keys(Lead::TIMES))],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [
            'phone.regex' => 'Enter a valid phone number.',
            'preferred_date.after_or_equal' => 'Choose today or a later date.',
            'preferred_date.before_or_equal' => 'Choose a date within the next 60 days.',
        ], ['library_name' => 'library name', 'preferred_date' => 'preferred date']);

        // A bot filled the hidden field: look successful and store nothing.
        if ($this->website !== '') {
            $this->done = true;

            return;
        }

        $key = 'lead:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many requests from your connection. Please try again in a little while, or call us.');

            return;
        }
        RateLimiter::hit($key, 3600);

        $email = mb_strtolower(trim($data['email']));
        $type = $data['type'];

        // The same person pressing twice (or coming back the next hour) is told we already have it.
        if (Lead::where('email', $email)->where('type', $type)->where('created_at', '>=', now()->subDay())->exists()) {
            $this->finish($type, true);

            return;
        }

        $plan = $this->plan !== '' ? SubscriptionPlan::where('status', 'active')->find((int) $this->plan) : null;

        $lead = Lead::create([
            'type' => $type, 'name' => trim($data['name']), 'email' => $email, 'phone' => trim($data['phone']), 'library_name' => trim($data['library_name']),
            'city' => $data['city'] ?: null, 'students' => $data['students'] ?: null, 'subscription_plan_id' => $plan?->id,
            'preferred_date' => $type === 'demo' ? ($data['preferred_date'] ?: null) : null, 'preferred_time' => $type === 'demo' ? ($data['preferred_time'] ?: null) : null,
            'message' => $data['message'] ?: null, 'ip' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 200),
        ]);

        $what = $type === 'demo' ? 'booked a demo' : 'asked for the free months';
        Notifier::platform('libraries.manage', 'lead.new', "New lead: {$lead->library_name}", "{$lead->name} {$what}. {$lead->phone}", route('admin.leads.index'));

        if ($to = BillingSettings::get('support_email')) {
            try {
                Mail::raw("New {$lead->type} request\n\nLibrary: {$lead->library_name}\nName: {$lead->name}\nPhone: {$lead->phone}\nEmail: {$lead->email}\nCity: {$lead->city}\nStudents: {$lead->students}\nPlan: ".($plan?->name ?? '—')
                    ."\nPreferred: {$lead->preferred_date?->format('d M Y')} {$lead->preferred_time}\n\n{$lead->message}", fn ($m) => $m->to($to)->subject("New {$lead->type} request: {$lead->library_name}"));
            } catch (\Throwable) {
                // mail is not set up yet: the request is safely stored and shown in the admin panel
            }
        }

        $this->finish($type, false);
    }

    protected function finish(string $type, bool $already): void
    {
        $this->done = true;
        $this->doneNote = $already ? 'We already have your request, and we will contact you shortly.' : null;
        $this->type = $type;
        $this->reset('name', 'email', 'phone', 'library_name', 'city', 'students', 'preferred_date', 'preferred_time', 'message', 'website');
    }

    public function again(): void
    {
        $this->done = false;
        $this->doneNote = null;
    }

    public function render()
    {
        return view('livewire.lead-form', [
            'plans' => SubscriptionPlan::where('status', 'active')->orderBy('price_monthly')->get(['id', 'name']),
            'offer' => \App\Http\Controllers\HomeController::offerLabel((int) BillingSettings::get('offer_free_days')),
        ]);
    }
}
