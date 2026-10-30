<?php

namespace Tests\Feature;

use App\Actions\RegisterLibrary;
use App\Models\Library;
use App\Models\MessageLog;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\Messaging;
use App\Support\Messaging\ProviderRegistry;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MessageLogsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Library $a;
    protected Library $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->admin = $this->admin->fresh();
        $this->actingAs($this->admin);
        $this->a = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'A', 'email' => 'a@lg.test']);
        $this->b = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@lg.test']);
    }

    protected function tearDown(): void
    {
        BillingSettings::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    public function test_a_failed_message_keeps_the_providers_reply_and_name_for_diagnosis(): void
    {
        ProviderRegistry::save('msg91', ['authkey' => 'KEY', 'templates' => ['joined' => ['sms_template_id' => 'T1']]]);
        BillingSettings::set(['messaging_active' => ['sms' => 'msg91', 'whatsapp' => 'none']]);
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'error', 'message' => 'Template not approved'], 200)]);
        $this->a->forceFill(['sms_credits' => 5])->save();
        Tenancy::set($this->a);

        $log = Messaging::send('sms', '9876543210', 'Hello', null, 'joined', null, ['name' => 'Asha']);

        $this->assertSame(['failed', 'MSG91: Template not approved', 'msg91'], [$log->status, $log->error, $log->provider]);
        $this->assertStringContainsString('Template not approved', $log->response);
        $this->assertSame(5, $this->a->fresh()->sms_credits);
    }

    public function test_the_admin_page_lists_every_librarys_messages_and_filters_them(): void
    {
        foreach ([[$this->a, 'sms', 'sent', null], [$this->a, 'whatsapp', 'failed', 'Not enough credits'], [$this->b, 'sms', 'failed', 'MSG91 replied 401']] as $i => [$lib, $ch, $st, $err]) {
            \Illuminate\Support\Facades\DB::table('message_logs')->insert(['library_id' => $lib->id, 'channel' => $ch, 'mobile' => '90000000'.$i, 'count' => 1, 'message' => "Msg {$i}", 'status' => $st, 'error' => $err, 'provider' => 'msg91', 'response' => '401 {"message":"Authentication failed"}', 'created_at' => now(), 'updated_at' => now()]);
        }

        $page = Livewire::test('pages::admin.message-logs.index');
        $this->assertCount(3, $page->instance()->logs);
        $page->assertSee('Alpha')->assertSee('Beta')->assertSee('MSG91 replied 401');

        $this->assertCount(2, Livewire::test('pages::admin.message-logs.index')->set('status', 'failed')->instance()->logs);
        $this->assertCount(2, Livewire::test('pages::admin.message-logs.index')->set('channel', 'sms')->instance()->logs);
        $this->assertCount(1, Livewire::test('pages::admin.message-logs.index')->set('library', (string) $this->b->id)->instance()->logs);
        $this->assertCount(1, Livewire::test('pages::admin.message-logs.index')->set('search', 'Not enough')->instance()->logs);

        $id = MessageLog::withoutGlobalScopes()->where('error', 'MSG91 replied 401')->value('id');
        Livewire::test('pages::admin.message-logs.index')->call('$set', 'open', $id)->assertSee('Authentication failed')->assertSee('Why it failed');
    }

    public function test_only_platform_staff_can_open_the_page_and_it_is_in_the_menu(): void
    {
        $this->get('/admin/message-logs')->assertOk()->assertSee('Message logs');
        $this->get('/admin')->assertSee('Message Logs');

        $owner = User::where('email', 'a@lg.test')->first();
        $this->actingAs($owner);
        $this->get('/admin/message-logs')->assertForbidden();
    }
}
