<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\Billing\ApproveSubscriptionPayment;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\SubmitSubscriptionPayment;
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
use App\Support\Messaging\ProviderRegistry;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MessagingProvidersTest extends TestCase
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
        $this->lib = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@pv.test']);
        $this->owner = User::where('email', 'o@pv.test')->first();
        app(CreateSubscription::class)($this->lib, $sub, 'monthly', false);
        Tenancy::set($this->lib);
        $this->actingAs($this->owner);
        $p = app(SubmitSubscriptionPayment::class)($this->lib, $sub, 'monthly', 'ACT', 1000, 'upi', today(), null, [UploadedFile::fake()->image('p.png')]);
        $this->actingAs($this->admin);
        app(ApproveSubscriptionPayment::class)($p);
        $this->actingAs($this->owner);

        $this->plan = MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 1000]);
        $this->student = Student::create(['name' => 'Asha', 'phone' => '9876543210', 'registered_on' => today(), 'status' => 'active']);
        $this->lib->forceFill(['sms_credits' => 20, 'whatsapp_credits' => 20])->save();
    }

    protected function tearDown(): void
    {
        BillingSettings::flush();
        LibrarySettings::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function use(array $active, string $key, array $cfg): void
    {
        $this->actingAs($this->admin);
        ProviderRegistry::save($key, $cfg);
        BillingSettings::set(['messaging_active' => $active + ['sms' => 'none', 'whatsapp' => 'none']]);
        $this->actingAs($this->owner);
    }

    protected function switchOn(string $event, string $channel): void
    {
        LibrarySettings::update(['message_triggers' => [$event => [$channel => true]]]);
    }

    public function test_every_provider_is_listed_and_only_offered_for_the_channels_it_carries(): void
    {
        $this->assertSame(['msg91', 'fast2sms', 'meta_whatsapp', 'webhook', 'test'], array_keys(ProviderRegistry::all()));
        $this->assertSame(['msg91', 'fast2sms', 'webhook', 'test'], array_keys(ProviderRegistry::forChannel('sms')));
        $this->assertSame(['msg91', 'meta_whatsapp', 'webhook', 'test'], array_keys(ProviderRegistry::forChannel('whatsapp')));

        $this->actingAs($this->admin);
        BillingSettings::set(['messaging_active' => ['sms' => 'meta_whatsapp', 'whatsapp' => 'fast2sms']]);   // wrong channel: ignored
        $this->assertNull(Messaging::provider('sms'));
        $this->assertNull(Messaging::provider('whatsapp'));
    }

    public function test_fast2sms_quick_route_sends_the_filled_text_to_the_ten_digit_number(): void
    {
        $this->use(['sms' => 'fast2sms'], 'fast2sms', ['api_key' => 'F2S-KEY', 'route' => 'q']);
        Http::fake(['www.fast2sms.com/*' => Http::response(['return' => true, 'request_id' => 'r1', 'message' => ['SMS sent successfully.']], 200)]);
        $this->switchOn('joined', 'sms');

        app(AssignMembership::class)($this->student, $this->plan, today());

        Http::assertSent(fn ($r) => $r->url() === 'https://www.fast2sms.com/dev/bulkV2' && $r->hasHeader('authorization', 'F2S-KEY')
            && $r['route'] === 'q' && $r['numbers'] === '9876543210' && str_contains($r['message'], 'Welcome Asha!'));
        $log = MessageLog::firstOrFail();
        $this->assertSame(['sent', 'fast2sms'], [$log->status, $log->provider]);
        $this->assertStringContainsString('request_id', $log->response);
    }

    public function test_fast2sms_dlt_route_sends_the_template_id_and_pipe_separated_variables(): void
    {
        $this->use(['sms' => 'fast2sms'], 'fast2sms', ['api_key' => 'F2S-KEY', 'route' => 'dlt', 'sender_id' => 'ALPLIB', 'templates' => ['joined' => ['sms_template_id' => '1007123', 'sms_vars' => 'name, date, library']]]);
        Http::fake(['www.fast2sms.com/*' => Http::response(['return' => true, 'message' => ['ok']], 200)]);
        $this->switchOn('joined', 'sms');

        app(AssignMembership::class)($this->student, $this->plan, today());

        Http::assertSent(fn ($r) => $r['route'] === 'dlt' && $r['sender_id'] === 'ALPLIB' && $r['message'] === '1007123'
            && $r['variables_values'] === 'Asha|'.today()->format('d M Y').'|Alpha');
    }

    public function test_fast2sms_errors_and_missing_dlt_settings_fail_cleanly_and_return_credits(): void
    {
        $this->use(['sms' => 'fast2sms'], 'fast2sms', ['api_key' => 'BAD', 'route' => 'q']);
        Http::fake(['www.fast2sms.com/*' => Http::response(['return' => false, 'status_code' => 412, 'message' => 'Invalid Authentication, Check Authorization Key'], 200)]);
        $this->switchOn('joined', 'sms');
        app(AssignMembership::class)($this->student, $this->plan, today());

        $log = MessageLog::firstOrFail();
        $this->assertSame(['failed', 'Fast2SMS: Invalid Authentication, Check Authorization Key', 20], [$log->status, $log->error, $this->lib->fresh()->sms_credits]);
        $this->assertStringContainsString('412', $log->response);

        $this->use(['sms' => 'fast2sms'], 'fast2sms', ['api_key' => 'K', 'route' => 'dlt']);              // no sender id or template id
        $second = Messaging::send('sms', '9876543210', 'x', null, 'joined');
        $this->assertSame('No DLT template id is set for this message', $second->error);
    }

    public function test_meta_whatsapp_sends_an_approved_template_with_ordered_parameters(): void
    {
        $this->use(['whatsapp' => 'meta_whatsapp'], 'meta_whatsapp', ['access_token' => 'META-TOKEN', 'phone_number_id' => '1055550001', 'language' => 'en_US',
            'templates' => ['joined' => ['wa_template' => 'welcome_member', 'wa_vars' => 'name, library']]]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.1']]], 200)]);
        $this->switchOn('joined', 'whatsapp');

        app(AssignMembership::class)($this->student, $this->plan, today());

        Http::assertSent(function ($r) {
            return $r->url() === 'https://graph.facebook.com/v20.0/1055550001/messages' && $r->hasHeader('Authorization', 'Bearer META-TOKEN')
                && $r['to'] === '919876543210' && $r['type'] === 'template' && $r['template']['name'] === 'welcome_member' && $r['template']['language']['code'] === 'en_US'
                && $r['template']['components'][0]['parameters'] === [['type' => 'text', 'text' => 'Asha'], ['type' => 'text', 'text' => 'Alpha']];
        });
        $this->assertSame(['sent', 'meta_whatsapp'], [MessageLog::firstOrFail()->status, MessageLog::firstOrFail()->provider]);
    }

    public function test_meta_whatsapp_without_a_template_sends_plain_text_and_reports_metas_errors(): void
    {
        $this->use(['whatsapp' => 'meta_whatsapp'], 'meta_whatsapp', ['access_token' => 'T', 'phone_number_id' => '1055550001']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Recipient phone number not in allowed list', 'code' => 131030]], 400)]);
        $this->switchOn('joined', 'whatsapp');

        app(AssignMembership::class)($this->student, $this->plan, today());

        Http::assertSent(fn ($r) => $r['type'] === 'text' && str_contains($r['text']['body'], 'Congratulations on your membership with Alpha'));
        $log = MessageLog::firstOrFail();
        $this->assertSame(['failed', 'Meta: Recipient phone number not in allowed list (code 131030)', 20], [$log->status, $log->error, $this->lib->fresh()->whatsapp_credits]);
        $this->assertStringContainsString('131030', $log->response);
    }

    public function test_the_admin_page_shows_each_providers_fields_and_saves_selects_and_templates(): void
    {
        $this->actingAs($this->admin);
        $page = Livewire::test('pages::admin.settings.index')->set('tab', 'messaging');

        $page->set('activeProvider.sms', 'fast2sms')->set('activeProvider.whatsapp', 'meta_whatsapp')
            ->assertSee('Fast2SMS settings')->assertSee('Meta WhatsApp Cloud API settings')->assertSee('Quick SMS')
            ->call('saveMessaging')->assertHasErrors(['provCfg.fast2sms.api_key']);

        $page->set('provCfg.fast2sms.api_key', 'F-KEY')->set('provCfg.fast2sms.route', 'dlt')->set('provTemplates.fast2sms.joined.sms_template_id', '99')
            ->set('provCfg.meta_whatsapp.access_token', 'M-TOKEN')->set('provCfg.meta_whatsapp.phone_number_id', '555')->set('provTemplates.meta_whatsapp.joined.wa_template', 'hello_world')
            ->call('saveMessaging')->assertHasNoErrors();
        BillingSettings::flush();

        $this->assertSame('dlt', ProviderRegistry::config('fast2sms')['route']);
        $this->assertSame('99', ProviderRegistry::config('fast2sms')['templates']['joined']['sms_template_id']);
        $this->assertSame('hello_world', ProviderRegistry::config('meta_whatsapp')['templates']['joined']['wa_template']);
        $this->assertSame(['fast2sms', 'meta_whatsapp'], [Messaging::provider('sms')->key(), Messaging::provider('whatsapp')->key()]);

        $page->set('provCfg.fast2sms.route', 'weird')->call('saveMessaging')->assertHasErrors('provCfg.fast2sms.route');
    }
}
