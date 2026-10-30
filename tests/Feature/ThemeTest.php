<?php

namespace Tests\Feature;

use App\Actions\RegisterLibrary;
use App\Models\User;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_library_picks_its_own_theme_without_touching_others(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin->fresh());

        $a = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner A', 'email' => 'a@test.test']);
        $b = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'Owner B', 'email' => 'b@test.test']);
        $ownerA = User::where('email', 'a@test.test')->first();

        Tenancy::set($a);
        $this->actingAs($ownerA);
        $this->get("/app/{$a->slug}")->assertOk()->assertSee('data-theme="default"', false);

        Livewire::test('theme-picker')->call('choose', 'sunset');

        $this->assertSame('sunset', LibrarySettings::get('theme', null, $a->id));
        $this->assertSame('', LibrarySettings::get('theme', null, $b->id));   // untouched: follows the platform default
        $this->get("/app/{$a->slug}")->assertOk()->assertSee('data-theme="sunset"', false);
    }

    public function test_unknown_theme_is_refused_and_falls_back_to_default(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin->fresh());
        $lib = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@test.test']);
        Tenancy::set($lib);
        $this->actingAs(User::where('email', 'o@test.test')->first());

        Livewire::test('theme-picker')->call('choose', 'neon')->assertNotFound();
        LibrarySettings::update(['theme' => 'neon']);
        $this->assertSame('default', \App\Support\Themes::key());
    }
}
