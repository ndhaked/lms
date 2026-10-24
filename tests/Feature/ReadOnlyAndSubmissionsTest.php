<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\Billing\GrantFreePeriod;
use App\Actions\CreateStudentLogin;
use App\Actions\RegisterLibrary;
use App\Exceptions\ReadOnlyLibraryException;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\PaymentSubmission;
use App\Models\Student;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\Entitlements;
use App\Support\LibraryRoles;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReadOnlyAndSubmissionsTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;
    protected User $owner;
    protected User $root;
    protected SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['billing.require_subscription' => true]);

        $this->root = User::factory()->create();
        $this->root->forceFill(['is_super_admin' => true])->save();
        $this->root = $this->root->fresh();
        $this->actingAs($this->root);

        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@alpha.test']);
        $this->owner = User::where('email', 'o@alpha.test')->first();
        $this->plan = SubscriptionPlan::create(['name' => 'Std', 'price_monthly' => 1000]);
    }

    protected function tearDown(): void
    {
        BillingSettings::flush();
        LibrarySettings::flush();
        Entitlements::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    /** Give the library a plan that has already run out beyond its grace period. */
    protected function expire(): void
    {
        $previous = auth()->user();
        $this->actingAs($this->root);
        $s = app(GrantFreePeriod::class)($this->library, $this->plan, 15, 'setup');
        $s->forceFill(['expires_on' => today()->subDays(60)])->save();
        Entitlements::flush();
        $this->actingAs($previous);
    }

    protected function setPolicy(?string $platform = null, ?string $library = null): void
    {
        if ($platform) {
            BillingSettings::set(['expiry_policy' => $platform]);
        }
        $this->library->forceFill(['expiry_policy' => $library])->save();
        Entitlements::flush();
    }

    // ===================== expiry policy =====================

    public function test_locked_policy_keeps_the_old_behaviour_for_expired_and_no_plan(): void
    {
        $this->setPolicy('billing_only');
        $this->actingAs($this->owner);

        $this->assertSame('restricted', Entitlements::state($this->library->id));
        $this->get("/app/{$this->library->slug}/students")->assertRedirect();

        $this->expire();
        $this->assertSame('restricted', Entitlements::state($this->library->id));
        $this->get("/app/{$this->library->slug}")->assertRedirect(route('app.subscription.index', $this->library->slug));
    }

    public function test_read_only_policy_lets_an_expired_library_look_but_not_change(): void
    {
        $this->setPolicy('read_only');
        $this->expire();
        Tenancy::set($this->library);
        $this->actingAs($this->owner);

        $this->assertSame('readonly', Entitlements::state());
        $this->get("/app/{$this->library->slug}/students")->assertOk();
        $this->get("/app/{$this->library->slug}")->assertOk()->assertSee('read-only');

        try {
            Student::create(['name' => 'Late', 'phone' => '9000000001']);
            $this->fail('write allowed in read-only mode');
        } catch (ReadOnlyLibraryException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, Student::count());

        $this->expectException(ReadOnlyLibraryException::class);
        LibrarySettings::update(['seat_prefix' => 'Z-']);
    }

    public function test_read_only_also_applies_when_a_library_has_no_plan(): void
    {
        $this->setPolicy('read_only');
        Tenancy::set($this->library);
        $this->actingAs($this->owner);

        $this->assertSame('readonly', Entitlements::state());
        $this->get("/app/{$this->library->slug}/students")->assertOk();
        $this->expectException(ReadOnlyLibraryException::class);
        Student::create(['name' => 'X', 'phone' => '9000000002']);
    }

    public function test_the_per_library_rule_overrides_the_platform_default(): void
    {
        $this->setPolicy('read_only', 'billing_only');
        $this->assertSame('restricted', Entitlements::state($this->library->id));

        $this->setPolicy('billing_only', 'read_only');
        $this->assertSame('readonly', Entitlements::state($this->library->id));

        $this->setPolicy('read_only', null);   // follows the default again
        $this->assertSame('readonly', Entitlements::state($this->library->id));
    }

    public function test_suspended_libraries_stay_fully_locked_even_with_read_only(): void
    {
        $this->setPolicy('read_only');
        $s = app(GrantFreePeriod::class)($this->library, $this->plan, 30, 'x');
        app(\App\Actions\Billing\ManageSubscription::class)->suspend($s, 'abuse');
        Entitlements::flush();

        $this->assertSame('restricted', Entitlements::state($this->library->id));
    }

    public function test_renewing_restores_write_access(): void
    {
        $this->setPolicy('read_only');
        $this->expire();
        Tenancy::set($this->library);
        $this->assertTrue(Entitlements::readOnly());

        app(GrantFreePeriod::class)($this->library, $this->plan, 30, 'renewed');
        Entitlements::flush();

        $this->assertFalse(Entitlements::readOnly());
        $this->assertNotNull(Student::create(['name' => 'Ok', 'phone' => '9000000003']));
    }

    public function test_the_billing_pages_keep_working_when_read_only(): void
    {
        $this->setPolicy('read_only');
        $this->expire();
        $this->actingAs($this->owner);
        Tenancy::set($this->library);

        Livewire::test('pages::app.subscription.index')->call('openSupport')->set('subject', 'Renew')->set('supportMessage', 'Please')->call('sendSupport')->assertHasNoErrors();
        $this->assertSame(1, \App\Models\SupportRequest::count());
    }

    public function test_staff_and_role_changes_are_blocked_when_read_only(): void
    {
        $this->setPolicy('read_only');
        $this->expire();
        $this->actingAs($this->owner);
        Tenancy::set($this->library);

        Livewire::test('pages::app.roles.index')->call('createRole')->assertForbidden();
    }

    public function test_platform_admin_sets_the_rule_per_library(): void
    {
        Livewire::test('pages::admin.libraries.show', ['library' => (string) $this->library->id])
            ->set('expiryPolicy', 'read_only')->call('savePolicy')->assertHasNoErrors();
        $this->assertSame('read_only', $this->library->fresh()->expiry_policy);

        Livewire::test('pages::admin.libraries.show', ['library' => (string) $this->library->id])
            ->set('expiryPolicy', 'bogus')->call('savePolicy')->assertHasErrors('expiryPolicy');

        Livewire::test('pages::admin.libraries.show', ['library' => (string) $this->library->id])
            ->set('expiryPolicy', '')->call('savePolicy');
        $this->assertNull($this->library->fresh()->expiry_policy);
        $this->assertNotNull(\App\Models\AuditLog::where('action', 'library.expiry_policy_changed')->first());

        $limited = User::factory()->create();
        $limited->forceFill(['is_super_admin' => true, 'platform_permissions' => ['libraries.manage']])->save();
        $this->actingAs($limited->fresh());
        Livewire::test('pages::admin.libraries.show', ['library' => (string) $this->library->id])->set('expiryPolicy', 'read_only')->call('savePolicy')->assertForbidden();
    }

    public function test_platform_default_is_chosen_in_settings(): void
    {
        Livewire::test('pages::admin.settings.index')->set('expiry_policy', 'nope')->call('saveSubscription')->assertHasErrors('expiry_policy');
        Livewire::test('pages::admin.settings.index')->set('expiry_policy', 'read_only')->call('saveSubscription')->assertHasNoErrors();

        $this->assertSame('read_only', BillingSettings::get('expiry_policy'));
        $this->assertSame(7, BillingSettings::get('default_grace_days'), 'numeric settings are untouched');
    }

    // ===================== student payment submissions =====================

    protected function studentWithDues(float $price = 500): array
    {
        $this->actingAs($this->root);
        app(GrantFreePeriod::class)($this->library, $this->plan, 60, 'setup');
        Tenancy::set($this->library);
        $this->actingAs($this->owner);

        $student = Student::create(['name' => 'Priya', 'phone' => '9000000010', 'email' => 'priya@x.test']);
        $membership = app(AssignMembership::class)($student, MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => $price]), today());
        $login = app(CreateStudentLogin::class)($student);
        LibrarySettings::update(['student_payment_submissions' => true]);

        return [$student, $membership, $login->fresh()];
    }

    protected function submit(User $login, array $over = [])
    {
        $this->actingAs($login);
        Tenancy::set($this->library);

        $t = Livewire::test('pages::app.my.index')->call('openPaid');
        foreach (array_merge(['subAmount' => '200', 'subMethod' => 'upi', 'subReference' => 'UTR'.uniqid()], $over) as $k => $v) {
            $t->set($k, $v);
        }

        return $t->call('sendPaid');
    }

    public function test_student_sends_details_and_staff_confirm_them_into_a_real_payment(): void
    {
        [$student, $membership, $login] = $this->studentWithDues(500);

        $this->submit($login, ['subAmount' => '200', 'subReference' => 'UTR1', 'subProof' => UploadedFile::fake()->image('p.png')])->assertHasNoErrors();

        $sub = PaymentSubmission::first();
        $this->assertSame('pending', $sub->status);
        $this->assertSame('200.00', (string) $sub->amount);
        $this->assertNotNull($sub->proof_path);
        $this->assertSame(0, Payment::count(), 'nothing is recorded before staff confirm');
        $this->assertSame('500.00', $membership->fresh()->outstanding());
        $this->assertSame(1, $this->owner->notifications()->where('data->kind', 'payment_submission.new')->count());

        $this->actingAs($this->owner);
        Tenancy::set($this->library);
        Livewire::test('pages::app.payment-submissions.index')->call('start', $sub->id, 'approve')->call('confirm')->assertHasNoErrors();

        $sub->refresh();
        $this->assertSame('approved', $sub->status);
        $this->assertNotNull($sub->payment_id);
        $this->assertSame('300.00', $membership->fresh()->outstanding());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, $login->notifications()->where('data->kind', 'payment_submission.approved')->count());

        // a second decision on the same claim is refused and cannot double-record
        Livewire::test('pages::app.payment-submissions.index')->call('start', $sub->id, 'approve')->call('confirm')->assertHasErrors('decision');
        $this->assertSame(1, Payment::count());
    }

    public function test_rejection_needs_a_reason_and_records_nothing(): void
    {
        [, $membership, $login] = $this->studentWithDues(500);
        $this->submit($login);
        $sub = PaymentSubmission::first();

        $this->actingAs($this->owner);
        Tenancy::set($this->library);
        Livewire::test('pages::app.payment-submissions.index')->call('start', $sub->id, 'reject')->call('confirm')->assertHasErrors('reason');
        Livewire::test('pages::app.payment-submissions.index')->call('start', $sub->id, 'reject')->set('reason', 'Money not received')->call('confirm')->assertHasNoErrors();

        $this->assertSame('rejected', $sub->fresh()->status);
        $this->assertSame(0, Payment::count());
        $this->assertSame('500.00', $membership->fresh()->outstanding());
        $this->assertSame(1, $login->notifications()->where('data->kind', 'payment_submission.rejected')->count());
    }

    public function test_submission_rules(): void
    {
        [, $membership, $login] = $this->studentWithDues(500);

        $this->submit($login, ['subAmount' => '900'])->assertHasErrors('subAmount');           // more than owed
        $this->submit($login, ['subAmount' => '0'])->assertHasErrors('subAmount');
        $this->submit($login, ['subReference' => ''])->assertHasErrors('subReference');        // reference needed unless cash
        $this->submit($login, ['subMethod' => 'cash', 'subReference' => ''])->assertHasNoErrors();
        $this->submit($login, ['subPaidOn' => today()->addDay()->toDateString()])->assertHasErrors('subPaidOn');
        $this->submit($login, ['subProof' => UploadedFile::fake()->create('x.exe', 10)])->assertHasErrors('subProof');

        // pending claims count against what is still claimable
        $this->assertSame(1, PaymentSubmission::count());
        $this->submit($login, ['subAmount' => '301', 'subReference' => 'R2'])->assertHasErrors('subAmount');   // 500 - 200 pending

        // the same reference cannot be sent twice
        $this->submit($login, ['subAmount' => '50', 'subReference' => 'DUP1'])->assertHasNoErrors();
        $this->submit($login, ['subAmount' => '50', 'subReference' => 'DUP1'])->assertHasErrors('subReference');
    }

    public function test_at_most_three_claims_can_wait_at_once(): void
    {
        [, , $login] = $this->studentWithDues(5000);
        foreach ([1, 2, 3] as $i) {
            $this->submit($login, ['subAmount' => '10', 'subReference' => "A{$i}"])->assertHasNoErrors();
        }
        $this->submit($login, ['subAmount' => '10', 'subReference' => 'A4'])->assertHasErrors('subAmount');
        $this->assertSame(3, PaymentSubmission::count());
    }

    public function test_feature_is_off_by_default_and_can_be_switched_off(): void
    {
        [, , $login] = $this->studentWithDues(500);
        LibrarySettings::update(['student_payment_submissions' => false]);

        $this->actingAs($login);
        Tenancy::set($this->library);
        $this->get("/app/{$this->library->slug}/my")->assertOk()->assertDontSee('I have paid');
        Livewire::test('pages::app.my.index')->call('openPaid')->assertForbidden();
    }

    public function test_review_screen_and_proof_are_private(): void
    {
        [$student, , $login] = $this->studentWithDues(500);
        $this->submit($login, ['subProof' => UploadedFile::fake()->image('p.png')]);
        $sub = PaymentSubmission::first();
        $proof = "/app/{$this->library->slug}/payment-submissions/{$sub->id}/proof";

        // the student who sent it and staff may open the proof; students cannot open the staff screen
        $this->actingAs($login)->get($proof)->assertOk();
        $this->get("/app/{$this->library->slug}/payment-submissions")->assertForbidden();
        $this->actingAs($this->owner)->get($proof)->assertOk();
        $this->get("/app/{$this->library->slug}/payment-submissions")->assertOk()->assertSee('Priya');

        // another student in the same library, and another library's owner, are refused
        $other = Student::create(['name' => 'Other', 'phone' => '9000000011', 'email' => 'other@x.test']);
        $otherLogin = app(CreateStudentLogin::class)($other)->fresh();
        $this->actingAs($otherLogin)->get($proof)->assertForbidden();

        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        $betaOwner = User::where('email', 'b@beta.test')->first();
        $this->actingAs($this->root);
        app(GrantFreePeriod::class)($beta, $this->plan, 30, 'x');
        $this->actingAs($betaOwner)->get("/app/{$beta->slug}/payment-submissions/{$sub->id}/proof")->assertNotFound();
    }

    public function test_a_student_cannot_claim_someone_elses_membership(): void
    {
        [, $membership, $login] = $this->studentWithDues(500);

        $this->actingAs($this->owner);
        Tenancy::set($this->library);
        $other = Student::create(['name' => 'Other', 'phone' => '9000000012', 'email' => 'o2@x.test']);
        $theirs = app(AssignMembership::class)($other, MembershipPlan::first(), today());

        $this->actingAs($login);
        Livewire::test('pages::app.my.index')->call('openPaid')->set('subMembership', (string) $theirs->id)->set('subAmount', '100')->set('subReference', 'ZZ')->call('sendPaid')->assertHasErrors('subMembership');
        $this->assertSame(0, PaymentSubmission::count());
    }

    public function test_submissions_are_blocked_when_the_library_is_read_only(): void
    {
        [, , $login] = $this->studentWithDues(500);
        $this->setPolicy('read_only');
        $s = \App\Models\Subscription::currentFor($this->library->id);
        $s->forceFill(['expires_on' => today()->subDays(60)])->save();
        Entitlements::flush();

        $this->submit($login)->assertForbidden();
        $this->assertSame(0, PaymentSubmission::count());
    }

    public function test_the_staff_setting_toggles_the_feature(): void
    {
        $this->studentWithDues(500);
        $this->actingAs($this->owner);
        Tenancy::set($this->library);

        Livewire::test('pages::app.settings.index')->set('student_payment_submissions', false)->call('saveMemberships')->assertHasNoErrors();
        $this->assertFalse(LibrarySettings::get('student_payment_submissions'));
        Livewire::test('pages::app.settings.index')->set('student_payment_submissions', true)->call('saveMemberships')->assertHasNoErrors();
        $this->assertTrue(LibrarySettings::get('student_payment_submissions'));
    }
}
