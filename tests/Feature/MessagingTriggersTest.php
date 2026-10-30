<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\Billing\ApproveSubscriptionPayment;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\SubmitSubscriptionPayment;
use App\Actions\RecordPayment;
use App\Actions\RegisterLibrary;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\MessageLog;
use App\Models\Student;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\LibrarySettings;
use App\Support\Messaging;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MessagingTriggersTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Library $lib;
    protected User $owner;
    protected Student $student;
    protected MembershipPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->admin = $this->admin->fresh();
        $sub = SubscriptionPlan::create(['name' => 'Std', 'price_monthly' => 1000, 'trial_days' => 0, 'max_students' => 50, 'max_seats' => 50, 'max_staff' => 5, 'modules' => [], 'grace_days' => 7]);

        $this->actingAs($this->admin);
        $this->lib = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@trg.test']);
        $this->owner = User::where('email', 'o@trg.test')->first();
        app(CreateSubscription::class)($this->lib, $sub, 'monthly', false);
        Tenancy::set($this->lib);
        $this->actingAs($this->owner);
        $p = app(SubmitSubscriptionPayment::class)($this->lib, $sub, 'monthly', 'ACT', 1000, 'upi', today(), null, [UploadedFile::fake()->image('p.png')]);
        $this->actingAs($this->admin);
        app(ApproveSubscriptionPayment::class)($p);
        $this->actingAs($this->owner);

        $this->plan = MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 1000]);
        $this->student = Student::create(['name' => 'Asha', 'phone' => '9876543210', 'registered_on' => today(), 'status' => 'active']);
        $this->lib->forceFill(['sms_credits' => 50, 'whatsapp_credits' => 50])->save();
    }

    protected function tearDown(): void
    {
        BillingSettings::flush();
        LibrarySettings::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function driver(string $d): void
    {
        $this->actingAs($this->admin);
        BillingSettings::set(['messaging_active' => ['sms' => $d, 'whatsapp' => $d]]);
        $this->actingAs($this->owner);
    }

    protected function switchOn(string $event, string ...$channels): void
    {
        $t = LibrarySettings::get('message_triggers');
        foreach ($channels as $c) {
            $t[$event][$c] = true;
        }
        LibrarySettings::update(['message_triggers' => $t]);
    }

    public function test_nothing_is_sent_or_charged_until_a_provider_is_connected(): void
    {
        $this->switchOn('joined', 'sms');
        app(AssignMembership::class)($this->student, $this->plan, today());

        $this->assertSame(0, MessageLog::count());
        $this->assertSame(50, $this->lib->fresh()->sms_credits);
    }

    public function test_a_new_membership_sends_the_joining_message_with_the_students_own_details(): void
    {
        $this->driver('test');
        $this->switchOn('joined', 'sms', 'whatsapp');

        app(AssignMembership::class)($this->student, $this->plan, today());

        $sms = MessageLog::where('channel', 'sms')->firstOrFail();
        $this->assertSame(['9876543210', 'joined', 'sent'], [$sms->mobile, $sms->event, $sms->status]);
        $this->assertStringContainsString('Welcome Asha!', $sms->message);
        $this->assertStringContainsString(today()->format('d M Y'), $sms->message);
        $this->assertStringContainsString('Alpha', $sms->message);
        $this->assertStringNotContainsString('{', $sms->message, 'every placeholder is filled in');

        $wa = MessageLog::where('channel', 'whatsapp')->firstOrFail();
        $this->assertStringContainsString('Congratulations on your membership with Alpha', $wa->message);
        $this->assertSame([50 - $sms->count, 49], [$this->lib->fresh()->sms_credits, $this->lib->fresh()->whatsapp_credits]);
    }

    public function test_only_the_channels_the_library_switched_on_are_used(): void
    {
        $this->driver('test');
        $this->switchOn('joined', 'whatsapp');

        app(AssignMembership::class)($this->student, $this->plan, today());

        $this->assertSame(['whatsapp'], MessageLog::pluck('channel')->all());
        $this->assertSame(50, $this->lib->fresh()->sms_credits);
    }

    public function test_a_second_membership_is_a_renewal_and_a_payment_mentions_what_is_still_due(): void
    {
        $this->driver('test');
        $this->switchOn('renewed', 'sms');
        $this->switchOn('payment', 'sms');

        app(AssignMembership::class)($this->student, $this->plan, today());                    // first: "joined", which is off
        $this->assertSame(0, MessageLog::count());
        $second = app(AssignMembership::class)($this->student, $this->plan, today()->addDays(31));
        $this->assertSame('renewed', MessageLog::firstOrFail()->event);

        app(RecordPayment::class)($this->student, 400, 'cash', today(), $second);
        $payment = MessageLog::where('event', 'payment')->firstOrFail();
        $this->assertStringContainsString('₹400.00', $payment->message);
        $this->assertStringContainsString('₹1,600.00', $payment->message);                     // 2 x 1000 owed, 400 paid
    }

    public function test_a_provider_failure_returns_the_credits_and_never_breaks_the_action(): void
    {
        $this->driver('webhook');
        \App\Support\Messaging\ProviderRegistry::save('webhook', ['url' => 'https://sms.example.test/send']);
        Http::fake(['sms.example.test/*' => Http::response('nope', 500)]);
        $this->switchOn('joined', 'sms');

        $membership = app(AssignMembership::class)($this->student, $this->plan, today());   // still succeeds

        $this->assertNotNull($membership->id);
        $log = MessageLog::firstOrFail();
        $this->assertSame(['failed', 'Provider replied 500', 50], [$log->status, $log->error, $this->lib->fresh()->sms_credits]);
    }

    public function test_the_webhook_receives_the_message_and_the_token(): void
    {
        $this->driver('webhook');
        \App\Support\Messaging\ProviderRegistry::save('webhook', ['url' => 'https://sms.example.test/send', 'token' => 'secret-token']);
        Http::fake(['sms.example.test/*' => Http::response(['ok' => true], 200)]);
        $this->switchOn('joined', 'sms');

        app(AssignMembership::class)($this->student, $this->plan, today());

        Http::assertSent(fn ($r) => $r->url() === 'https://sms.example.test/send' && $r->hasHeader('Authorization', 'Bearer secret-token')
            && $r['channel'] === 'sms' && $r['to'] === '919876543210' && $r['event'] === 'joined' && str_contains($r['message'], 'Welcome Asha'));
        $this->assertSame('sent', MessageLog::firstOrFail()->status);
    }

    public function test_not_enough_credits_logs_a_failure_and_sends_nothing(): void
    {
        $this->driver('test');
        $this->lib->forceFill(['sms_credits' => 0])->save();
        $this->switchOn('joined', 'sms');

        app(AssignMembership::class)($this->student, $this->plan, today());

        $log = MessageLog::firstOrFail();
        $this->assertSame(['failed', 'Not enough credits'], [$log->status, $log->error]);
    }

    public function test_a_student_without_a_phone_is_skipped(): void
    {
        $this->driver('test');
        $this->switchOn('joined', 'sms');
        $this->student->forceFill(['phone' => ''])->save();

        app(AssignMembership::class)($this->student, $this->plan, today());

        $this->assertSame(0, MessageLog::count());
    }

    public function test_the_daily_reminders_message_expiring_memberships_once_and_unpaid_dues_weekly(): void
    {
        $this->driver('test');
        $this->switchOn('expiring', 'sms');
        $this->switchOn('due', 'whatsapp');
        $soon = app(AssignMembership::class)($this->student, $this->plan, today()->subDays(27));    // ends in 2-3 days
        $this->assertSame(0, MessageLog::count());

        Artisan::call('library:send-reminders', ['--library' => $this->lib->id]);
        Artisan::call('library:send-reminders', ['--library' => $this->lib->id]);                   // same day again

        $this->assertSame(1, MessageLog::where('event', 'expiring')->count(), 'told once only');
        $this->assertStringContainsString('Asha', MessageLog::where('event', 'expiring')->first()->message);

        // an ended, unpaid membership is chased once a week
        $old = Student::create(['name' => 'Ravi', 'phone' => '9000000009', 'registered_on' => today()->subMonths(3), 'status' => 'active']);
        app(AssignMembership::class)($old, $this->plan, today()->subDays(60));
        Artisan::call('library:send-reminders', ['--library' => $this->lib->id]);
        Artisan::call('library:send-reminders', ['--library' => $this->lib->id]);
        $this->assertSame(1, MessageLog::where('event', 'due')->where('mobile', '9000000009')->count());
    }

    public function test_the_library_switches_messages_on_from_the_templates_page_but_only_with_permission(): void
    {
        Livewire::test('pages::app.templates.index')->call('toggle', 'payment', 'sms');
        $this->assertTrue(Messaging::enabled('payment', 'sms'));
        $this->assertFalse(Messaging::enabled('payment', 'whatsapp'));
        Livewire::test('pages::app.templates.index')->call('toggle', 'payment', 'sms');
        $this->assertFalse(Messaging::enabled('payment', 'sms'));

        $desk = User::factory()->create();
        $this->lib->users()->attach($desk->id, ['is_active' => true]);
        Tenancy::run($this->lib, fn () => $desk->assignRole(\App\Support\LibraryRoles::SUPERVISOR));
        $this->actingAs($desk->fresh());
        Livewire::test('pages::app.templates.index')->assertForbidden();                       // no billing.view either
    }

    public function test_super_admin_connects_a_provider_and_can_send_a_test(): void
    {
        $this->actingAs($this->admin);
        Http::fake(['sms.example.test/*' => Http::response('', 202)]);

        $page = Livewire::test('pages::admin.settings.index')->set('tab', 'messaging');
        $page->set('activeProvider.sms', 'webhook')->call('saveMessaging')->assertHasErrors('provCfg.webhook.url');
        $page->set('provCfg.webhook.url', 'http://insecure.example.test')->call('saveMessaging')->assertHasErrors('provCfg.webhook.url');
        $page->set('provCfg.webhook.url', 'https://sms.example.test/send')->call('saveMessaging')->assertHasNoErrors();
        BillingSettings::flush();
        $this->assertTrue(Messaging::connected('sms'));
        $this->assertFalse(Messaging::connected('whatsapp'));

        $page->set('testMobile', '9876543210')->call('sendTest')->assertSet('testResult', 'Accepted by the provider. Check the phone 9876543210.');
        Http::assertSent(fn ($r) => $r['event'] === 'joined' && $r['to'] === '919876543210' && str_contains($r['message'], 'Welcome Asha'));
        $this->assertSame(0, MessageLog::withoutGlobalScopes()->count(), 'a test is not logged or charged');

        $page->set('testChannel', 'whatsapp')->call('sendTest')->assertSet('testResult', 'Switch WhatsApp to a provider and save first.');
    }

    public function test_sms_and_whatsapp_can_use_different_providers_and_be_switched(): void
    {
        $this->actingAs($this->admin);
        \App\Support\Messaging\ProviderRegistry::save('webhook', ['url' => 'https://sms.example.test/send']);
        BillingSettings::set(['messaging_active' => ['sms' => 'webhook', 'whatsapp' => 'test']]);

        $this->assertSame(['webhook', 'test'], [Messaging::provider('sms')->key(), Messaging::provider('whatsapp')->key()]);

        BillingSettings::set(['messaging_active' => ['sms' => 'test', 'whatsapp' => 'none']]);
        $this->assertSame('test', Messaging::provider('sms')->key());
        $this->assertNull(Messaging::provider('whatsapp'));
    }

    public function test_provider_secrets_are_stored_encrypted_and_a_blank_keeps_the_saved_one(): void
    {
        $this->actingAs($this->admin);
        \App\Support\Messaging\ProviderRegistry::save('msg91', ['authkey' => 'PLAIN-SECRET-123', 'whatsapp_number' => '911234567890', 'templates' => ['joined' => ['sms_template_id' => 'tpl1']]]);

        $raw = BillingSettings::get('messaging_providers')['msg91']['authkey'];
        $this->assertNotSame('PLAIN-SECRET-123', $raw);
        $this->assertStringNotContainsString('PLAIN-SECRET', json_encode(\Illuminate\Support\Facades\DB::table('subscription_settings')->get()->all()));
        $this->assertSame('PLAIN-SECRET-123', \App\Support\Messaging\ProviderRegistry::config('msg91')['authkey']);

        \App\Support\Messaging\ProviderRegistry::save('msg91', ['authkey' => '', 'whatsapp_number' => '911111111111']);      // blank secret = keep
        $this->assertSame('PLAIN-SECRET-123', \App\Support\Messaging\ProviderRegistry::config('msg91')['authkey']);
        $this->assertSame('911111111111', \App\Support\Messaging\ProviderRegistry::config('msg91')['whatsapp_number']);
    }

    public function test_msg91_sms_sends_the_template_id_and_the_variables_with_the_auth_key(): void
    {
        $this->actingAs($this->admin);
        \App\Support\Messaging\ProviderRegistry::save('msg91', ['authkey' => 'KEY-1', 'templates' => ['joined' => ['sms_template_id' => 'TPL-JOIN']]]);
        BillingSettings::set(['messaging_active' => ['sms' => 'msg91', 'whatsapp' => 'none']]);
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success', 'message' => 'abc'], 200)]);
        $this->actingAs($this->owner);
        $this->switchOn('joined', 'sms');

        app(AssignMembership::class)($this->student, $this->plan, today());

        Http::assertSent(fn ($r) => $r->url() === 'https://control.msg91.com/api/v5/flow/' && $r->hasHeader('authkey', 'KEY-1')
            && $r['template_id'] === 'TPL-JOIN' && $r['recipients'][0]['mobiles'] === '919876543210'
            && $r['recipients'][0]['name'] === 'Asha' && $r['recipients'][0]['library'] === 'Alpha' && $r['recipients'][0]['plan'] === 'Monthly');
        $this->assertSame('sent', MessageLog::firstOrFail()->status);
    }

    public function test_msg91_reports_missing_template_and_its_own_errors_and_returns_credits(): void
    {
        $this->actingAs($this->admin);
        \App\Support\Messaging\ProviderRegistry::save('msg91', ['authkey' => 'KEY-1']);                    // no template ids yet
        BillingSettings::set(['messaging_active' => ['sms' => 'msg91', 'whatsapp' => 'none']]);
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'error', 'message' => 'Template not approved'], 200)]);
        $this->actingAs($this->owner);
        $this->switchOn('joined', 'sms');

        app(AssignMembership::class)($this->student, $this->plan, today());
        $first = MessageLog::firstOrFail();
        $this->assertSame(['failed', 'No MSG91 SMS template id is set for this message', 50], [$first->status, $first->error, $this->lib->fresh()->sms_credits]);
        Http::assertNothingSent();

        $this->actingAs($this->admin);
        \App\Support\Messaging\ProviderRegistry::save('msg91', ['templates' => ['renewed' => ['sms_template_id' => 'TPL-R']]]);
        $this->actingAs($this->owner);
        $this->switchOn('renewed', 'sms');
        app(AssignMembership::class)($this->student, $this->plan, today()->addDays(31));
        $second = MessageLog::where('event', 'renewed')->firstOrFail();
        $this->assertSame(['failed', 'MSG91: Template not approved', 50], [$second->status, $second->error, $this->lib->fresh()->sms_credits]);
    }

    public function test_msg91_whatsapp_sends_the_template_with_variables_in_the_listed_order(): void
    {
        $this->actingAs($this->admin);
        \App\Support\Messaging\ProviderRegistry::save('msg91', ['authkey' => 'KEY-1', 'whatsapp_number' => '+91 98765 00000', 'whatsapp_namespace' => 'ns_1', 'whatsapp_language' => 'en_US',
            'templates' => ['joined' => ['wa_template' => 'welcome_member', 'wa_vars' => 'name, library, date']]]);
        BillingSettings::set(['messaging_active' => ['sms' => 'none', 'whatsapp' => 'msg91']]);
        Http::fake(['control.msg91.com/*' => Http::response(['status' => 'success'], 200)]);
        $this->actingAs($this->owner);
        $this->switchOn('joined', 'whatsapp');

        app(AssignMembership::class)($this->student, $this->plan, today());

        Http::assertSent(function ($r) {
            $t = $r['payload']['template'] ?? [];

            return str_contains($r->url(), 'whatsapp-outbound-message') && $r->hasHeader('authkey', 'KEY-1') && $r['integrated_number'] === '919876500000'
                && $t['name'] === 'welcome_member' && $t['namespace'] === 'ns_1' && $t['language']['code'] === 'en_US'
                && $t['to_and_components'][0]['to'] === ['919876543210']
                && $t['to_and_components'][0]['components']['body_1']['value'] === 'Asha' && $t['to_and_components'][0]['components']['body_2']['value'] === 'Alpha';
        });
        $this->assertSame('sent', MessageLog::firstOrFail()->status);
    }

    public function test_the_providers_settings_are_saved_through_the_admin_page_and_secrets_are_never_shown_back(): void
    {
        $this->actingAs($this->admin);
        $page = Livewire::test('pages::admin.settings.index')->set('tab', 'messaging');

        $page->set('activeProvider.sms', 'msg91')->call('saveMessaging')->assertHasErrors('provCfg.msg91.authkey');
        $page->set('provCfg.msg91.authkey', 'KEY-ADMIN')->set('provTemplates.msg91.joined.sms_template_id', 'TPL-9')->call('saveMessaging')->assertHasNoErrors();
        BillingSettings::flush();

        $this->assertSame('KEY-ADMIN', \App\Support\Messaging\ProviderRegistry::config('msg91')['authkey']);
        $this->assertSame('TPL-9', \App\Support\Messaging\ProviderRegistry::config('msg91')['templates']['joined']['sms_template_id']);

        $fresh = Livewire::test('pages::admin.settings.index')->set('tab', 'messaging');
        $this->assertSame('', $fresh->get('provCfg')['msg91']['authkey']);                                     // not echoed back
        $fresh->assertSet('provTemplates.msg91.joined.sms_template_id', 'TPL-9');
        $fresh->call('saveMessaging')->assertHasNoErrors();                                                    // saving again keeps the key
        $this->assertSame('KEY-ADMIN', \App\Support\Messaging\ProviderRegistry::config('msg91')['authkey']);
    }
}
