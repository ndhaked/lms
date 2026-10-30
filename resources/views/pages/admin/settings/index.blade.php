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

    // themes
    public array $themesEnabled = [];
    public string $defaultTheme = 'default';

    // referral program
    public bool $referral_enabled = true;
    public string $referral_reward_coins = '100';
    public string $coin_value = '1';
    public string $referral_redeem_percent = '20';

    // razorpay
    public bool $rzp_enabled = false;
    public string $rzp_key_id = '';
    public string $rzp_key_secret = '';       // never filled back in: a blank keeps the saved one
    public string $rzp_webhook_secret = '';
    public string $rzpResult = '';

    // mobile app push notifications (secrets are never filled back in: a blank keeps the saved one)
    public bool $push_enabled = true;
    public string $api_docs = 'auto';
    public array $appVersions = ['android' => ['latest' => '', 'minimum' => '', 'store_url' => ''], 'ios' => ['latest' => '', 'minimum' => '', 'store_url' => '']];
    public string $app_update_message = '';
    public string $push_fcm_project_id = '';
    public string $push_fcm_credentials = '';
    public string $push_expo_access_token = '';

    // messaging providers
    public array $activeProvider = ['sms' => 'none', 'whatsapp' => 'none'];
    public array $provCfg = [];
    public array $provTemplates = [];   // provider key => event => field => value
    public string $testChannel = 'sms';
    public string $testEvent = 'joined';
    public string $testMobile = '';
    public string $testResult = '';
    // message templates
    public array $smsRows = [];
    public array $waRows = [];

    // help centre
    public array $faqRows = [];
    public string $help_full_video = '';
    // system
    public string $upload_limit_kb = '4096';
    public string $maintenance_notice = '';
    public string $retention_policy = '';

    public function mount(): void
    {
        $this->authorizeManage();

        $s = BillingSettings::all();
        $this->help_full_video = (string) $s['help_full_video'];
        $this->smsRows = \App\Support\MessageTemplates::sms();
        $this->waRows = \App\Support\MessageTemplates::whatsappStarter();
        $this->loadMessaging();
        $this->rzp_enabled = (bool) $s['razorpay_enabled'];
        $this->push_enabled = (bool) $s['push_enabled'];
        $this->api_docs = in_array($s['api_docs'], ['auto', 'on', 'off'], true) ? $s['api_docs'] : 'auto';
        foreach (array_keys(\App\Support\Api\AppVersion::PLATFORMS) as $p) {
            $this->appVersions[$p] = \App\Support\Api\AppVersion::config($p);
        }
        $this->app_update_message = (string) $s['app_update_message'];
        $this->push_fcm_project_id = (string) $s['push_fcm_project_id'];
        $this->rzp_key_id = (string) $s['razorpay_key_id'];
        $this->loadFaqRows();
        $this->themesEnabled = \App\Support\Themes::enabled();
        $this->defaultTheme = \App\Support\Themes::platformDefault();
        $this->fill([
            'referral_enabled' => (bool) $s['referral_enabled'], 'referral_reward_coins' => (string) $s['referral_reward_coins'],
            'coin_value' => (string) $s['coin_value'], 'referral_redeem_percent' => (string) $s['referral_redeem_percent'],
        ]);        $this->fill([
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
            'payment_methods.*' => ['in:'.implode(',', array_keys(SubscriptionPayment::MANUAL_METHODS))],
            'payment_instructions' => ['nullable', 'string', 'max:2000'],
        ], ['payment_methods.min' => 'Enable at least one payment method.']);

        $this->persist('payments', [
            'payment_methods' => array_values($d['payment_methods']), 'payment_instructions' => (string) $d['payment_instructions'],
            'proof_required' => $this->proof_required, 'require_exact_amount' => $this->require_exact_amount,
        ]);
    }

    protected function loadFaqRows(): void
    {
        $this->faqRows = collect(\App\Support\HelpContent::faqs())->map(fn ($f) => [
            'key' => $f['key'], 'icon' => $f['icon'], 'q_en' => $f['en'][0], 'a_en' => $f['en'][1], 'q_hi' => $f['hi'][0], 'a_hi' => $f['hi'][1], 'video' => $f['video'],
        ])->all();
    }

    public function addFaq(): void
    {
        $this->faqRows[] = ['key' => 'q'.substr(md5(uniqid('', true)), 0, 8), 'icon' => 'chat', 'q_en' => '', 'a_en' => '', 'q_hi' => '', 'a_hi' => '', 'video' => ''];
    }

    public function removeFaq(int $i): void
    {
        unset($this->faqRows[$i]);
        $this->faqRows = array_values($this->faqRows);
    }

    public function moveFaq(int $i, int $by): void
    {
        $j = $i + $by;
        if (isset($this->faqRows[$i], $this->faqRows[$j])) {
            [$this->faqRows[$i], $this->faqRows[$j]] = [$this->faqRows[$j], $this->faqRows[$i]];
        }
    }

    public function resetFaqs(): void
    {
        $this->authorizeManage();
        $this->persist('help', ['help_faqs' => [], 'help_videos' => []]);
        BillingSettings::flush();
        $this->loadFaqRows();
    }

    public function saveHelp(): void
    {
        $this->authorizeManage();
        $this->validate([
            'faqRows' => ['required', 'array', 'min:1', 'max:40'],
            'faqRows.*.icon' => ['required', 'in:'.implode(',', \App\Support\HelpContent::ICONS)],
            'faqRows.*.q_en' => ['required', 'string', 'max:200'], 'faqRows.*.a_en' => ['required', 'string', 'max:1500'],
            'faqRows.*.q_hi' => ['nullable', 'string', 'max:300'], 'faqRows.*.a_hi' => ['nullable', 'string', 'max:2000'],
            'faqRows.*.video' => ['nullable', 'url:https', 'max:300'],
            'help_full_video' => ['nullable', 'url:https', 'max:300'],
        ], [
            'faqRows.*.q_en.required' => 'Every question needs an English title.', 'faqRows.*.a_en.required' => 'Every question needs an English answer.',
            'faqRows.*.video.url' => 'Video links must be full https links.', 'help_full_video.url' => 'Enter a full https link.',
        ]);

        $faqs = collect($this->faqRows)->map(fn ($r) => [
            'key' => (string) $r['key'], 'icon' => $r['icon'], 'en' => [trim($r['q_en']), trim($r['a_en'])],
            'hi' => [trim((string) $r['q_hi']), trim((string) $r['a_hi'])], 'video' => trim((string) $r['video']),
        ])->values()->all();

        $this->persist('help', ['help_faqs' => $faqs, 'help_videos' => [], 'help_full_video' => (string) $this->help_full_video]);
    }

    protected function loadMessaging(): void
    {
        $active = (array) BillingSettings::get('messaging_active');
        $this->activeProvider = ['sms' => (string) ($active['sms'] ?? 'none'), 'whatsapp' => (string) ($active['whatsapp'] ?? 'none')];

        foreach (\App\Support\Messaging\ProviderRegistry::all() as $key => $p) {
            $cfg = \App\Support\Messaging\ProviderRegistry::config($key);
            foreach ($p->fields() as $f) {
                $value = $f['type'] === 'secret' ? '' : (string) ($cfg[$f['key']] ?? '');   // secrets are never shown again
                $this->provCfg[$key][$f['key']] = $value === '' && $f['type'] === 'select' ? (string) array_key_first($f['options']) : $value;
            }
        }
        foreach (\App\Support\Messaging\ProviderRegistry::all() as $key => $p) {
            if (! $p->usesEventTemplates()) {
                continue;
            }
            $saved = (array) (\App\Support\Messaging\ProviderRegistry::config($key)['templates'] ?? []);
            foreach (array_keys(\App\Support\Messaging::EVENTS) as $event) {
                foreach ($p->templateFields() as $tf) {
                    $this->provTemplates[$key][$event][$tf['key']] = (string) ($saved[$event][$tf['key']] ?? '');
                }
            }
        }
    }

    public function saveRazorpay(): void
    {
        $this->authorizeManage();
        $this->validate([
            'rzp_key_id' => ['nullable', 'regex:/^rzp_(test|live)_[A-Za-z0-9]{8,30}$/'],
            'rzp_key_secret' => ['nullable', 'string', 'max:100'],
            'rzp_webhook_secret' => ['nullable', 'string', 'max:100'],
        ], ['rzp_key_id.regex' => 'The key id looks like rzp_test_… or rzp_live_…']);

        $hasSecret = filled($this->rzp_key_secret) || BillingSettings::get('razorpay_key_secret') !== '';
        if ($this->rzp_enabled && (blank($this->rzp_key_id) || ! $hasSecret)) {
            $this->addError('rzp_key_id', 'Enter the key id and key secret before switching online payment on.');

            return;
        }

        $values = ['razorpay_enabled' => $this->rzp_enabled, 'razorpay_key_id' => trim($this->rzp_key_id)];
        if (filled($this->rzp_key_secret)) {
            $values['razorpay_key_secret'] = \App\Support\Razorpay::encrypt(trim($this->rzp_key_secret));
        }
        if (filled($this->rzp_webhook_secret)) {
            $values['razorpay_webhook_secret'] = \App\Support\Razorpay::encrypt(trim($this->rzp_webhook_secret));
        }

        $this->persist('razorpay', $values);
        $this->reset(['rzp_key_secret', 'rzp_webhook_secret']);
        $this->rzpResult = '';
    }

    /** Push notifications for the mobile app: Expo works with no keys; Firebase (FCM) needs the project id and a service-account key. */
    public function savePush(): void
    {
        $this->validate([
            'push_fcm_project_id' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9\-]*$/'],
            'push_fcm_credentials' => ['nullable', 'string', 'max:20000', 'json'],
            'push_expo_access_token' => ['nullable', 'string', 'max:200'],
            'api_docs' => ['required', 'in:auto,on,off'],
            'appVersions.*.latest' => ['nullable', 'regex:/^\d+(\.\d+){0,3}$/'],
            'appVersions.*.minimum' => ['nullable', 'regex:/^\d+(\.\d+){0,3}$/'],
            'appVersions.*.store_url' => ['nullable', 'url:https', 'max:300'],
            'app_update_message' => ['nullable', 'string', 'max:300'],
        ], ['push_fcm_project_id.regex' => 'The Firebase project id uses lower-case letters, numbers and dashes.', 'appVersions.*.latest.regex' => 'Write versions like 1.4.2.', 'appVersions.*.minimum.regex' => 'Write versions like 1.4.2.', 'appVersions.*.store_url.url' => 'The store link must start with https://.']);

        foreach ($this->appVersions as $p => $v) {
            if (filled($v['minimum']) && filled($v['latest']) && version_compare($v['minimum'], $v['latest'], '>')) {
                $this->addError("appVersions.{$p}.minimum", 'The minimum version cannot be newer than the latest version.');

                return;
            }
        }

        if (filled($this->push_fcm_credentials)) {
            $sa = json_decode($this->push_fcm_credentials, true);
            if (! is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
                $this->addError('push_fcm_credentials', 'Paste the whole service-account JSON file from Firebase (it has client_email and private_key).');

                return;
            }
        }

        $values = ['push_enabled' => $this->push_enabled, 'push_fcm_project_id' => trim($this->push_fcm_project_id), 'api_docs' => $this->api_docs,
            'app_versions' => collect($this->appVersions)->map(fn ($v) => ['latest' => trim((string) $v['latest']), 'minimum' => trim((string) $v['minimum']), 'store_url' => trim((string) $v['store_url'])])->all(),
            'app_update_message' => trim($this->app_update_message)];
        if (filled($this->push_fcm_credentials)) {
            $values['push_fcm_credentials'] = \App\Support\Push\PushSender::encrypt(trim($this->push_fcm_credentials));
        }
        if (filled($this->push_expo_access_token)) {
            $values['push_expo_access_token'] = \App\Support\Push\PushSender::encrypt(trim($this->push_expo_access_token));
        }

        $this->persist('push', $values);
        $this->reset(['push_fcm_credentials', 'push_expo_access_token']);
    }

    public function testRazorpay(): void
    {
        $this->authorizeManage();
        BillingSettings::flush();
        [, $this->rzpResult] = \App\Support\Razorpay::keyId() === '' || \App\Support\Razorpay::secret() === '' ? [false, 'Save the key id and key secret first.'] : \App\Support\Razorpay::testKeys();
    }

    public function saveMessaging(): void
    {
        $this->authorizeManage();
        $registry = \App\Support\Messaging\ProviderRegistry::all();

        $this->validate([
            'activeProvider.sms' => ['required', 'in:none,'.implode(',', array_keys(\App\Support\Messaging\ProviderRegistry::forChannel('sms')))],
            'activeProvider.whatsapp' => ['required', 'in:none,'.implode(',', array_keys(\App\Support\Messaging\ProviderRegistry::forChannel('whatsapp')))],
        ]);

        // a provider that is switched on must have what it needs
        foreach (array_unique(array_filter($this->activeProvider, fn ($k) => $k !== 'none')) as $key) {
            foreach ($registry[$key]->fields() as $f) {
                if (! empty($f['required']) && trim((string) ($this->provCfg[$key][$f['key']] ?? '')) === '' && ! ($f['type'] === 'secret' && \App\Support\Messaging\ProviderRegistry::hasSecret($key, $f['key']))) {
                    $this->addError("provCfg.{$key}.{$f['key']}", "{$registry[$key]->label()}: {$f['label']} is required.");

                    return;
                }
                if ($f['type'] === 'select' && ! array_key_exists((string) ($this->provCfg[$key][$f['key']] ?? ''), $f['options'])) {
                    $this->addError("provCfg.{$key}.{$f['key']}", "{$registry[$key]->label()}: choose a {$f['label']}.");

                    return;
                }
                if ($f['type'] === 'url' && filled($this->provCfg[$key][$f['key']] ?? '') && ! str_starts_with((string) $this->provCfg[$key][$f['key']], 'https://')) {
                    $this->addError("provCfg.{$key}.{$f['key']}", 'The link must start with https://');

                    return;
                }
            }
        }

        foreach ($registry as $key => $p) {
            if ($p->fields() === [] && ! $p->usesEventTemplates()) {
                continue;
            }
            $values = $this->provCfg[$key] ?? [];
            if ($p->usesEventTemplates()) {
                $values['templates'] = $this->provTemplates[$key] ?? [];
            }
            \App\Support\Messaging\ProviderRegistry::save($key, $values);
        }

        $this->persist('messaging', ['messaging_active' => $this->activeProvider]);
        $this->loadMessaging();
    }

    /** Sends one real message through the provider chosen for a channel, to prove it works. The library's credits are not touched. */
    public function sendTest(): void
    {
        $this->authorizeManage();
        $this->validate(['testMobile' => ['required', 'regex:/^[0-9+\-() ]{7,20}$/'], 'testChannel' => ['required', 'in:sms,whatsapp'], 'testEvent' => ['required', 'in:'.implode(',', array_keys(\App\Support\Messaging::EVENTS))]],
            ['testMobile.regex' => 'Enter a valid mobile number.']);
        BillingSettings::flush();

        if (! \App\Support\Messaging::connected($this->testChannel)) {
            $this->testResult = 'Switch '.($this->testChannel === 'sms' ? 'SMS' : 'WhatsApp').' to a provider and save first.';

            return;
        }

        $vars = ['name' => 'Asha', 'library' => 'Demo Library', 'date' => now()->format('d M Y'), 'amount' => '₹500.00', 'plan' => 'Monthly', 'due' => '₹1,500.00'];
        $text = \App\Support\MessageTemplates::fill($this->testChannel === 'sms'
            ? (collect(\App\Support\MessageTemplates::sms())->firstWhere('name', \App\Support\Messaging::EVENTS[$this->testEvent][2])['message'] ?? 'Test message')
            : (collect(\App\Support\MessageTemplates::whatsappStarter())->firstWhere('name', \App\Support\Messaging::EVENTS[$this->testEvent][3])['message'] ?? 'Test message'), $vars);

        [$ok, $error] = \App\Support\Messaging::deliverPublic($this->testChannel, $this->testMobile, $text, $this->testEvent, $vars);
        $this->testResult = $ok ? 'Accepted by the provider. Check the phone '.$this->testMobile.'.' : 'Failed: '.$error;
    }
    public function addTemplate(string $kind): void
    {
        $this->authorizeManage();
        abort_unless(in_array($kind, ['sms', 'wa'], true), 404);
        $this->{$kind.'Rows'}[] = ['name' => '', 'message' => ''];
    }

    public function removeTemplate(string $kind, int $i): void
    {
        $this->authorizeManage();
        abort_unless(in_array($kind, ['sms', 'wa'], true), 404);
        unset($this->{$kind.'Rows'}[$i]);
        $this->{$kind.'Rows'} = array_values($this->{$kind.'Rows'});
    }

    public function resetTemplates(): void
    {
        $this->authorizeManage();
        $this->persist('templates', ['sms_templates' => [], 'whatsapp_default_templates' => []]);
        BillingSettings::flush();
        $this->smsRows = \App\Support\MessageTemplates::sms();
        $this->waRows = \App\Support\MessageTemplates::whatsappStarter();
    }

    public function saveTemplates(): void
    {
        $this->authorizeManage();
        $this->validate([
            'smsRows' => ['required', 'array', 'min:1', 'max:40'], 'smsRows.*.name' => ['required', 'string', 'max:80'], 'smsRows.*.message' => ['required', 'string', 'max:600'],
            'waRows' => ['required', 'array', 'min:1', 'max:40'], 'waRows.*.name' => ['required', 'string', 'max:80'], 'waRows.*.message' => ['required', 'string', 'max:1000'],
        ], ['smsRows.*.name.required' => 'Every SMS template needs a name.', 'smsRows.*.message.required' => 'Every SMS template needs a message.',
            'waRows.*.name.required' => 'Every WhatsApp template needs a name.', 'waRows.*.message.required' => 'Every WhatsApp template needs a message.']);

        $clean = fn (array $rows) => array_values(array_map(fn ($r) => ['name' => trim($r['name']), 'message' => trim($r['message'])], $rows));
        $this->persist('templates', ['sms_templates' => $clean($this->smsRows), 'whatsapp_default_templates' => $clean($this->waRows)]);
    }

    public function saveThemes(): void
    {
        $this->authorizeManage();
        $this->validate(['themesEnabled' => ['required', 'array', 'min:1'], 'themesEnabled.*' => ['in:'.implode(',', array_keys(\App\Support\Themes::ALL))], 'defaultTheme' => ['required', 'in:'.implode(',', array_keys(\App\Support\Themes::ALL))]],
            ['themesEnabled.min' => 'Keep at least one theme on.', 'themesEnabled.required' => 'Keep at least one theme on.']);

        if (! in_array($this->defaultTheme, $this->themesEnabled, true)) {
            $this->addError('defaultTheme', 'The default theme must be one of the themes that are on.');

            return;
        }

        $this->persist('themes', ['themes_enabled' => array_values($this->themesEnabled), 'default_theme' => $this->defaultTheme]);
    }

    public function saveReferral(): void
    {
        $this->authorizeManage();
        $d = $this->validate([
            'referral_reward_coins' => ['required', 'integer', 'between:0,100000'],
            'coin_value' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'referral_redeem_percent' => ['required', 'integer', 'between:0,100'],
        ]);

        $this->persist('referral', [
            'referral_enabled' => $this->referral_enabled, 'referral_reward_coins' => (int) $d['referral_reward_coins'],
            'coin_value' => (float) $d['coin_value'], 'referral_redeem_percent' => (int) $d['referral_redeem_percent'],
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

    @php $tabs = ['general' => 'General', 'subscription' => 'Subscriptions', 'modules' => 'Add-on modules', 'payments' => 'Payments', 'themes' => 'Themes', 'referral' => 'Referral', 'razorpay' => 'Razorpay', 'mobile' => 'Mobile app', 'messaging' => 'Messaging', 'templates' => 'Message templates', 'help' => 'Help centre', 'system' => 'System']; @endphp

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

        @elseif ($tab === 'themes')
            <form wire:submit="saveThemes" class="space-y-4">
                <p class="text-sm text-gray-600">Choose which colour themes libraries can pick from, and the theme every library starts with. A library that already uses a theme you switch off keeps it. You can also set one library's theme from its Edit page.</p>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach (\App\Support\Themes::ALL as $key => $t)
                        <div class="rounded-xl border p-3 {{ $defaultTheme === $key ? 'border-brand-500 ring-1 ring-brand-200' : 'border-gray-200' }}" wire:key="th-{{ $key }}">
                            <span class="block h-12 rounded-lg" style="background: {{ \App\Support\Themes::swatch($key) }}"></span>
                            <p class="mt-2 text-sm font-semibold text-gray-900">{{ $t['label'] }}</p>
                            <label class="mt-2 flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="themesEnabled" value="{{ $key }}" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> Libraries can pick it</label>
                            <label class="mt-1 flex items-center gap-2 text-sm text-gray-700"><input type="radio" wire:model="defaultTheme" value="{{ $key }}" class="border-gray-300 text-brand-600 focus:ring-brand-500"> Default for all libraries</label>
                        </div>
                    @endforeach
                </div>
                @error('themesEnabled') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                @error('defaultTheme') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <x-ui.button type="submit">Save themes</x-ui.button>
            </form>

        @elseif ($tab === 'referral')
            <form wire:submit="saveReferral" class="space-y-4">
                <p class="text-sm text-gray-600">A library earns coins when a library it referred has its first subscription payment approved. Coins are then a discount on subscription payments.</p>
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="referral_enabled" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Referral program is on</span><br><span class="text-xs text-gray-500">Off: no new coins are earned and coins cannot be used.</span></span></label>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.input name="referral_reward_coins" label="Coins earned per referral" type="number" min="0" wire:model="referral_reward_coins" />
                    <x-ui.input name="coin_value" label="Rupees one coin is worth" type="number" step="0.01" min="0.01" wire:model="coin_value" />
                    <x-ui.input name="referral_redeem_percent" label="Most of a price coins can pay (%)" type="number" min="0" max="100" wire:model="referral_redeem_percent" />
                </div>
                <x-ui.button type="submit">Save referral settings</x-ui.button>
            </form>

        @elseif ($tab === 'razorpay')
            @php $rzpHasSecret = BillingSettings::get('razorpay_key_secret') !== ''; $rzpHasHook = BillingSettings::get('razorpay_webhook_secret') !== ''; @endphp
            <form wire:submit="saveRazorpay" class="space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-gray-600">Let libraries pay for plans and SMS / WhatsApp credits online with Razorpay. Payments are checked with Razorpay before anything is activated.</p>
                    @if (\App\Support\Razorpay::enabled())<span class="rounded-full px-3 py-1 text-xs font-bold {{ \App\Support\Razorpay::mode() === 'live' ? 'bg-emerald-100 text-emerald-800' : 'bg-blue-100 text-blue-800' }}">On · {{ strtoupper(\App\Support\Razorpay::mode()) }} mode</span>@else<span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-bold text-gray-600">Off</span>@endif
                </div>
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="rzp_enabled" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Accept online payments</span><br><span class="text-xs text-gray-500">Off: the online option shows "Coming soon" and only Pay by Cash works.</span></span></label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="rzp_key_id" label="Key ID" placeholder="rzp_test_…" autocomplete="off" wire:model="rzp_key_id" hint="Razorpay dashboard → Account & Settings → API keys." />
                    <x-ui.input name="rzp_key_secret" label="Key secret" type="password" autocomplete="off" wire:model="rzp_key_secret" :placeholder="$rzpHasSecret ? '•••••• saved (leave blank to keep)' : ''" hint="Stored encrypted and never shown again." />
                    <x-ui.input name="rzp_webhook_secret" label="Webhook secret" type="password" autocomplete="off" wire:model="rzp_webhook_secret" :placeholder="$rzpHasHook ? '•••••• saved (leave blank to keep)' : ''" hint="The secret you type when you create the webhook in Razorpay." class="sm:col-span-2" />
                </div>

                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                    <p class="font-semibold text-gray-900">Set up the webhook in Razorpay</p>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-xs">
                        <li>Razorpay dashboard → Account &amp; Settings → Webhooks → Add new webhook.</li>
                        <li>Webhook URL: <code class="rounded bg-white px-1.5 py-0.5 font-mono text-[11px]">{{ route('webhooks.razorpay') }}</code> (must be a public https address; your local site cannot receive it).</li>
                        <li>Secret: type any long random text, and paste the same text in "Webhook secret" above.</li>
                        <li>Tick these events: <strong>payment.captured</strong>, <strong>payment.authorized</strong>, <strong>payment.failed</strong>, <strong>order.paid</strong>.</li>
                    </ol>
                    <p class="mt-2 text-xs text-gray-500">The webhook is what activates a plan when someone pays and then closes their browser. {{ $rzpHasHook ? 'A webhook secret is saved.' : 'No webhook secret is saved yet, so webhooks are refused.' }}</p>
                </div>

                @if ($errors->any()) <div class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div> @endif
                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.button type="submit">Save Razorpay settings</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="testRazorpay">Test the keys</x-ui.button>
                    @if ($rzpResult)<span class="text-sm {{ str_starts_with($rzpResult, 'Connected') ? 'text-emerald-700' : 'text-red-600' }}">{{ $rzpResult }}</span>@endif
                </div>
            </form>

        @elseif ($tab === 'mobile')
            @php $hasFcm = BillingSettings::get('push_fcm_credentials') !== ''; $hasExpo = BillingSettings::get('push_expo_access_token') !== ''; @endphp
            <form wire:submit="savePush" class="space-y-5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-gray-200 bg-white p-4"><p class="text-xs text-gray-500">App API address</p><p class="mt-1 break-all font-mono text-sm font-semibold text-gray-900">{{ url('/api/v1') }}</p></div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4"><p class="text-xs text-gray-500">Phones signed in</p><p class="mt-1 text-2xl font-extrabold text-gray-900">{{ \Laravel\Sanctum\PersonalAccessToken::count() }}</p></div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4"><p class="text-xs text-gray-500">Phones with notifications on</p><p class="mt-1 text-2xl font-extrabold text-gray-900">{{ \App\Models\DeviceToken::count() }}</p></div>
                </div>
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="push_enabled" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Send push notifications to the mobile app</span><br><span class="text-xs text-gray-500">Every in-app notification (the bell) is also sent to the person's phones.</span></span></label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="push_fcm_project_id" label="Firebase project id" wire:model="push_fcm_project_id" placeholder="my-library-app" hint="Only for apps that register Firebase (FCM) tokens." />
                    <x-ui.input name="push_expo_access_token" label="Expo access token (optional)" type="password" autocomplete="off" wire:model="push_expo_access_token" :placeholder="$hasExpo ? '•••••• saved (leave blank to keep)' : ''" hint="Only if 'enhanced push security' is on in your Expo account." />
                    <x-ui.textarea name="push_fcm_credentials" label="Firebase service-account key (JSON)" wire:model="push_fcm_credentials" class="sm:col-span-2" :placeholder="$hasFcm ? 'Saved (leave blank to keep)' : 'Paste the JSON file from Firebase → Project settings → Service accounts → Generate new private key'" />
                </div>
                <p class="text-xs text-gray-500">Keys are stored encrypted and never shown again. An app built with Expo needs no keys at all.</p>

                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <p class="text-sm font-semibold text-gray-900">App versions and force update</p>
                    <p class="mt-0.5 text-xs text-gray-500">Below the <b>minimum</b> version the app must update before it can be used (the API also refuses old apps). Below the <b>latest</b> version people are told an update is available but can skip it. Leave empty for no rule.</p>
                    <div class="mt-3 grid gap-4 lg:grid-cols-2">
                        @foreach (\App\Support\Api\AppVersion::PLATFORMS as $p => $label)
                            <div class="space-y-3 rounded-lg border border-gray-200 bg-white p-3">
                                <p class="text-sm font-bold text-gray-900">{{ $label }}</p>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <x-ui.input name="appVersions.{{ $p }}.latest" label="Latest version" placeholder="1.4.2" wire:model="appVersions.{{ $p }}.latest" />
                                    <x-ui.input name="appVersions.{{ $p }}.minimum" label="Minimum version (force update)" placeholder="1.2.0" wire:model="appVersions.{{ $p }}.minimum" />
                                </div>
                                <x-ui.input name="appVersions.{{ $p }}.store_url" label="Store link" :placeholder="$p === 'android' ? 'https://play.google.com/store/apps/details?id=…' : 'https://apps.apple.com/app/id…'" wire:model="appVersions.{{ $p }}.store_url" />
                            </div>
                        @endforeach
                    </div>
                    <x-ui.input name="app_update_message" label="Message on the force-update screen (optional)" placeholder="A new version of the app is required. Please update to continue." wire:model="app_update_message" class="mt-3" />
                </div>

                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <p class="text-sm font-semibold text-gray-900">API documentation (Swagger)</p>
                    <p class="mt-0.5 text-xs text-gray-500">A page where app developers can read and try every API call. It shows the API's structure, not any data or keys; calls still need a valid sign-in.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach (['auto' => 'Automatic (off on the live site)', 'on' => 'On', 'off' => 'Off'] as $k => $l)
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm {{ $api_docs === $k ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-gray-200 bg-white text-gray-700' }}"><input type="radio" wire:model.live="api_docs" value="{{ $k }}" class="text-brand-600 focus:ring-brand-500"> {{ $l }}</label>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs">@if (\App\Http\Controllers\Api\DocsController::enabled())<span class="font-semibold text-emerald-700">Open now:</span> <a href="{{ route('api.docs') }}" target="_blank" rel="noopener" class="font-mono text-brand-700 underline">{{ route('api.docs') }}</a>@else<span class="font-semibold text-gray-600">Hidden now</span> (the page answers "not found").@endif</p>
                </div>
                @if ($errors->any()) <div class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div> @endif
                <x-ui.button type="submit">Save mobile app settings</x-ui.button>
            </form>

        @elseif ($tab === 'messaging')
            @php
                $registry = \App\Support\Messaging\ProviderRegistry::all();
                $channelNames = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp'];
                $usedKeys = array_unique(array_filter($activeProvider, fn ($k) => $k !== 'none'));
            @endphp
            <form wire:submit="saveMessaging" class="space-y-6">
                <p class="text-sm text-gray-600">Choose which provider carries SMS and which carries WhatsApp. You can switch any time; libraries keep their own on/off choices and credits. Until a channel has a provider, nothing is sent and no credits are used.</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($channelNames as $ch => $chName)
                        <x-ui.select name="activeProvider.{{ $ch }}" label="{{ $chName }} provider" wire:model.live="activeProvider.{{ $ch }}">
                            <option value="none">Not connected (nothing is sent)</option>
                            @foreach (\App\Support\Messaging\ProviderRegistry::forChannel($ch) as $key => $p) <option value="{{ $key }}">{{ $p->label() }}</option> @endforeach
                        </x-ui.select>
                    @endforeach
                </div>

                @foreach ($registry as $key => $p)
                    @if ($p->fields() !== [] && in_array($key, $usedKeys, true))
                        <fieldset class="space-y-3 rounded-xl border border-gray-200 p-4" wire:key="prov-{{ $key }}">
                            <legend class="px-2 text-sm font-bold text-gray-900">{{ $p->label() }} settings</legend>
                            <div class="grid gap-4 sm:grid-cols-2">
                                @foreach ($p->fields() as $f)
                                    @if ($f['type'] === 'select')
                                        <x-ui.select name="provCfg.{{ $key }}.{{ $f['key'] }}" :label="$f['label']" wire:model="provCfg.{{ $key }}.{{ $f['key'] }}">
                                            @foreach ($f['options'] as $ov => $ol) <option value="{{ $ov }}">{{ $ol }}</option> @endforeach
                                        </x-ui.select>
                                    @else
                                        <x-ui.input name="provCfg.{{ $key }}.{{ $f['key'] }}" :label="$f['label']" :required="! empty($f['required'])"
                                            :type="$f['type'] === 'secret' ? 'password' : ($f['type'] === 'url' ? 'url' : 'text')" autocomplete="off" wire:model="provCfg.{{ $key }}.{{ $f['key'] }}"
                                            :placeholder="$f['type'] === 'secret' && \App\Support\Messaging\ProviderRegistry::hasSecret($key, $f['key']) ? '•••••• saved (leave blank to keep)' : ''"
                                            :hint="$f['hint'] ?? null" />
                                    @endif
                                @endforeach
                            </div>

                            @if ($p->usesEventTemplates())
                                @php $cols = array_values(array_filter($p->templateFields(), fn ($tf) => ($activeProvider[$tf['channel']] ?? 'none') === $key)); @endphp
                                @if ($cols)
                                    <div class="overflow-x-auto">
                                        <p class="mb-2 text-xs text-gray-600">Each automatic message needs its own {{ $p->label() }} template details. Variables are the placeholders that fill the template's numbered or named slots, in order: <code>name</code>, <code>date</code>, <code>amount</code>, <code>library</code>, <code>plan</code>, <code>due</code>.</p>
                                        <table class="w-full text-sm">
                                            <thead class="text-left text-xs text-gray-500"><tr><th class="pb-2 pr-3 font-medium">Message</th>@foreach ($cols as $tf)<th class="pb-2 pr-3 font-medium">{{ $tf['label'] }}<span class="font-normal text-gray-400"> · {{ $tf['channel'] === 'sms' ? 'SMS' : 'WhatsApp' }}</span></th>@endforeach</tr></thead>
                                            <tbody>
                                                @foreach (\App\Support\Messaging::EVENTS as $event => [$evLabel])
                                                    <tr class="border-t border-gray-100" wire:key="pt-{{ $key }}-{{ $event }}">
                                                        <td class="py-2 pr-3 font-medium text-gray-800">{{ $evLabel }}</td>
                                                        @foreach ($cols as $tf)
                                                            <td class="py-1.5 pr-3"><input type="text" wire:model="provTemplates.{{ $key }}.{{ $event }}.{{ $tf['key'] }}" class="w-full rounded-lg border-gray-300 text-sm" placeholder="{{ $tf['placeholder'] ?? '' }}"></td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            @endif
                        </fieldset>
                    @endif
                @endforeach
                @error('provCfg.*') @enderror
                @if ($errors->any()) <div class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div> @endif
                <x-ui.button type="submit">Save messaging</x-ui.button>
            </form>

            <div class="mt-6 space-y-3 border-t border-gray-100 pt-4">
                <h4 class="text-sm font-bold text-gray-900">Send a test message</h4>
                <div class="flex flex-wrap items-end gap-3">
                    <x-ui.select name="testChannel" label="Channel" wire:model="testChannel"><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option></x-ui.select>
                    <x-ui.select name="testEvent" label="Message" wire:model="testEvent">@foreach (\App\Support\Messaging::EVENTS as $ev => [$evl]) <option value="{{ $ev }}">{{ $evl }}</option> @endforeach</x-ui.select>
                    <x-ui.input name="testMobile" label="Mobile number" type="tel" wire:model="testMobile" />
                    <x-ui.button type="button" variant="secondary" wire:click="sendTest">Send test</x-ui.button>
                </div>
                @if ($testResult)<p class="text-sm {{ str_starts_with($testResult, 'Accepted') ? 'text-emerald-700' : 'text-red-600' }}">{{ $testResult }}</p>@endif
                <p class="text-xs text-gray-500">A test is not logged and uses none of a library's credits. For MSG91 it uses the template set for the message you pick.</p>
            </div>
        @elseif ($tab === 'templates')
            <form wire:submit="saveTemplates" class="space-y-6">
                <p class="text-sm text-gray-600">SMS templates are shared by every library, who can only read them. The WhatsApp set below is copied to each <strong>new</strong> library when it is onboarded; libraries then edit their own. Use {name} {library} {date} {amount} {plan} as placeholders.</p>
                @foreach (['sms' => ['SMS templates (all libraries)', 'smsRows'], 'wa' => ['WhatsApp templates (starter set for new libraries)', 'waRows']] as $kind => [$title, $prop])
                    <div class="space-y-3" wire:key="tpl-{{ $kind }}">
                        <h4 class="text-sm font-bold text-gray-900">{{ $title }}</h4>
                        @foreach ($this->{$prop} as $i => $row)
                            <div class="rounded-xl border border-gray-200 p-3" wire:key="tpl-{{ $kind }}-{{ $i }}">
                                <div class="flex items-start gap-2">
                                    <div class="grid flex-1 gap-2 sm:grid-cols-[14rem_1fr]">
                                        <x-ui.input name="{{ $prop }}.{{ $i }}.name" placeholder="Name" wire:model="{{ $prop }}.{{ $i }}.name" />
                                        <x-ui.textarea name="{{ $prop }}.{{ $i }}.message" rows="3" placeholder="Message" wire:model="{{ $prop }}.{{ $i }}.message" />
                                    </div>
                                    <button type="button" wire:click="removeTemplate('{{ $kind }}', {{ $i }})" wire:confirm="Remove this template?" class="rounded px-2 py-1 text-red-600 hover:bg-red-50" title="Remove">✕</button>
                                </div>
                            </div>
                        @endforeach
                        <x-ui.button type="button" variant="secondary" wire:click="addTemplate('{{ $kind }}')">+ Add {{ $kind === 'sms' ? 'SMS' : 'WhatsApp' }} template</x-ui.button>
                    </div>
                @endforeach
                @if ($errors->any()) <div class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div> @endif
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="submit">Save templates</x-ui.button>
                    <button type="button" wire:click="resetTemplates" wire:confirm="Replace both lists with the built-in templates?" class="ml-auto text-xs font-medium text-gray-500 hover:text-red-600">Reset to built-in templates</button>
                </div>
            </form>

        @elseif ($tab === 'help')
            <form wire:submit="saveHelp" class="space-y-4">
                <p class="text-sm text-gray-600">These are the questions on every library's Need Help? page. Add, reorder, translate or remove them. A question with a video link shows a Watch video button.</p>
                <x-ui.input name="help_full_video" label="Full walkthrough video (optional)" type="url" placeholder="https://" wire:model="help_full_video" />
                <div class="space-y-3">
                    @foreach ($faqRows as $i => $row)
                        <div class="rounded-xl border border-gray-200 p-4" wire:key="faq-{{ $row['key'] }}">
                            <div class="mb-3 flex items-center justify-between gap-2">
                                <span class="text-xs font-semibold text-gray-500">Question {{ $i + 1 }}</span>
                                <span class="flex items-center gap-1">
                                    <select wire:model="faqRows.{{ $i }}.icon" class="rounded-lg border-gray-300 py-1 text-xs">@foreach (\App\Support\HelpContent::ICONS as $ic) <option value="{{ $ic }}">{{ $ic }}</option> @endforeach</select>
                                    <button type="button" wire:click="moveFaq({{ $i }}, -1)" class="rounded px-2 py-1 text-gray-500 hover:bg-gray-100" title="Move up">↑</button>
                                    <button type="button" wire:click="moveFaq({{ $i }}, 1)" class="rounded px-2 py-1 text-gray-500 hover:bg-gray-100" title="Move down">↓</button>
                                    <button type="button" wire:click="removeFaq({{ $i }})" wire:confirm="Remove this question?" class="rounded px-2 py-1 text-red-600 hover:bg-red-50" title="Remove">✕</button>
                                </span>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <x-ui.input name="faqRows.{{ $i }}.q_en" label="Question (English)" wire:model="faqRows.{{ $i }}.q_en" />
                                <x-ui.input name="faqRows.{{ $i }}.q_hi" label="Question (हिन्दी)" wire:model="faqRows.{{ $i }}.q_hi" />
                                <x-ui.textarea name="faqRows.{{ $i }}.a_en" label="Answer (English)" rows="3" wire:model="faqRows.{{ $i }}.a_en" />
                                <x-ui.textarea name="faqRows.{{ $i }}.a_hi" label="Answer (हिन्दी)" rows="3" wire:model="faqRows.{{ $i }}.a_hi" />
                            </div>
                            <x-ui.input name="faqRows.{{ $i }}.video" label="Video link (optional)" type="url" placeholder="https://" wire:model="faqRows.{{ $i }}.video" class="mt-3" />
                        </div>
                    @endforeach
                </div>
                @error('faqRows') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="button" variant="secondary" wire:click="addFaq">+ Add question</x-ui.button>
                    <x-ui.button type="submit">Save help centre</x-ui.button>
                    <button type="button" wire:click="resetFaqs" wire:confirm="Replace every question with the built-in set?" class="ml-auto text-xs font-medium text-gray-500 hover:text-red-600">Reset to built-in questions</button>
                </div>
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
