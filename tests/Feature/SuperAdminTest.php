<?php

namespace Tests\Feature;

use App\Actions\ChangeLibraryStatus;
use App\Actions\RegisterLibrary;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenancy::flush();
        parent::tearDown();
    }

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u->fresh();
    }

    protected function register(string $name = 'Alpha Hall', string $email = 'owner@alpha.test', array $data = [], bool $setup = false): Library
    {
        return app(RegisterLibrary::class)(
            array_merge(['name' => $name, 'status' => Library::PENDING], $data),
            ['name' => 'Olive Owner', 'email' => $email, 'phone' => '9999900000'],
            'first contact via phone',
            $setup,
        );
    }

    public function test_registration_creates_code_owner_and_notes_without_a_shared_password(): void
    {
        $this->actingAs($this->superAdmin());
        $library = $this->register();
        $owner = User::where('email', 'owner@alpha.test')->first();

        $this->assertSame(sprintf('LIB-%04d', $library->id), $library->fresh()->code);
        $this->assertSame($owner->id, $library->fresh()->owner_id);
        $this->assertSame('9999900000', $owner->phone);
        $this->assertSame(Library::PENDING, $library->status);
        $this->assertNull($library->activated_at);
        $this->assertSame('first contact via phone', $library->notes()->first()->body);

        // No default or guessable password: nothing the platform knows can log in.
        foreach (['password', 'password123', '12345678', 'secret', 'owner@alpha.test'] as $guess) {
            $this->assertFalse(\Hash::check($guess, $owner->password));
        }
    }

    public function test_setup_email_is_sent_to_new_owners_only(): void
    {
        Notification::fake();
        $this->actingAs($this->superAdmin());

        $this->register('Alpha', 'owner@alpha.test', [], true);
        Notification::assertSentTo(User::where('email', 'owner@alpha.test')->first(), ResetPassword::class, function ($n) {
            return true;
        });

        // An existing login already has a password, so it is not mailed a new setup link.
        Notification::fake();
        $this->register('Beta', 'owner@alpha.test', [], true);
        Notification::assertNothingSent();
    }

    public function test_pending_library_cannot_be_used_until_activated(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $library = $this->register();
        $owner = User::where('email', 'owner@alpha.test')->first();

        $this->actingAs($owner)->get("/app/{$library->slug}")->assertForbidden();

        $this->actingAs($admin);
        app(ChangeLibraryStatus::class)($library, Library::ACTIVE);
        $this->assertNotNull($library->fresh()->activated_at);

        $this->actingAs($owner->fresh())->get("/app/{$library->slug}")->assertOk();
    }

    public function test_status_changes_are_validated_and_audited_with_a_reason(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $library = $this->register();

        app(ChangeLibraryStatus::class)($library, Library::ACTIVE);

        try {
            app(ChangeLibraryStatus::class)($library, Library::SUSPENDED);
            $this->fail('suspension without a reason must be rejected');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        app(ChangeLibraryStatus::class)($library, Library::SUSPENDED, 'Unpaid invoice');
        $log = AuditLog::where('action', 'library.status_changed')->latest('id')->first();
        $this->assertSame(['from' => 'active', 'to' => 'suspended', 'reason' => 'Unpaid invoice'], $log->properties);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($library->id, $log->library_id);

        // pending/active/suspended data is never removed
        $this->assertDatabaseHas('libraries', ['id' => $library->id, 'status' => 'suspended']);

        // a suspended library cannot jump to pending
        $this->expectException(BusinessRuleException::class);
        app(ChangeLibraryStatus::class)($library->fresh(), Library::PENDING, 'x');
    }

    public function test_audit_logs_cannot_be_changed_or_deleted(): void
    {
        $log = AuditLog::record('test.event');

        $this->expectException(\LogicException::class);
        try {
            $log->update(['action' => 'tampered']);
        } finally {
            $this->assertSame('test.event', $log->fresh()->action);
        }
    }

    public function test_audit_logs_cannot_be_deleted(): void
    {
        $log = AuditLog::record('test.event');

        try {
            $log->delete();
            $this->fail('deleting an audit log must fail');
        } catch (\LogicException) {
            $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
        }
    }

    public function test_library_pages_show_platform_wide_counts(): void
    {
        $this->actingAs($this->superAdmin());
        $a = $this->register('Alpha', 'a@x.test');
        $b = $this->register('Beta', 'b@x.test');

        Tenancy::run($a, function () {
            Student::create(['name' => 'S1', 'phone' => '1']);
            Student::create(['name' => 'S2', 'phone' => '2']);
        });
        Tenancy::run($b, fn () => Student::create(['name' => 'S3', 'phone' => '3']));

        $counts = Library::withCount(['students', 'seats'])->orderBy('id')->get()->pluck('students_count', 'name');
        $this->assertSame(2, $counts['Alpha']);
        $this->assertSame(1, $counts['Beta']);
    }

    public function test_super_admin_can_open_all_platform_pages_and_library_users_cannot(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $library = $this->register();
        app(ChangeLibraryStatus::class)($library, Library::ACTIVE);

        foreach (['/admin', '/admin/libraries', '/admin/libraries/create', "/admin/libraries/{$library->id}", "/admin/libraries/{$library->id}/edit", '/admin/audit-logs'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }

        $owner = User::where('email', 'owner@alpha.test')->first();
        foreach (['/admin', '/admin/libraries', '/admin/audit-logs'] as $path) {
            $this->actingAs($owner)->get($path)->assertForbidden();
        }
    }

    public function test_create_form_registers_a_library(): void
    {
        Notification::fake();
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.libraries.create')
            ->set('name', 'Gamma Study Hub')
            ->set('owner_name', 'Gina Owner')
            ->set('owner_email', 'gina@gamma.test')
            ->set('owner_phone', '9000011111')
            ->set('status', Library::PENDING)
            ->set('send_setup', true)
            ->set('notes', 'Met at expo')
            ->call('save')
            ->assertHasNoErrors();

        $library = Library::where('name', 'Gamma Study Hub')->first();
        $this->assertNotNull($library);
        $this->assertSame('gina@gamma.test', $library->owner->email);
        Notification::assertSentTo($library->owner, ResetPassword::class);
    }

    public function test_create_form_validates_required_fields(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test('pages::admin.libraries.create')
            ->call('save')
            ->assertHasErrors(['name', 'owner_name', 'owner_email', 'owner_phone']);
    }

    public function test_status_actions_on_the_details_page(): void
    {
        $this->actingAs($this->superAdmin());
        $library = $this->register();

        $page = Livewire::test('pages::admin.libraries.show', ['library' => (string) $library->id]);

        $page->call('activateNow');
        $this->assertSame(Library::ACTIVE, $library->fresh()->status);

        // suspending requires a reason
        $page->call('startStatus', Library::SUSPENDED)->set('reason', '')->call('confirmStatus')->assertHasErrors('reason');
        $this->assertSame(Library::ACTIVE, $library->fresh()->status);

        $page->set('reason', 'Chargeback')->call('confirmStatus')->assertHasNoErrors();
        $this->assertSame(Library::SUSPENDED, $library->fresh()->status);
    }

    public function test_notes_can_be_added_and_are_listed(): void
    {
        $this->actingAs($this->superAdmin());
        $library = $this->register();

        Livewire::test('pages::admin.libraries.show', ['library' => (string) $library->id])
            ->set('showNote', true)
            ->set('noteBody', 'Called owner, will pay Friday')
            ->call('saveNote')
            ->assertHasNoErrors();

        $this->assertSame('Called owner, will pay Friday', $library->notes()->first()->body);
    }

    public function test_library_pages_render_with_real_rows(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $library = $this->register('Alpha Hall', 'owner@alpha.test');
        app(ChangeLibraryStatus::class)($library, Library::ACTIVE);
        app(ChangeLibraryStatus::class)($library, Library::SUSPENDED, 'test');

        $this->get('/admin')->assertOk()->assertSee('Alpha Hall');
        $this->get('/admin/libraries')->assertOk()->assertSee('Alpha Hall')->assertSee('LIB-');
        foreach (['overview', 'owner', 'subscription', 'payments', 'usage', 'activity', 'notes'] as $tab) {
            $this->get("/admin/libraries/{$library->id}?tab={$tab}")->assertOk();
        }
        $this->get('/admin/audit-logs')->assertOk()->assertSee('library.status_changed');
    }
}
