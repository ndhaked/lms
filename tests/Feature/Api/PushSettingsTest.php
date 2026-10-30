<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\BillingSettings;
use App\Support\Push\PushSender;
use Livewire\Livewire;

class PushSettingsTest extends ApiTestCase
{
    public function test_super_admin_saves_push_keys_encrypted_and_bad_keys_are_refused(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@platform.test', 'password' => 'password123']);
        $admin->forceFill(['is_super_admin' => true, 'is_active' => true])->save();
        $this->actingAs($admin);

        Livewire::test('pages::admin.settings.index')->call('setTab', 'mobile')
            ->set('push_fcm_credentials', '{"foo":"bar"}')->call('savePush')->assertHasErrors('push_fcm_credentials');

        $json = json_encode(['client_email' => 'app@x.iam.gserviceaccount.com', 'private_key' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n"]);
        Livewire::test('pages::admin.settings.index')->call('setTab', 'mobile')
            ->set('push_fcm_project_id', 'my-app')->set('push_fcm_credentials', $json)->set('push_expo_access_token', 'expo-secret')->call('savePush')->assertHasNoErrors();

        BillingSettings::flush();
        $this->assertSame('my-app', BillingSettings::get('push_fcm_project_id'));
        $this->assertStringNotContainsString('client_email', (string) BillingSettings::get('push_fcm_credentials'), 'stored encrypted');
        $this->assertSame($json, PushSender::secret('push_fcm_credentials'));
        $this->assertSame('expo-secret', PushSender::secret('push_expo_access_token'));
    }

    public function test_super_admin_switches_the_swagger_page(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@platform.test', 'password' => 'password123']);
        $admin->forceFill(['is_super_admin' => true, 'is_active' => true])->save();
        $this->actingAs($admin);

        Livewire::test('pages::admin.settings.index')->call('setTab', 'mobile')->assertSee('API documentation (Swagger)')
            ->set('api_docs', 'off')->call('savePush')->assertHasNoErrors();
        BillingSettings::flush();
        $this->get('/api/docs')->assertNotFound();

        Livewire::test('pages::admin.settings.index')->set('api_docs', 'on')->call('savePush');
        BillingSettings::flush();
        $this->get('/api/docs')->assertOk();

        Livewire::test('pages::admin.settings.index')->set('api_docs', 'sometimes')->call('savePush')->assertHasErrors('api_docs');
    }
}
