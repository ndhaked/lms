<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\SubmitSubscriptionPayment;
use App\Actions\CreateStudentLogin;
use App\Actions\RecordPayment;
use App\Actions\RegisterLibrary;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\RenewalRequest;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Livewire\NotificationBell;
use App\Notifications\InAppNotification;
use App\Support\LibraryRoles;
use App\Support\LibrarySettings;
use App\Support\Notifier;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationsPortalTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;
    protected User $owner;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->admin = $this->admin->fresh();
        $this->actingAs($this->admin);

        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'owner@alpha.test']);
        $this->owner = User::where('email', 'owner@alpha.test')->first();
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

    protected function enrolled(?int $daysAhead = 30, float $price = 500): array
    {
        $this->actingAs($this->owner);
        $student = Student::create(['name' => 'Priya Singh', 'phone' => (string) random_int(7000000000, 9999999999), 'email' => 'priya'.random_int(1, 99999).'@x.test']);
        $plan = MembershipPlan::create(['name' => 'Weekly '.uniqid(), 'duration_unit' => 'weekly', 'price' => $price]);
        $start = today()->subDays(6)->addDays($daysAhead);          // ends in $daysAhead days
        $membership = app(AssignMembership::class)($student, $plan, $start);

        return [$student, $membership];
    }

    // ===================== notifier & bell =====================

    public function test_library_notifications_go_only_to_people_with_the_permission(): void
    {
        $manager = $this->member(LibraryRoles::MANAGER);            // memberships.view yes, billing no
        $clerk = $this->member(LibraryRoles::SUPERVISOR);           // neither

        $sent = Notifier::library($this->library, 'memberships.view', 'test.kind', 'Hello');

        $this->assertSame(2, $sent);                                 // owner + manager
        $this->assertSame(1, $manager->notifications()->count());
        $this->assertSame(0, $clerk->notifications()->count());
    }

    public function test_dedupe_keys_prevent_repeats(): void
    {
        Notifier::library($this->library, 'memberships.view', 'k', 'Once', '', null, 'same-key');
        Notifier::library($this->library, 'memberships.view', 'k', 'Once', '', null, 'same-key');

        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_the_bell_shows_only_this_librarys_messages_for_this_user(): void
    {
        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        $this->library->users()->syncWithoutDetaching([$this->owner->id => ['is_active' => true]]);
        $beta->users()->attach($this->owner->id, ['is_active' => true]);
        Tenancy::run($beta, fn () => $this->owner->assignRole(LibraryRoles::OWNER));

        Notifier::user($this->owner, $this->library->id, 'a', 'Alpha message');
        Notifier::user($this->owner, $beta->id, 'b', 'Beta message');
        Notifier::user($this->owner, null, 'p', 'Platform message');

        Tenancy::set($this->library);
        $this->actingAs($this->owner);
        $titles = Livewire::test(NotificationBell::class)->get('items')->pluck('data.title')->all();

        $this->assertSame(['Alpha message'], $titles);

        Tenancy::set($beta);
        $this->assertSame(['Beta message'], Livewire::test(NotificationBell::class)->get('items')->pluck('data.title')->all());
    }

    public function test_reading_and_marking_notifications(): void
    {
        Notifier::user($this->owner, $this->library->id, 'k', 'One', '', route('app.dashboard', ['library' => $this->library->slug]));
        Notifier::user($this->owner, $this->library->id, 'k', 'Two');
        $this->actingAs($this->owner);

        $bell = Livewire::test(NotificationBell::class);
        $this->assertSame(2, $bell->get('unread'));

        $first = $this->owner->notifications()->where('data->title', 'One')->first();
        $bell->call('read', $first->id)->assertRedirect(route('app.dashboard', ['library' => $this->library->slug]));
        $this->assertNotNull($first->fresh()->read_at);

        $bell->call('markAllRead');
        $this->assertSame(0, Livewire::test(NotificationBell::class)->get('unread'));
    }

    public function test_cannot_read_someone_elses_notification_or_follow_external_links(): void
    {
        $other = $this->member(LibraryRoles::MANAGER);
        Notifier::user($other, $this->library->id, 'k', 'Private');
        $theirs = $other->notifications()->first();

        $this->actingAs($this->owner);
        Livewire::test(NotificationBell::class)->call('read', $theirs->id);
        $this->assertNull($theirs->fresh()->read_at, "another user's notification is untouched");

        Notifier::user($this->owner, $this->library->id, 'k', 'Evil', '', 'https://evil.example/phish');
        $evil = $this->owner->notifications()->where('data->title', 'Evil')->first();
        Livewire::test(NotificationBell::class)->call('read', $evil->id)->assertNoRedirect();
    }

    public function test_the_bell_renders_in_every_panel(): void
    {
        Notifier::user($this->owner, $this->library->id, 'k', 'Library note');
        Notifier::user($this->admin, null, 'k', 'Platform note');

        $this->actingAs($this->owner)->get("/app/{$this->library->slug}")->assertOk()->assertSee('Notifications');
        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('Notifications');
    }

    // ===================== scheduled reminders =====================

    public function test_membership_expiry_reminders_are_sent_once_per_day(): void
    {
        $this->enrolled(3);                          // ends in 3 days (window is 7)
        $this->enrolled(40);                         // far away: not included

        $this->artisan('library:send-reminders')->assertSuccessful();
        $first = DatabaseNotification::where('data->kind', 'membership.expiring')->count();
        $this->assertGreaterThan(0, $first);
        $note = $this->owner->notifications()->where('data->kind', 'membership.expiring')->first();
        $this->assertStringContainsString('1 membership', $note->data['title']);

        $this->artisan('library:send-reminders');    // same day again
        $this->assertSame($first, DatabaseNotification::where('data->kind', 'membership.expiring')->count());
    }

    public function test_overdue_fee_reminder_and_settings_switches(): void
    {
        [$s] = $this->enrolled(-10, 800);            // ended 10 days ago, nothing paid

        LibrarySettings::update(['notify_dues' => false, 'notify_expiry' => false]);
        $this->artisan('library:send-reminders');
        $this->assertSame(0, DatabaseNotification::count(), 'switched off in settings');

        LibrarySettings::update(['notify_dues' => true]);
        $this->artisan('library:send-reminders');
        $n = $this->owner->notifications()->where('data->kind', 'dues.overdue')->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('800.00', $n->data['title']);

        // paid up: no reminder next time
        DatabaseNotification::query()->delete();
        $this->actingAs($this->owner);
        app(RecordPayment::class)($s, 800, 'cash', today(), StudentMembership::first());
        $this->artisan('library:send-reminders');
        $this->assertSame(0, DatabaseNotification::where('data->kind', 'dues.overdue')->count());
    }

    public function test_reminders_skip_inactive_libraries_and_are_tenant_scoped(): void
    {
        $this->enrolled(2);
        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'suspended'], ['name' => 'B', 'email' => 'b@beta.test']);
        Tenancy::run($beta, fn () => $this->actingAs(User::where('email', 'b@beta.test')->first()));

        $this->artisan('library:send-reminders');

        $betaOwner = User::where('email', 'b@beta.test')->first();
        $this->assertSame(0, $betaOwner->notifications()->count());
        $this->assertGreaterThan(0, $this->owner->notifications()->count());
    }

    public function test_students_with_logins_are_told_about_their_own_expiry(): void
    {
        [$student] = $this->enrolled(2);
        $this->actingAs($this->owner);
        $login = app(CreateStudentLogin::class)($student);

        $this->artisan('library:send-reminders');

        $mine = $login->fresh()->notifications()->where('data->kind', 'membership.expiring_self')->first();
        $this->assertNotNull($mine);
    }

    // ===================== subscription sync =====================

    public function test_sync_expires_lapsed_subscriptions_with_history_and_a_single_notification(): void
    {
        $plan = SubscriptionPlan::create(['name' => 'Std', 'price_monthly' => 100, 'trial_days' => 5, 'grace_days' => 3]);
        $this->actingAs($this->admin);
        $s = app(CreateSubscription::class)($this->library, $plan, 'monthly', true);
        $s->forceFill(['trial_ends_on' => today()->subDays(10), 'expires_on' => today()->subDays(10)])->save();   // past the 3 grace days

        auth()->logout();   // cron has no signed-in user
        $this->artisan('subscriptions:sync')->assertSuccessful();

        $this->assertSame('expired', $s->fresh()->status);
        $event = $s->events()->where('type', 'expired')->first();
        $this->assertSame('trial', $event->from_status);
        $this->assertNull($event->user_id, 'done by the system');
        $this->assertSame(1, $this->owner->notifications()->where('data->kind', 'subscription.expired')->count());

        $this->artisan('subscriptions:sync');         // idempotent
        $this->assertSame(1, $this->owner->notifications()->where('data->kind', 'subscription.expired')->count());
        $this->assertSame(1, $s->events()->where('type', 'expired')->count());
    }

    public function test_sync_sends_grace_and_expiring_soon_notices_without_changing_status(): void
    {
        $plan = SubscriptionPlan::create(['name' => 'Std', 'price_monthly' => 100, 'grace_days' => 5]);
        $this->actingAs($this->admin);
        $s = app(CreateSubscription::class)($this->library, $plan, 'monthly');
        $s->forceFill(['status' => 'active', 'starts_on' => today()->subMonth(), 'expires_on' => today()->subDays(2)])->save();

        $this->artisan('subscriptions:sync');
        $this->assertSame('active', $s->fresh()->status, 'still in grace: the stored status is untouched');
        $this->assertSame(1, $this->owner->notifications()->where('data->kind', 'subscription.grace')->count());

        $s->forceFill(['expires_on' => today()->addDays(3)])->save();
        $this->artisan('subscriptions:sync');
        $this->assertSame(1, $this->owner->notifications()->where('data->kind', 'subscription.expiring')->count());
    }

    public function test_submitting_a_payment_notifies_only_admins_who_can_approve(): void
    {
        Storage::fake('local');
        $plan = SubscriptionPlan::create(['name' => 'Std', 'price_monthly' => 100]);
        $this->actingAs($this->admin);
        app(CreateSubscription::class)($this->library, $plan, 'monthly');

        $approver = User::factory()->create();
        $approver->forceFill(['is_super_admin' => true, 'platform_permissions' => ['payments.approve']])->save();
        $auditor = User::factory()->create();
        $auditor->forceFill(['is_super_admin' => true, 'platform_permissions' => ['audit.view']])->save();

        $this->actingAs($this->owner);
        app(SubmitSubscriptionPayment::class)($this->library, $plan, 'monthly', 'REF1', 100, 'upi', today(), null, [UploadedFile::fake()->image('p.png')]);

        $this->assertSame(1, $approver->notifications()->where('data->kind', 'subscription.payment_submitted')->count());
        $this->assertSame(0, $auditor->notifications()->count());
        $this->assertSame(1, $this->admin->notifications()->where('data->kind', 'subscription.payment_submitted')->count());
    }

    // ===================== student portal =====================

    public function test_creating_a_student_login(): void
    {
        Notification::fake();
        $this->actingAs($this->owner);
        $student = Student::create(['name' => 'Amit', 'phone' => '9000000050', 'email' => 'amit@x.test']);

        $user = app(CreateStudentLogin::class)($student);

        $this->assertSame($user->id, $student->fresh()->user_id);
        $this->assertTrue($this->library->users()->where('users.id', $user->id)->exists());
        Tenancy::set($this->library);
        $this->assertTrue($user->fresh()->hasRole(LibraryRoles::STUDENT));
        $this->assertFalse($user->fresh()->can('students.view'));
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertNotNull(AuditLog::where('action', 'student.login_created')->first());
    }

    public function test_student_login_rules(): void
    {
        Notification::fake();
        $this->actingAs($this->owner);
        $noEmail = Student::create(['name' => 'No Email', 'phone' => '9000000051']);
        $staffEmail = Student::create(['name' => 'Also Staff', 'phone' => '9000000052', 'email' => 'owner@alpha.test']);
        $ok = Student::create(['name' => 'Fine', 'phone' => '9000000053', 'email' => 'fine@x.test']);

        foreach ([$noEmail, $staffEmail] as $bad) {
            try {
                app(CreateStudentLogin::class)($bad);
                $this->fail('login created for '.$bad->name);
            } catch (BusinessRuleException) {
                $this->assertTrue(true);
            }
        }

        app(CreateStudentLogin::class)($ok);
        $this->expectException(BusinessRuleException::class);   // already has one
        app(CreateStudentLogin::class)($ok->fresh());
    }

    public function test_receptionists_may_not_create_logins_without_student_update(): void
    {
        $this->actingAs($this->member(LibraryRoles::SUPERVISOR));
        $student = Student::create(['name' => 'X', 'phone' => '9000000054', 'email' => 'x@x.test']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(CreateStudentLogin::class)($student);
    }

    public function test_a_student_sees_only_their_own_data(): void
    {
        Notification::fake();
        [$mine, $myMembership] = $this->enrolled(20, 500);
        $this->actingAs($this->owner);
        app(RecordPayment::class)($mine, 200, 'cash', today(), $myMembership);
        $login = app(CreateStudentLogin::class)($mine);

        $other = Student::create(['name' => 'Other Person', 'phone' => '9000000060', 'email' => 'other@x.test']);
        $otherMembership = app(AssignMembership::class)($other, MembershipPlan::first(), today());
        $otherPayment = app(RecordPayment::class)($other, 100, 'upi', today(), $otherMembership);

        $this->actingAs($login->fresh());
        $slug = $this->library->slug;

        $this->get("/app/{$slug}/my")->assertOk()->assertSee('Priya Singh')->assertDontSee('Other Person')->assertSee('300.00');
        $this->get("/app/{$slug}")->assertRedirect("/app/{$slug}/my");                     // dashboard sends students home
        $this->get('/')->assertRedirect("/app/{$slug}");

        // own receipt yes, someone else's no
        $own = Payment::where('student_id', $mine->id)->first();
        $this->get("/app/{$slug}/my/receipts/{$own->id}")->assertOk();
        $this->get("/app/{$slug}/my/receipts/{$otherPayment->id}")->assertNotFound();

        // no staff pages at all
        foreach (['students', 'payments', 'memberships', 'seats', 'reports', 'staff', 'settings', 'expenses', 'dues'] as $page) {
            $this->get("/app/{$slug}/{$page}")->assertForbidden();
        }
        $this->get("/app/{$slug}/payments/{$own->id}/receipt")->assertForbidden();         // the staff route stays closed
    }

    public function test_a_login_without_a_student_record_gets_nothing(): void
    {
        $orphan = $this->member(LibraryRoles::STUDENT);   // Student role but no linked student row
        $this->actingAs($orphan)->get("/app/{$this->library->slug}/my")->assertNotFound();
    }

    public function test_renewal_request_flow(): void
    {
        [$student] = $this->enrolled(5);
        $this->actingAs($this->owner);
        $login = app(CreateStudentLogin::class)($student);
        $plan = MembershipPlan::first();

        $this->actingAs($login->fresh());
        Livewire::test('pages::app.my.index')->call('openRenew')->set('planId', (string) $plan->id)->set('message', 'Please renew')->call('sendRenew')->assertHasNoErrors();

        $req = RenewalRequest::first();
        $this->assertSame('pending', $req->status);
        $this->assertSame($student->id, $req->student_id);
        $this->assertSame(1, $this->owner->notifications()->where('data->kind', 'membership.renewal_requested')->count());

        // one request at a time
        Livewire::test('pages::app.my.index')->call('openRenew')->assertStatus(422);

        // staff see it and answer it by assigning a membership
        $this->actingAs($this->owner);
        $this->get("/app/{$this->library->slug}/memberships")->assertOk()->assertSee('Renewal requests')->assertSee('Please renew');

        Livewire::test('pages::app.memberships.index')->call('assignFromRequest', $req->id)
            ->assertSet('studentId', $student->id)->assertSet('planId', (string) $plan->id)
            ->set('startsOn', today()->addDays(6)->toDateString())->call('saveAssign')->assertHasNoErrors();

        $this->assertSame('fulfilled', $req->fresh()->status);
        $this->assertNotNull($req->fresh()->handled_at);
    }

    public function test_renewal_request_can_be_dismissed_and_rejects_foreign_plans(): void
    {
        Notification::fake();
        [$student] = $this->enrolled(5);
        $this->actingAs($this->owner);
        $login = app(CreateStudentLogin::class)($student);

        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        $foreignPlan = Tenancy::run($beta, fn () => MembershipPlan::create(['name' => 'Foreign', 'duration_unit' => 'monthly', 'price' => 1]));
        Tenancy::set($this->library);

        $this->actingAs($login->fresh());
        Livewire::test('pages::app.my.index')->call('openRenew')->set('planId', (string) $foreignPlan->id)->call('sendRenew')->assertHasErrors('planId');
        $this->assertSame(0, RenewalRequest::count());

        Livewire::test('pages::app.my.index')->call('openRenew')->call('sendRenew')->assertHasNoErrors();
        $req = RenewalRequest::first();

        $this->actingAs($this->owner);
        Livewire::test('pages::app.memberships.index')->call('dismissRequest', $req->id);
        $this->assertSame('dismissed', $req->fresh()->status);
    }

    // ===================== student export & duplicates =====================

    public function test_student_export_permissions_and_switches(): void
    {
        $this->actingAs($this->owner);
        Student::create(['name' => 'Export Me', 'phone' => '9000000070']);
        $slug = $this->library->slug;

        $csv = $this->get("/app/{$slug}/students/export/csv");
        $csv->assertOk();
        $this->assertStringContainsString('Export Me', $csv->streamedContent());
        $this->assertNotNull(AuditLog::where('action', 'report.exported')->where('properties->report', 'student-registration')->first());

        $this->get("/app/{$slug}/students/export/xlsx")->assertOk();
        $this->get("/app/{$slug}/students/export/docx")->assertNotFound();

        $this->actingAs($this->member(LibraryRoles::MANAGER))->get("/app/{$slug}/students/export/csv")->assertForbidden();   // no students.export
        $this->actingAs($this->member(LibraryRoles::RECEPTIONIST))->get("/app/{$slug}/students/export/csv")->assertForbidden();

        $this->actingAs($this->owner);
        LibrarySettings::update(['export_enabled' => false]);
        $this->get("/app/{$slug}/students/export/csv")->assertForbidden();
    }

    public function test_student_export_works_without_the_reports_module(): void
    {
        $plan = SubscriptionPlan::create(['name' => 'NoReports', 'price_monthly' => 10, 'modules' => ['exports']]);
        $this->actingAs($this->admin);
        $s = app(CreateSubscription::class)($this->library, $plan, 'monthly', false);
        $s->forceFill(['status' => 'active', 'starts_on' => today(), 'expires_on' => today()->addMonth()])->save();
        $this->actingAs($this->owner);
        Student::create(['name' => 'Plain', 'phone' => '9000000071']);

        $this->get("/app/{$this->library->slug}/reports")->assertForbidden();                   // module off
        $this->get("/app/{$this->library->slug}/students/export/csv")->assertOk();              // student export is separate
    }

    public function test_duplicate_registration_detection(): void
    {
        $this->actingAs($this->owner);
        Student::create(['name' => 'Rahul Sharma', 'phone' => '9000000080', 'email' => 'rahul@x.test']);
        $fill = fn ($c, $name, $phone, $email = '') => $c->set('name', $name)->set('phone', $phone)->set('email', $email)->set('registered_on', today()->toDateString());

        // same phone: hard block
        $fill(Livewire::test('pages::app.students.form'), 'Different Name', '9000000080')->call('save')->assertHasErrors('phone');

        // same name (any case) or same email: blocked until confirmed
        $page = $fill(Livewire::test('pages::app.students.form'), 'rahul sharma', '9000000081')->call('save')->assertHasErrors('name');
        $this->assertSame(1, Student::count());
        $this->assertCount(1, $page->get('duplicates'));

        $page->set('confirmDuplicate', true)->call('save')->assertHasNoErrors();
        $this->assertSame(2, Student::count());

        $byEmail = $fill(Livewire::test('pages::app.students.form'), 'Someone Else', '9000000082', 'rahul@x.test')->call('save')->assertHasErrors('name');
        $this->assertSame(2, Student::count());

        // editing an existing student never trips the duplicate check
        $existing = Student::where('phone', '9000000081')->first();
        Livewire::test('pages::app.students.form', ['student' => (string) $existing->id])->set('notes', 'updated')->call('save')->assertHasNoErrors();
    }
}
