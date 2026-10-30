<?php

namespace Tests\Feature\Api;

use App\Models\DeviceToken;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

class AuthApiTest extends ApiTestCase
{
    public function test_login_returns_a_token_the_user_and_their_libraries(): void
    {
        $res = $this->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'password123', 'device_name' => 'Pixel 8'])
            ->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'email'], 'libraries' => [['id', 'slug', 'name', 'roles', 'workspace', 'theme']]]);

        $this->assertSame('staff', $res->json('libraries.0.workspace'));
        $this->assertContains('Library Owner', $res->json('libraries.0.roles'));
        $this->assertNotNull($res->json('expires_at'));
    }

    public function test_wrong_password_unknown_email_and_inactive_accounts_get_the_same_answer(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'nope', 'device_name' => 'x'])->assertStatus(422)->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@alpha.test', 'password' => 'nope', 'device_name' => 'x'])->assertStatus(422)->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
        $this->owner->forceFill(['is_active' => false])->save();
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'password123', 'device_name' => 'x'])->assertStatus(422)->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }

    public function test_sign_in_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'bad', 'device_name' => 'x'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'password123', 'device_name' => 'x'])->assertStatus(429);
    }

    public function test_super_admins_cannot_use_the_app(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@platform.test', 'password' => 'password123']);
        $admin->forceFill(['is_super_admin' => true, 'is_active' => true])->save();
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@platform.test', 'password' => 'password123', 'device_name' => 'x'])->assertStatus(422);
    }

    public function test_endpoints_need_a_token_and_reject_bad_ones(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->withHeaders(['Authorization' => 'Bearer 1|nonsense'])->getJson('/api/v1/auth/me')->assertUnauthorized();
        // no Accept header: still JSON, never a redirect to the web login
        $this->get('/api/v1/auth/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    }

    public function test_me_logout_and_logout_all(): void
    {
        $t1 = $this->ownerToken();
        $t2 = $this->ownerToken();
        $this->as($t1)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.email', 'owner@alpha.test');
        $this->as($t1)->getJson('/api/v1/auth/sessions')->assertOk()->assertJsonCount(2, 'data');

        $this->as($t1)->postJson('/api/v1/auth/logout')->assertOk();
        $this->as($t1)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->as($t2)->getJson('/api/v1/auth/me')->assertOk();

        $this->as($t2)->postJson('/api/v1/auth/logout-all')->assertOk();
        $this->as($t2)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame(0, $this->owner->tokens()->count());
    }

    public function test_tokens_expire_and_deactivation_stops_them_at_once(): void
    {
        $token = $this->ownerToken();
        $this->travel(31)->days();
        $this->as($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->travelBack();

        $token = $this->ownerToken();
        $this->owner->forceFill(['is_active' => false])->save();
        $this->as($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_profile_update_and_password_change_signs_out_other_phones(): void
    {
        $keep = $this->ownerToken();
        $other = $this->ownerToken();

        $this->as($keep)->putJson('/api/v1/me', ['name' => 'Rakesh', 'phone' => '9876500001', 'email' => 'hacker@x.test'])->assertOk()->assertJsonPath('data.name', 'Rakesh');
        $this->assertSame('owner@alpha.test', $this->owner->fresh()->email, 'email cannot be changed from the app');

        $this->as($keep)->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertStatus(422);
        $this->as($keep)->putJson('/api/v1/me/password', ['current_password' => 'password123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertOk();
        $this->as($keep)->getJson('/api/v1/auth/me')->assertOk();
        $this->as($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->login('owner@alpha.test', 'newpass123');
    }

    public function test_forgot_password_never_reveals_whether_the_email_exists(): void
    {
        Notification::fake();
        $a = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'owner@alpha.test'])->assertOk()->json('message');
        $b = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@alpha.test'])->assertOk()->json('message');
        $this->assertSame($a, $b);
    }

    public function test_devices_register_on_login_and_receive_push_for_notifications(): void
    {
        $this->withoutDefer();
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'abc']]])]);

        $token = $this->login('owner@alpha.test', extra: ['push_token' => 'ExponentPushToken[abc123]', 'push_provider' => 'expo', 'platform' => 'android']);
        $this->assertSame(1, $this->owner->devices()->count());

        Notifier::user($this->owner, $this->library->id, 'test.kind', 'Hello', 'From the test');
        Http::assertSent(function ($r) {
            $msg = json_decode($r->body(), true)[0] ?? [];

            return str_contains($r->url(), 'exp.host') && $msg['to'] === 'ExponentPushToken[abc123]' && $msg['title'] === 'Hello' && $msg['data']['kind'] === 'test.kind';
        });

        // signing out removes this phone's push registration
        $this->as($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, DeviceToken::count());
    }

    public function test_sign_in_works_without_a_push_token(): void
    {
        foreach ([null, '', 'null'] as $empty) {
            $this->login('owner@alpha.test', extra: ['push_token' => $empty, 'push_provider' => 'expo', 'platform' => 'android']);
        }
        $this->assertSame(3, $this->owner->tokens()->count());
        $this->assertSame(0, $this->owner->devices()->count());
    }

    public function test_a_bad_push_token_at_login_is_a_field_error_and_creates_no_sign_in(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@alpha.test', 'password' => 'password123', 'device_name' => 'x', 'push_token' => 'ewrwerwerewr', 'push_provider' => 'expo', 'platform' => 'android'])
            ->assertStatus(422)->assertJsonValidationErrors('push_token')->assertJsonMissingPath('exception');
        $this->assertSame(0, $this->owner->tokens()->count());
        $this->assertSame(0, $this->owner->devices()->count());
    }

    public function test_a_device_the_push_service_no_longer_knows_is_removed(): void
    {
        $this->withoutDefer();
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);
        $token = $this->ownerToken();
        $this->as($token)->postJson('/api/v1/devices', ['token' => 'ExponentPushToken[gone]', 'provider' => 'expo', 'platform' => 'ios'])->assertCreated();
        $this->as($token)->postJson('/api/v1/devices', ['token' => 'not-an-expo-token', 'provider' => 'expo', 'platform' => 'ios'])->assertStatus(422);

        Notifier::user($this->owner, $this->library->id, 'test.kind', 'Hello');
        $this->assertSame(0, DeviceToken::count());
    }

    public function test_notifications_list_read_and_only_own(): void
    {
        $token = $this->ownerToken();
        Notifier::user($this->owner, $this->library->id, 'a', 'First');
        Notifier::user($this->owner, $this->library->id, 'b', 'Second');
        $stranger = User::create(['name' => 'X', 'email' => 'x@x.test', 'password' => 'password123']);
        Notifier::user($stranger, null, 'c', 'Not yours');

        $list = $this->as($token)->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('unread', 2);
        $id = $list->json('data.0.id');
        $this->as($token)->postJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('data.read', true);
        $this->as($token)->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread', 1);

        $foreign = $stranger->notifications()->first()->id;
        $this->as($token)->postJson("/api/v1/notifications/{$foreign}/read")->assertNotFound();

        $this->as($token)->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->as($token)->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread', 0);
    }

    public function test_library_context_belongs_only_to_members_and_theme_needs_settings_permission(): void
    {
        $token = $this->ownerToken();
        $this->as($token)->getJson($this->lib())->assertOk()
            ->assertJsonPath('data.library.slug', $this->library->slug)
            ->assertJsonStructure(['data' => ['me' => ['permissions'], 'modules', 'subscription' => ['state'], 'credits', 'theme' => ['current', 'choices']]]);

        $other = $this->makeLibrary('Beta', 'owner@beta.test');
        $this->as($token)->getJson($this->lib('', $other))->assertNotFound();

        $this->as($token)->putJson($this->lib('theme'), ['theme' => 'sunset'])->assertOk()->assertJsonPath('data.current', 'sunset');
        $this->as($token)->putJson($this->lib('theme'), ['theme' => 'neon'])->assertStatus(422);

        $this->staff($this->roleName('RECEPTIONIST'), 'desk@alpha.test');
        $desk = $this->login('desk@alpha.test');
        $this->as($desk)->putJson($this->lib('theme'), ['theme' => 'ocean'])->assertForbidden();
        $this->as($desk)->getJson($this->lib('theme'))->assertOk()->assertJsonPath('data.current', 'sunset')->assertJsonPath('data.can_change', false);
    }

    public function test_dashboard_and_insights_follow_permissions(): void
    {
        $this->member();
        $owner = $this->ownerToken();
        $tiles = collect($this->as($owner)->getJson($this->lib('dashboard'))->assertOk()->json('data.tiles'))->pluck('key');
        $this->assertTrue($tiles->contains('live_members') && $tiles->contains('today_collection'));
        $this->as($owner)->getJson($this->lib('insights'))->assertOk()->assertJsonStructure(['data' => ['members' => ['live', 'sentence'], 'money' => ['month', 'sentence']]]);

        $this->staff($this->roleName('SUPERVISOR'), 'floor@alpha.test');
        $floor = $this->login('floor@alpha.test');
        $tiles = collect($this->as($floor)->getJson($this->lib('dashboard'))->assertOk()->json('data.tiles'))->pluck('key');
        $this->assertFalse($tiles->contains('today_collection'), 'a floor supervisor does not see money');
        $this->assertTrue($tiles->contains('seats_occupied'));
    }

    public function test_app_info_is_public(): void
    {
        $this->getJson('/api/v1/app/info')->assertOk()->assertJsonStructure(['data' => ['app_name', 'support' => ['phone', 'email']]]);
    }
}
