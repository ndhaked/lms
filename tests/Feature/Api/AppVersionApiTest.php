<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\BillingSettings;
use Livewire\Livewire;

class AppVersionApiTest extends ApiTestCase
{
    protected function rules(): void
    {
        BillingSettings::set(['app_versions' => [
            'android' => ['latest' => '1.5.0', 'minimum' => '1.2.0', 'store_url' => 'https://play.google.com/store/apps/details?id=in.lms'],
            'ios' => ['latest' => '2.0', 'minimum' => '', 'store_url' => 'https://apps.apple.com/app/id1'],
        ], 'app_update_message' => 'Please update the app.']);
    }

    public function test_version_check_says_required_optional_or_none(): void
    {
        $this->rules();
        $this->getJson('/api/v1/app/version?platform=android&version=1.1.9')->assertOk()
            ->assertJsonPath('data.update', 'required')->assertJsonPath('data.force_update', true)
            ->assertJsonPath('data.message', 'Please update the app.')->assertJsonPath('data.store_url', 'https://play.google.com/store/apps/details?id=in.lms');
        $this->getJson('/api/v1/app/version?platform=android&version=1.4.10')->assertJsonPath('data.update', 'optional');
        $this->getJson('/api/v1/app/version?platform=android&version=1.10.0')->assertJsonPath('data.update', 'none');   // 1.10 > 1.5
        $this->getJson('/api/v1/app/version?platform=ios&version=0.1')->assertJsonPath('data.update', 'optional');    // no minimum on iOS
        $this->getJson('/api/v1/app/version?platform=windows&version=1')->assertStatus(422);
        $this->getJson('/api/v1/app/version?platform=android&version=abc')->assertStatus(422);
        $this->getJson('/api/v1/app/info')->assertJsonPath('data.versions.android.minimum_version', '1.2.0');
    }

    public function test_too_old_apps_are_refused_on_every_call_but_the_version_check(): void
    {
        $this->rules();
        $old = ['X-App-Platform' => 'android', 'X-App-Version' => '1.0.0'];
        $this->withHeaders($old)->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'password123', 'device_name' => 'x'])
            ->assertStatus(426)->assertJsonPath('code', 'update_required')->assertJsonPath('update', 'required');
        $this->withHeaders($old)->getJson('/api/v1/app/version?platform=android&version=1.0.0')->assertOk();
        $this->withHeaders($old)->getJson('/api/v1/app/info')->assertOk();

        // a current app, or no version headers at all (Swagger, Postman), works as before
        $this->withHeaders(['X-App-Platform' => 'android', 'X-App-Version' => '1.3.0'])->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'password123', 'device_name' => 'x'])->assertOk();
        $this->flushHeaders();
        $this->ownerToken();
    }

    public function test_super_admin_sets_versions_with_checks(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@platform.test', 'password' => 'password123']);
        $admin->forceFill(['is_super_admin' => true, 'is_active' => true])->save();
        $this->actingAs($admin);

        Livewire::test('pages::admin.settings.index')->call('setTab', 'mobile')->assertSee('App versions and force update')
            ->set('appVersions.android.minimum', '2.0')->set('appVersions.android.latest', '1.5')->call('savePush')->assertHasErrors('appVersions.android.minimum');
        Livewire::test('pages::admin.settings.index')->set('appVersions.android.latest', 'one')->call('savePush')->assertHasErrors('appVersions.android.latest');
        Livewire::test('pages::admin.settings.index')->set('appVersions.ios.store_url', 'http://insecure.test')->call('savePush')->assertHasErrors('appVersions.ios.store_url');

        Livewire::test('pages::admin.settings.index')->set('appVersions.android.latest', '1.5.0')->set('appVersions.android.minimum', '1.2.0')
            ->set('appVersions.android.store_url', 'https://play.google.com/store/apps/details?id=in.lms')->set('app_update_message', 'Update please')->call('savePush')->assertHasNoErrors();
        BillingSettings::flush();
        $this->assertSame('1.2.0', BillingSettings::get('app_versions')['android']['minimum']);
        $this->getJson('/api/v1/app/version?platform=android&version=1.0')->assertJsonPath('data.update', 'required')->assertJsonPath('data.message', 'Update please');
    }
}
