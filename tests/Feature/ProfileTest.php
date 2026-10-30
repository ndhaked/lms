<?php

namespace Tests\Feature;

use App\Actions\RegisterLibrary;
use App\Models\Library;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Library $lib;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['password' => 'old-password-1']);
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->admin = $this->admin->fresh();
        $this->actingAs($this->admin);
        $this->lib = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@prof.test', 'password' => 'owner-pass-1']);
        $this->owner = User::where('email', 'o@prof.test')->first();
        Tenancy::set($this->lib);
    }

    protected function tearDown(): void
    {
        Tenancy::flush();
        parent::tearDown();
    }

    public function test_the_profile_menu_link_opens_the_page_in_both_panels(): void
    {
        $this->get('/admin/profile')->assertOk()->assertSee('My Profile')->assertSee($this->admin->email);
        $this->get('/admin')->assertOk()->assertSee('/admin/profile', false);

        $this->actingAs($this->owner);
        $this->get("/app/{$this->lib->slug}/profile")->assertOk()->assertSee('My Profile')->assertSee('o@prof.test');
        $this->get("/app/{$this->lib->slug}")->assertOk()->assertSee("/app/{$this->lib->slug}/profile", false);
    }

    public function test_details_can_be_updated_but_not_the_email(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::account.profile')->set('name', 'New Name')->set('phone', '98765 43210')->call('saveDetails')->assertHasNoErrors();

        $u = $this->owner->fresh();
        $this->assertSame(['New Name', '98765 43210', 'o@prof.test'], [$u->name, $u->phone, $u->email]);
        Livewire::test('pages::account.profile')->set('phone', 'abc')->call('saveDetails')->assertHasErrors('phone');
    }

    public function test_password_change_needs_the_current_password(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::account.profile')->set('current_password', 'wrong')->set('password', 'brand-new-pass')->set('password_confirmation', 'brand-new-pass')->call('savePassword')->assertHasErrors('current_password');
        $this->assertTrue(Hash::check('owner-pass-1', $this->owner->fresh()->password));

        Livewire::test('pages::account.profile')->set('current_password', 'owner-pass-1')->set('password', 'short')->set('password_confirmation', 'short')->call('savePassword')->assertHasErrors('password');
        Livewire::test('pages::account.profile')->set('current_password', 'owner-pass-1')->set('password', 'brand-new-pass')->set('password_confirmation', 'different')->call('savePassword')->assertHasErrors('password');

        Livewire::test('pages::account.profile')->set('current_password', 'owner-pass-1')->set('password', 'brand-new-pass')->set('password_confirmation', 'brand-new-pass')->call('savePassword')->assertHasNoErrors();
        $this->assertTrue(Hash::check('brand-new-pass', $this->owner->fresh()->password));
    }

    public function test_a_super_admin_edits_only_their_own_profile(): void
    {
        Livewire::test('pages::account.profile')->set('name', 'Chief')->call('saveDetails')->assertHasNoErrors();

        $this->assertSame('Chief', $this->admin->fresh()->name);
        $this->assertSame('Owner', $this->owner->fresh()->name);
    }
}
