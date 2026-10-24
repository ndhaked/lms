<?php

namespace Tests\Feature;

use App\Actions\RegisterLibrary;
use App\Models\Concerns\BelongsToLibrary;
use App\Models\Library;
use App\Models\User;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProbeRecord extends Model
{
    use BelongsToLibrary;

    protected $table = 'probe_records';
    public $timestamps = false;
    protected $guarded = [];
}

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function register(string $name, string $ownerEmail): Library
    {
        return app(RegisterLibrary::class)(
            ['name' => $name],
            ['name' => "$name Owner", 'email' => $ownerEmail, 'password' => 'password123'],
        );
    }

    protected function superAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $admin = $admin->fresh();

        return $admin;
    }

    protected function tearDown(): void
    {
        Tenancy::flush();
        parent::tearDown();
    }

    public function test_registering_a_library_provisions_roles_and_owner(): void
    {
        $library = $this->register('Alpha Hall', 'a@example.com');
        $owner = User::where('email', 'a@example.com')->first();

        $this->assertSame('alpha-hall', $library->slug);
        $this->assertTrue($owner->libraries->contains($library));

        Tenancy::run($library, function () use ($owner) {
            $this->assertTrue($owner->fresh()->hasRole(LibraryRoles::OWNER));
            $this->assertTrue($owner->fresh()->can('payments.refund'));
        });
    }

    public function test_roles_are_scoped_to_each_library(): void
    {
        $a = $this->register('Alpha', 'a@example.com');
        $b = $this->register('Beta', 'b@example.com');
        $ownerA = User::where('email', 'a@example.com')->first();

        Tenancy::run($b, fn () => $this->assertFalse($ownerA->fresh()->hasRole(LibraryRoles::OWNER)));
        Tenancy::run($b, fn () => $this->assertFalse($ownerA->fresh()->can('students.view')));
        Tenancy::run($a, fn () => $this->assertTrue($ownerA->fresh()->can('students.view')));
    }

    public function test_user_can_hold_different_roles_in_different_libraries(): void
    {
        $a = $this->register('Alpha', 'a@example.com');
        $b = $this->register('Beta', 'b@example.com');
        $ownerA = User::where('email', 'a@example.com')->first();

        $b->users()->attach($ownerA->id, ['is_active' => true]);
        Tenancy::run($b, fn () => $ownerA->assignRole(LibraryRoles::RECEPTIONIST));

        Tenancy::run($b, function () use ($ownerA) {
            $u = $ownerA->fresh();
            $this->assertTrue($u->can('payments.record'));
            $this->assertFalse($u->can('payments.refund'));
        });
        Tenancy::run($a, fn () => $this->assertTrue($ownerA->fresh()->can('payments.refund')));
    }

    public function test_tenant_scope_isolates_records_and_fails_closed(): void
    {
        Schema::dropIfExists('probe_records');
        Schema::create('probe_records', function ($t) {
            $t->id();
            $t->unsignedBigInteger('library_id');
            $t->string('label');
        });

        $a = $this->register('Alpha', 'a@example.com');
        $b = $this->register('Beta', 'b@example.com');

        Tenancy::run($a, fn () => ProbeRecord::create(['label' => 'a-row']));
        Tenancy::run($b, fn () => ProbeRecord::create(['label' => 'b-row']));

        Tenancy::run($a, function () use ($a) {
            $this->assertSame(['a-row'], ProbeRecord::pluck('label')->all());
            // A client-supplied library_id is overwritten by the active library.
            $row = ProbeRecord::create(['label' => 'spoof', 'library_id' => 999]);
            $this->assertSame($a->id, $row->library_id);
        });

        $this->assertCount(0, ProbeRecord::all(), 'no active library -> no rows');
        $this->expectException(\LogicException::class);
        ProbeRecord::create(['label' => 'orphan']);
    }

    public function test_library_user_cannot_enter_another_library_by_url(): void
    {
        $a = $this->register('Alpha', 'a@example.com');
        $b = $this->register('Beta', 'b@example.com');
        $ownerA = User::where('email', 'a@example.com')->first();

        $this->actingAs($ownerA)->get("/app/{$a->slug}")->assertOk();
        $this->actingAs($ownerA)->get("/app/{$b->slug}")->assertStatus(404);
    }

    public function test_library_users_cannot_open_platform_admin(): void
    {
        $this->register('Alpha', 'a@example.com');
        $ownerA = User::where('email', 'a@example.com')->first();

        $this->actingAs($ownerA)->get('/admin')->assertForbidden();
        $this->actingAs($this->superAdmin())->get('/admin')->assertOk();
    }

    public function test_super_admin_has_no_implicit_library_access(): void
    {
        $a = $this->register('Alpha', 'a@example.com');

        $this->actingAs($this->superAdmin())->get("/app/{$a->slug}")->assertNotFound();
    }

    public function test_suspended_library_is_blocked_but_data_is_kept(): void
    {
        $a = $this->register('Alpha', 'a@example.com');
        $ownerA = User::where('email', 'a@example.com')->first();
        $a->update(['status' => Library::SUSPENDED]);

        $this->actingAs($ownerA)->get("/app/{$a->slug}")->assertForbidden();
        $this->assertDatabaseHas('libraries', ['id' => $a->id]);
    }

    public function test_inactive_membership_is_denied(): void
    {
        $a = $this->register('Alpha', 'a@example.com');
        $ownerA = User::where('email', 'a@example.com')->first();
        $a->users()->updateExistingPivot($ownerA->id, ['is_active' => false]);

        $this->assertFalse($ownerA->fresh()->canAccessLibrary($a));
    }
}
