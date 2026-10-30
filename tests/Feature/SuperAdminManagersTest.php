<?php

namespace Tests\Feature;

use App\Actions\RegisterLibrary;
use App\Models\Library;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use App\Support\Themes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SuperAdminManagersTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Library $lib;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->admin = $this->admin->fresh();
        $this->actingAs($this->admin);
        $this->lib = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@mgr.test']);
        $this->owner = User::where('email', 'o@mgr.test')->first();
        Tenancy::set($this->lib);
    }

    protected function tearDown(): void
    {
        BillingSettings::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    public function test_platform_default_theme_applies_until_a_library_picks_its_own(): void
    {
        Livewire::test('pages::admin.settings.index')->set('tab', 'themes')->set('defaultTheme', 'royal')->call('saveThemes')->assertHasNoErrors();
        BillingSettings::flush();

        $this->assertSame('royal', Themes::key());                       // nothing chosen: follows the platform
        LibrarySettings::update(['theme' => 'ocean']);
        $this->assertSame('ocean', Themes::key());                       // the library's own choice wins
    }

    public function test_switched_off_themes_are_hidden_from_libraries_but_kept_by_those_using_them(): void
    {
        LibrarySettings::update(['theme' => 'sunset']);
        Livewire::test('pages::admin.settings.index')->set('tab', 'themes')->set('themesEnabled', ['default', 'ocean'])->call('saveThemes')->assertHasNoErrors();
        BillingSettings::flush();

        $this->assertSame(['default', 'ocean', 'sunset'], Themes::pickable('sunset'));   // sunset stays for the library using it
        $this->assertSame(['default', 'ocean'], Themes::pickable('default'));

        $this->actingAs($this->owner);
        Livewire::test('theme-picker')->call('choose', 'royal')->assertNotFound();       // switched off
    }

    public function test_at_least_one_theme_and_a_valid_default_are_required(): void
    {
        Livewire::test('pages::admin.settings.index')->set('tab', 'themes')->set('themesEnabled', [])->call('saveThemes')->assertHasErrors('themesEnabled');
        Livewire::test('pages::admin.settings.index')->set('tab', 'themes')->set('themesEnabled', ['ocean'])->set('defaultTheme', 'royal')->call('saveThemes')->assertHasErrors('defaultTheme');
    }

    public function test_super_admin_sets_one_librarys_theme_from_its_edit_page(): void
    {
        Livewire::test('pages::admin.libraries.edit', ['library' => $this->lib->id])->set('theme', 'forest')->call('save')->assertHasNoErrors();

        $this->assertSame('forest', LibrarySettings::get('theme', null, $this->lib->id));
    }

    public function test_referral_rules_are_managed_by_the_super_admin(): void
    {
        Livewire::test('pages::admin.settings.index')->set('tab', 'referral')
            ->set('referral_reward_coins', '250')->set('coin_value', '0.5')->set('referral_redeem_percent', '30')->call('saveReferral')->assertHasNoErrors();
        BillingSettings::flush();

        $this->assertSame(250, BillingSettings::get('referral_reward_coins'));
        $this->assertEquals(0.5, BillingSettings::get('coin_value'));
        $this->assertSame(30, BillingSettings::get('referral_redeem_percent'));

        Livewire::test('pages::admin.settings.index')->set('tab', 'referral')->set('referral_redeem_percent', '150')->call('saveReferral')->assertHasErrors('referral_redeem_percent');
    }

    public function test_library_owners_cannot_open_the_platform_settings(): void
    {
        $this->actingAs($this->owner);
        Livewire::test('pages::admin.settings.index')->assertForbidden();
    }

    public function test_super_admin_has_their_own_admin_panel_theme(): void
    {
        $this->actingAs($this->admin);
        $this->get('/admin')->assertOk()->assertSee('data-theme="default"', false);   // the platform default until they choose

        Livewire::test('theme-picker', ['scope' => 'admin'])->call('choose', 'sunset');

        $this->assertSame('sunset', $this->admin->fresh()->admin_theme);
        $this->get('/admin')->assertOk()->assertSee('data-theme="sunset"', false);
        $this->assertSame('', (string) LibrarySettings::get('theme', null, $this->lib->id), 'library themes are unaffected');
    }

    public function test_a_library_owner_cannot_use_the_admin_scope_to_set_an_admin_theme(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('theme-picker', ['scope' => 'admin'])->call('choose', 'ocean')->assertForbidden();
        $this->assertNull($this->owner->fresh()->admin_theme);
    }}
