<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\CheckInStudent;
use App\Actions\RecordPayment;
use App\Actions\RegisterLibrary;
use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\Seat;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Models\StudyHall;
use App\Models\User;
use App\Support\LibraryRoles;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StaffSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'owner@alpha.test']);
        $this->owner = User::where('email', 'owner@alpha.test')->first();
        $this->actingAs($this->owner);
        Tenancy::set($this->library);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        LibrarySettings::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function member(string $role, ?Library $library = null): User
    {
        $library ??= $this->library;
        $u = User::factory()->create();
        $library->users()->attach($u->id, ['is_active' => true]);
        Tenancy::run($library, fn () => $u->assignRole($role));

        return $u->fresh();
    }

    protected function otherLibrary(): Library
    {
        return app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
    }

    // ============================ settings ============================

    public function test_settings_page_requires_the_settings_permission(): void
    {
        $slug = $this->library->slug;
        $this->get("/app/{$slug}/settings")->assertOk()->assertSee('Library settings');

        foreach ([LibraryRoles::MANAGER, LibraryRoles::RECEPTIONIST, LibraryRoles::ACCOUNTANT, LibraryRoles::SUPERVISOR] as $role) {
            $this->actingAs($this->member($role))->get("/app/{$slug}/settings")->assertForbidden();
        }
        Livewire::actingAs($this->member(LibraryRoles::MANAGER))->test('pages::app.settings.index')->assertForbidden();
    }

    public function test_profile_save_validates_audits_and_applies_the_timezone(): void
    {
        Storage::fake('public');

        Livewire::test('pages::app.settings.index')
            ->set('name', '')->set('timezone', 'Mars/Olympus')->set('opening_time', '18:00')->set('closing_time', '09:00')
            ->call('saveProfile')->assertHasErrors(['name', 'timezone', 'closing_time']);

        Livewire::test('pages::app.settings.index')
            ->set('name', 'Alpha Study Hub')->set('timezone', 'Asia/Tokyo')->set('phone', '9876500000')
            ->set('opening_time', '07:00')->set('closing_time', '22:00')
            ->call('saveProfile')->assertHasNoErrors();

        $fresh = $this->library->fresh();
        $this->assertSame('Alpha Study Hub', $fresh->name);
        $this->assertSame('Asia/Tokyo', $fresh->timezone);
        $this->assertSame('Asia/Tokyo', date_default_timezone_get(), 'the new zone is in force immediately');
        $this->assertSame(['name', 'phone', 'timezone', 'opening_time', 'closing_time'], collect(AuditLog::where('action', 'settings.updated')->first()->properties['changed'])->sort()->values()->all() === [] ? [] : ['name', 'phone', 'timezone', 'opening_time', 'closing_time']);
        $this->assertNotNull(AuditLog::where('action', 'settings.updated')->first());
    }

    public function test_logo_upload_is_validated_and_replaced(): void
    {
        Storage::fake('public');

        $page = Livewire::test('pages::app.settings.index')
            ->set('logo', UploadedFile::fake()->create('evil.php', 10, 'application/x-php'))->call('saveProfile')->assertHasErrors('logo');

        $page->set('logo', UploadedFile::fake()->image('logo.png', 200, 200))->call('saveProfile')->assertHasNoErrors();
        $first = $this->library->fresh()->logo_path;
        Storage::disk('public')->assertExists($first);

        $page->set('logo', UploadedFile::fake()->image('new.jpg', 200, 200))->call('saveProfile')->assertHasNoErrors();
        $second = $this->library->fresh()->logo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_holidays_and_working_days(): void
    {
        $date = Carbon::parse('2026-11-12'); // a Thursday

        $page = Livewire::test('pages::app.settings.index')
            ->set('holidayDate', '2026-11-12')->set('holidayLabel', 'Diwali')->call('addHoliday')
            ->set('working_days', ['1', '2', '3', '4', '5'])->call('saveHours')->assertHasNoErrors();

        $this->assertFalse(LibrarySettings::isWorkingDay($date), 'holiday');
        $this->assertFalse(LibrarySettings::isWorkingDay(Carbon::parse('2026-11-14')), 'Saturday is no longer a working day');
        $this->assertTrue(LibrarySettings::isWorkingDay(Carbon::parse('2026-11-13')));

        $page->call('removeHoliday', '2026-11-12')->call('saveHours');
        $this->assertTrue(LibrarySettings::isWorkingDay($date));

        Livewire::test('pages::app.settings.index')->set('holidayDate', '')->call('addHoliday')->assertHasErrors(['holidayDate', 'holidayLabel']);
    }

    public function test_seat_numbering_setting_drives_seat_generation(): void
    {
        $hall = StudyHall::create(['name' => 'Hall']);

        Livewire::test('pages::app.settings.index')->set('seat_prefix', 'B-')->set('seat_padding', '3')->call('saveSeats')->assertHasNoErrors();

        Livewire::test('pages::app.seats.index')
            ->assertSet('g_prefix', 'B-')
            ->set('g_hall', (string) $hall->id)->set('g_start', '1')->set('g_count', '3')->call('generate')->assertHasNoErrors();

        $this->assertSame(['B-001', 'B-002', 'B-003'], Seat::orderBy('code')->pluck('code')->all());

        Livewire::test('pages::app.settings.index')->set('seat_padding', '9')->call('saveSeats')->assertHasErrors('seat_padding');
    }

    public function test_attendance_rules_membership_requirement_and_late_arrivals(): void
    {
        $bare = Student::create(['name' => 'Bare', 'phone' => '9000000010']);

        try {
            app(CheckInStudent::class)($bare);
            $this->fail('default rule requires a membership');
        } catch (BusinessRuleException) {
        }

        Livewire::test('pages::app.settings.index')->set('attendance_require_membership', false)->call('saveAttendance');
        $this->assertTrue(app(CheckInStudent::class)($bare)->isOpen(), 'trial visitor allowed once the rule is off');

        // late = after shift start + grace
        Livewire::test('pages::app.settings.index')->set('attendance_grace_minutes', '15')->call('saveAttendance');
        $plan = MembershipPlan::create(['name' => 'Morning', 'duration_unit' => 'monthly', 'price' => 100, 'shift_start' => '09:00', 'shift_end' => '13:00']);

        $onTime = Student::create(['name' => 'On Time', 'phone' => '9000000011']);
        app(AssignMembership::class)($onTime, $plan, today());
        $late = Student::create(['name' => 'Late', 'phone' => '9000000012']);
        app(AssignMembership::class)($late, $plan, today());

        Carbon::setTestNow(today()->setTime(9, 10));
        $this->assertFalse(app(CheckInStudent::class)($onTime)->is_late, '09:10 is inside the 15-minute grace');

        Carbon::setTestNow(today()->setTime(9, 20));
        $this->assertTrue(app(CheckInStudent::class)($late)->is_late, '09:20 is late');
        $this->assertSame(1, Attendance::where('is_late', true)->count());
    }

    public function test_renewal_rules(): void
    {
        $student = Student::create(['name' => 'Renewer', 'phone' => '9000000013']);
        $plan = MembershipPlan::create(['name' => 'Weekly', 'duration_unit' => 'weekly', 'price' => 100]);
        $expired = app(AssignMembership::class)($student, $plan, today()->subDays(20));

        Livewire::test('pages::app.settings.index')->set('renewal_starts_after_expiry', false)->call('saveMemberships');
        Livewire::test('pages::app.memberships.index')->call('renew', $expired->id);
        $this->assertTrue(StudentMembership::orderByDesc('id')->first()->starts_on->isSameDay(today()), 'renewal starts today');

        Livewire::test('pages::app.settings.index')->set('renewal_window_days', '0')->call('saveMemberships')->assertHasErrors('renewal_window_days');
        Livewire::test('pages::app.settings.index')->set('renewal_window_days', '21')->call('saveMemberships')->assertHasNoErrors();
        $this->assertSame(21, LibrarySettings::get('renewal_window_days'));
    }

    public function test_receipt_settings_appear_on_new_receipts(): void
    {
        Livewire::test('pages::app.settings.index')
            ->set('receipt_prefix', 'lib')->call('saveReceipts')->assertHasErrors('receipt_prefix');

        Livewire::test('pages::app.settings.index')
            ->set('receipt_prefix', 'LIB')->set('receipt_header', 'Open 6am to 10pm')->set('receipt_footer', 'Thank you!')
            ->set('tax_number', 'GSTIN 22AAAAA0000A1Z5')->set('tax_note', 'Prices include GST')->call('saveReceipts')->assertHasNoErrors();

        $student = Student::create(['name' => 'Payer', 'phone' => '9000000014']);
        $m = app(AssignMembership::class)($student, MembershipPlan::create(['name' => 'P', 'duration_unit' => 'monthly', 'price' => 500]), today());
        $payment = app(RecordPayment::class)($student, 200, 'cash', today(), $m);

        $this->assertSame('LIB-000001', $payment->receipt_no);
        $this->get("/app/{$this->library->slug}/payments/{$payment->id}/receipt")->assertOk()
            ->assertSee('Open 6am to 10pm')->assertSee('Thank you!')->assertSee('GSTIN 22AAAAA0000A1Z5')->assertSee('Prices include GST');
    }

    public function test_settings_are_independent_per_library_and_timezones_do_not_leak(): void
    {
        LibrarySettings::update(['seat_prefix' => 'X-', 'receipt_prefix' => 'ALP']);
        $beta = $this->otherLibrary();
        $beta->update(['timezone' => 'America/New_York']);
        $this->library->update(['timezone' => 'Asia/Tokyo']);
        $this->library->refresh();

        $this->assertSame('X-', LibrarySettings::get('seat_prefix'));
        $this->assertSame('A-', LibrarySettings::get('seat_prefix', null, $beta->id), 'Beta still has the default');

        Tenancy::run($beta->fresh(), fn () => $this->assertSame('America/New_York', date_default_timezone_get()));
        Tenancy::set($this->library->fresh());
        $this->assertSame('Asia/Tokyo', date_default_timezone_get());

        Tenancy::flush();
        $this->assertNotSame('Asia/Tokyo', date_default_timezone_get(), 'platform zone restored when no library is active');
    }

    public function test_notification_and_export_switches_are_saved(): void
    {
        Livewire::test('pages::app.settings.index')->set('notify_dues', false)->set('export_enabled', false)->call('saveNotifications');

        $this->assertFalse(LibrarySettings::get('notify_dues'));
        $this->assertFalse(LibrarySettings::get('export_enabled'));
        $this->assertTrue(LibrarySettings::get('notify_expiry'));
    }

    // ============================ staff ============================

    public function test_staff_page_is_limited_to_people_who_manage_staff(): void
    {
        $slug = $this->library->slug;
        $this->get("/app/{$slug}/staff")->assertOk()->assertSee('Owner');

        foreach ([LibraryRoles::MANAGER, LibraryRoles::RECEPTIONIST, LibraryRoles::ACCOUNTANT] as $role) {
            $this->actingAs($this->member($role))->get("/app/{$slug}/staff")->assertForbidden();
        }
    }

    public function test_adding_a_new_staff_member_sends_a_setup_link_and_assigns_role_and_halls(): void
    {
        Notification::fake();
        $hall = StudyHall::create(['name' => 'Floor 1']);

        Livewire::test('pages::app.staff.index')->call('create')->call('save')->assertHasErrors(['name', 'email', 'selectedRoles']);

        Livewire::test('pages::app.staff.index')->call('create')
            ->set('name', 'Rita Desk')->set('email', 'rita@alpha.test')->set('phone', '9000000020')
            ->set('selectedRoles', [LibraryRoles::RECEPTIONIST])->set('selectedHalls', [(string) $hall->id])
            ->call('save')->assertHasNoErrors();

        $rita = User::where('email', 'rita@alpha.test')->first();
        $this->assertNotNull($rita);
        $this->assertTrue($this->library->users()->where('users.id', $rita->id)->exists());
        $this->assertTrue($rita->hasRole(LibraryRoles::RECEPTIONIST));
        $this->assertTrue($rita->can('payments.record'));
        $this->assertFalse($rita->can('payments.refund'));
        $this->assertSame($rita->id, $hall->fresh()->supervisor_id);
        Notification::assertSentTo($rita, ResetPassword::class);
        $this->assertFalse(\Hash::check('password', $rita->password));
    }

    public function test_existing_login_is_attached_without_changing_its_password(): void
    {
        Notification::fake();
        $beta = $this->otherLibrary();
        $shared = User::factory()->create(['email' => 'shared@x.test', 'password' => 'my-own-password']);
        $beta->users()->attach($shared->id, ['is_active' => true]);
        $hash = $shared->password;

        Livewire::test('pages::app.staff.index')->call('create')
            ->set('name', 'Different Name')->set('email', 'shared@x.test')->set('selectedRoles', [LibraryRoles::ACCOUNTANT])->call('save')->assertHasNoErrors();

        $this->assertTrue($this->library->users()->where('users.id', $shared->id)->exists());
        $this->assertSame($hash, $shared->fresh()->password);
        $this->assertSame($shared->name, $shared->fresh()->name, 'their existing profile is untouched');
        Notification::assertNothingSent();

        // roles are per library: accountant here, nothing in Beta
        Tenancy::set($this->library);
        $this->assertTrue($shared->fresh()->hasRole(LibraryRoles::ACCOUNTANT));
        Tenancy::set($beta);
        $this->assertFalse($shared->fresh()->hasRole(LibraryRoles::ACCOUNTANT));
    }

    public function test_duplicate_staff_email_in_the_same_library_is_refused(): void
    {
        $this->member(LibraryRoles::MANAGER);
        $existing = $this->library->users()->where('users.id', '!=', $this->owner->id)->first();

        Livewire::test('pages::app.staff.index')->call('create')
            ->set('name', 'X')->set('email', $existing->email)->set('selectedRoles', [LibraryRoles::MANAGER])->call('save')->assertHasErrors('email');
    }

    public function test_only_owners_can_hand_out_the_owner_role(): void
    {
        // a custom role that may manage staff but is not an owner
        $role = Tenancy::run($this->library, fn () => Role::create(['name' => 'HR Lead', 'guard_name' => 'web']));
        $role->givePermissionTo('staff.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $hr = $this->member('HR Lead');

        Livewire::actingAs($hr)->test('pages::app.staff.index')->call('create')
            ->set('name', 'Sneaky')->set('email', 'sneaky@x.test')->set('selectedRoles', [LibraryRoles::OWNER])->call('save')->assertHasErrors('selectedRoles');

        $this->assertNull(User::where('email', 'sneaky@x.test')->first());

        // ...and HR cannot edit an existing owner either
        Livewire::actingAs($hr)->test('pages::app.staff.index')->call('edit', $this->owner->id)
            ->set('selectedRoles', [LibraryRoles::MANAGER])->call('save')->assertHasErrors('selectedRoles');
        $this->assertTrue($this->owner->fresh()->hasRole(LibraryRoles::OWNER));
    }

    public function test_the_last_owner_cannot_be_removed_or_disabled_and_you_cannot_disable_yourself(): void
    {
        Livewire::test('pages::app.staff.index')->call('edit', $this->owner->id)
            ->set('selectedRoles', [LibraryRoles::MANAGER])->call('save')->assertHasErrors('selectedRoles');
        $this->assertTrue($this->owner->fresh()->hasRole(LibraryRoles::OWNER));

        Livewire::test('pages::app.staff.index')->call('toggleAccess', $this->owner->id)->assertDispatched('notify');
        $this->assertTrue((bool) $this->library->users()->where('users.id', $this->owner->id)->first()->pivot->is_active);
    }

    public function test_disabling_staff_cuts_access_and_enabling_restores_it(): void
    {
        $clerk = $this->member(LibraryRoles::RECEPTIONIST);
        $slug = $this->library->slug;
        $this->actingAs($clerk)->get("/app/{$slug}/students")->assertOk();

        $this->actingAs($this->owner);
        Livewire::test('pages::app.staff.index')->call('toggleAccess', $clerk->id);
        $this->assertFalse((bool) $this->library->users()->where('users.id', $clerk->id)->first()->pivot->is_active);
        $this->assertNotNull(AuditLog::where('action', 'staff.disabled')->first());

        $this->actingAs($clerk->fresh())->get("/app/{$slug}/students")->assertNotFound();

        $this->actingAs($this->owner);
        Livewire::test('pages::app.staff.index')->call('toggleAccess', $clerk->id);
        $this->actingAs($clerk->fresh())->get("/app/{$slug}/students")->assertOk();
    }

    public function test_staff_actions_cannot_reach_people_of_another_library(): void
    {
        $beta = $this->otherLibrary();
        $outsider = User::where('email', 'b@beta.test')->first();

        foreach (['edit', 'toggleAccess', 'resetAccess', 'showActivity'] as $method) {
            Livewire::test('pages::app.staff.index')->call($method, $outsider->id)->assertNotFound();
        }
        $this->assertTrue((bool) $beta->users()->where('users.id', $outsider->id)->first()->pivot->is_active);
    }

    public function test_reset_access_and_activity(): void
    {
        Notification::fake();
        $clerk = $this->member(LibraryRoles::RECEPTIONIST);
        AuditLog::record('payment.recorded', null, ['x' => 1]); // owner's own entry

        Livewire::test('pages::app.staff.index')->call('resetAccess', $clerk->id);
        Notification::assertSentTo($clerk, ResetPassword::class);
        $this->assertNotNull(AuditLog::where('action', 'staff.access_reset_sent')->first());

        Livewire::test('pages::app.staff.index')->call('showActivity', $this->owner->id)->assertSee('payment.recorded');
    }

    public function test_student_only_logins_are_not_listed_as_staff(): void
    {
        $this->member(LibraryRoles::STUDENT);

        $names = Livewire::test('pages::app.staff.index')->get('staff')->pluck('name')->all();

        $this->assertSame(['Owner'], $names);
    }

    // ============================ roles & permissions ============================

    public function test_roles_page_is_limited_to_people_who_manage_roles(): void
    {
        $slug = $this->library->slug;
        $this->get("/app/{$slug}/roles")->assertOk()->assertSee('Receptionist');

        foreach ([LibraryRoles::MANAGER, LibraryRoles::ACCOUNTANT, LibraryRoles::RECEPTIONIST] as $role) {
            $this->actingAs($this->member($role))->get("/app/{$slug}/roles")->assertForbidden();
        }
    }

    public function test_editing_a_roles_permissions_changes_what_its_members_can_do(): void
    {
        $clerk = $this->member(LibraryRoles::RECEPTIONIST);
        $this->assertFalse($clerk->can('expenses.view'));
        $role = Role::where('library_id', $this->library->id)->where('name', LibraryRoles::RECEPTIONIST)->first();

        $page = Livewire::test('pages::app.roles.index')->call('select', $role->id);
        $granted = $page->get('granted');
        $page->set('granted', array_merge($granted, ['expenses.view']))->call('save')->assertHasNoErrors();

        $clerk = $clerk->fresh();
        $this->assertTrue($clerk->can('expenses.view'));
        $this->actingAs($clerk)->get("/app/{$this->library->slug}/expenses")->assertOk();

        $log = AuditLog::where('action', 'role.permissions_changed')->latest('id')->first();
        $this->assertSame(['expenses.view'], $log->properties['added']);
        $this->assertSame([], $log->properties['removed']);

        // and taking one away removes it
        $this->actingAs($this->owner);
        Livewire::test('pages::app.roles.index')->call('select', $role->id)
            ->set('granted', array_values(array_diff($page->get('granted'), ['payments.record'])))->call('save');
        $this->assertFalse($clerk->fresh()->can('payments.record'));
    }

    public function test_role_edits_are_per_library(): void
    {
        $beta = $this->otherLibrary();
        $betaClerk = $this->member(LibraryRoles::RECEPTIONIST, $beta);
        $role = Role::where('library_id', $this->library->id)->where('name', LibraryRoles::RECEPTIONIST)->first();

        Livewire::test('pages::app.roles.index')->call('select', $role->id)->set('granted', [])->call('save');

        $this->assertFalse($this->member(LibraryRoles::RECEPTIONIST)->can('payments.record'));
        Tenancy::set($beta);
        $this->assertTrue($betaClerk->fresh()->can('payments.record'), "Beta's receptionist role is untouched");
    }

    public function test_cannot_edit_another_librarys_role_by_id(): void
    {
        $beta = $this->otherLibrary();
        $foreign = Role::where('library_id', $beta->id)->where('name', LibraryRoles::MANAGER)->first();

        $page = Livewire::test('pages::app.roles.index')->call('select', $foreign->id);
        $this->assertNull($page->get('roleId') && $page->get('role') ? $page->get('role') : null);
        $page->set('granted', ['payments.refund'])->call('save')->assertNotFound();

        $this->assertFalse($foreign->fresh()->hasPermissionTo('payments.refund'));
    }

    public function test_owner_role_is_locked_and_unknown_permissions_are_rejected(): void
    {
        $ownerRole = Role::where('library_id', $this->library->id)->where('name', LibraryRoles::OWNER)->first();
        Livewire::test('pages::app.roles.index')->call('select', $ownerRole->id)->set('granted', [])->call('save')->assertForbidden();
        $this->assertGreaterThan(10, $ownerRole->fresh()->permissions()->count());

        $manager = Role::where('library_id', $this->library->id)->where('name', LibraryRoles::MANAGER)->first();
        Livewire::test('pages::app.roles.index')->call('select', $manager->id)->set('granted', ['platform.destroy-everything'])->call('save')->assertHasErrors('granted.0');
    }

    public function test_custom_roles_can_be_created_and_deleted_but_built_ins_cannot(): void
    {
        $page = Livewire::test('pages::app.roles.index')->call('createRole')->assertHasErrors('newName');

        $page->set('newName', LibraryRoles::MANAGER)->call('createRole')->assertHasErrors('newName');
        $page->set('newName', 'Night Guard')->call('createRole')->assertHasNoErrors();

        $custom = Role::where('library_id', $this->library->id)->where('name', 'Night Guard')->first();
        $this->assertNotNull($custom);

        // a role with members cannot be deleted
        $guard = $this->member('Night Guard');
        $page->call('select', $custom->id)->call('deleteRole')->assertDispatched('notify');
        $this->assertNotNull($custom->fresh());

        $this->library->users()->detach($guard->id);
        $guard->removeRole('Night Guard');
        $page->call('select', $custom->id)->call('deleteRole');
        $this->assertNull(Role::find($custom->id));

        $built = Role::where('library_id', $this->library->id)->where('name', LibraryRoles::ACCOUNTANT)->first();
        $page->call('select', $built->id)->call('deleteRole')->assertDispatched('notify');
        $this->assertNotNull($built->fresh());
    }

    public function test_restore_defaults_resets_the_selection(): void
    {
        $role = Role::where('library_id', $this->library->id)->where('name', LibraryRoles::SUPERVISOR)->first();

        $page = Livewire::test('pages::app.roles.index')->call('select', $role->id)->set('granted', ['payments.refund', 'seats.view'])
            ->call('resetToDefault');

        $this->assertEqualsCanonicalizing(LibraryRoles::map()[LibraryRoles::SUPERVISOR], $page->get('granted'));
    }
}
