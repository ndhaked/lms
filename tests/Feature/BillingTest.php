<?php

namespace Tests\Feature;

use App\Actions\Billing\ApproveSubscriptionPayment;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\ManageSubscription;
use App\Actions\Billing\RespondToPaymentInfoRequest;
use App\Actions\Billing\ReviewSubscriptionPayment;
use App\Actions\Billing\SubmitSubscriptionPayment;
use App\Actions\RegisterLibrary;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Seat;
use App\Models\Student;
use App\Models\StudyHall;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Support\BillingSettings;
use App\Support\Entitlements;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;
    protected User $owner;
    protected User $admin;
    protected SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->admin = $this->admin->fresh();

        $this->plan = SubscriptionPlan::create([
            'name' => 'Standard', 'price_monthly' => 1000, 'price_quarterly' => 2700, 'price_annual' => 10000,
            'trial_days' => 14, 'max_students' => 5, 'max_seats' => 4, 'max_staff' => 2, 'modules' => ['attendance', 'reports'], 'grace_days' => 7,
        ]);

        $this->actingAs($this->admin);
        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'owner@alpha.test']);
        $this->owner = User::where('email', 'owner@alpha.test')->first();
        Tenancy::set($this->library);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        BillingSettings::flush();
        Entitlements::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function subscribe(bool $trial = false, string $cycle = 'monthly'): Subscription
    {
        $this->actingAs($this->admin);

        return app(CreateSubscription::class)($this->library, $this->plan, $cycle, $trial);
    }

    protected function submit(Subscription $s, array $override = []): SubscriptionPayment
    {
        $this->actingAs($this->owner);
        $args = array_merge([
            'plan' => $this->plan, 'cycle' => 'monthly', 'reference' => 'UTR'.random_int(1000, 9999), 'amount' => 1000, 'method' => 'upi',
            'paidOn' => today(), 'note' => null, 'files' => [UploadedFile::fake()->image('proof.png')],
        ], $override);

        return app(SubmitSubscriptionPayment::class)($this->library, $args['plan'], $args['cycle'], $args['reference'], $args['amount'], $args['method'], $args['paidOn'], $args['note'], $args['files']);
    }

    protected function approve(SubscriptionPayment $p, ?string $note = null): SubscriptionPayment
    {
        $this->actingAs($this->admin);

        return app(ApproveSubscriptionPayment::class)($p, $note);
    }

    protected function platformAdmin(array $permissions): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true, 'platform_permissions' => $permissions])->save();

        return $u->fresh();
    }

    protected function libraryUser(string $role): User
    {
        $u = User::factory()->create();
        $this->library->users()->attach($u->id, ['is_active' => true]);
        Tenancy::run($this->library, fn () => $u->assignRole($role));

        return $u->fresh();
    }

    // ===================== plans & creation =====================

    public function test_plan_prices_and_cycles(): void
    {
        $this->assertSame('1000.00', $this->plan->priceFor('monthly'));
        $this->assertNull($this->plan->priceFor('weekly'));

        $partial = SubscriptionPlan::create(['name' => 'Annual only', 'price_annual' => 5000]);
        $this->assertSame(['annual'], $partial->cycles());
    }

    public function test_trial_and_pending_subscriptions_are_created_with_snapshots(): void
    {
        $s = $this->subscribe(trial: true);

        $this->assertSame('trial', $s->status);
        $this->assertTrue($s->trial_ends_on->isSameDay(today()->addDays(13)), '14 trial days including today');
        $this->assertSame(5, $s->max_students);
        $this->assertSame('Standard', $s->plan_name);
        $this->assertSame(['attendance', 'reports'], $s->modules);
        $this->assertNotNull(SubscriptionEvent::where('subscription_id', $s->id)->where('type', 'created')->first());
    }

    public function test_creation_rules_and_permissions(): void
    {
        $this->subscribe();

        $this->expectException(BusinessRuleException::class);   // already has a subscription
        app(CreateSubscription::class)($this->library, $this->plan);
    }

    public function test_cannot_create_with_inactive_plan_unsold_cycle_or_without_trial(): void
    {
        $inactive = SubscriptionPlan::create(['name' => 'Old', 'price_monthly' => 10, 'status' => 'inactive']);
        $annualOnly = SubscriptionPlan::create(['name' => 'Yearly', 'price_annual' => 100, 'trial_days' => 0]);

        foreach ([fn () => app(CreateSubscription::class)($this->library, $inactive),
            fn () => app(CreateSubscription::class)($this->library, $annualOnly, 'monthly'),
            fn () => app(CreateSubscription::class)($this->library, $annualOnly, 'annual', true)] as $attempt) {
            try {
                $attempt();
                $this->fail('invalid subscription created');
            } catch (BusinessRuleException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, Subscription::count());
    }

    public function test_only_platform_staff_with_the_permission_can_create_or_change_subscriptions(): void
    {
        $this->actingAs($this->owner);   // a library owner is not platform staff
        try {
            app(CreateSubscription::class)($this->library, $this->plan);
            $this->fail('library owner created a subscription');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->actingAs($this->platformAdmin(['audit.view']));
        $this->expectException(HttpException::class);
        app(CreateSubscription::class)($this->library, $this->plan);
    }

    // ===================== lifecycle over time =====================

    public function test_effective_status_follows_dates_grace_and_expiry(): void
    {
        $s = $this->subscribe(trial: true);
        $this->assertSame('trial', $s->effectiveStatus());
        $this->assertSame('full', $s->accessState());

        Carbon::setTestNow(today()->addDays(14));          // day after the trial: grace starts
        $this->assertSame('grace', $s->effectiveStatus());
        $this->assertSame('warn', $s->accessState());

        Carbon::setTestNow(today()->addDays(14 + 7 - 1));  // last grace day (trial ended d13, grace_days 7)
        Carbon::setTestNow(Carbon::parse($s->trial_ends_on)->addDays(7));
        $this->assertSame('grace', $s->effectiveStatus());

        Carbon::setTestNow(Carbon::parse($s->trial_ends_on)->addDays(8));
        $this->assertSame('expired', $s->effectiveStatus());
        $this->assertSame('restricted', $s->accessState());
    }

    public function test_pending_suspended_and_cancelled_are_restricted_regardless_of_dates(): void
    {
        $s = $this->subscribe();
        $this->assertSame('restricted', $s->accessState(), 'pending until the first payment is approved');

        $s->forceFill(['status' => 'suspended', 'expires_on' => today()->addYear()])->save();
        $this->assertSame('restricted', $s->fresh()->accessState());

        $s->forceFill(['status' => 'cancelled'])->save();
        $this->assertSame('restricted', $s->fresh()->accessState());
    }

    public function test_no_subscription_means_unrestricted(): void
    {
        $this->assertSame('none', Entitlements::state());
        Entitlements::assertCanAdd('students', 9999);   // no limit when there is no plan
        $this->assertTrue(Entitlements::hasModule('attendance'));
    }

    // ===================== limits & modules =====================

    public function test_plan_limits_block_additions(): void
    {
        $s = $this->subscribe(trial: true);

        foreach (range(1, 5) as $i) {
            Student::create(['name' => "S$i", 'phone' => "900000000$i"]);
        }
        try {
            Entitlements::assertCanAdd('students');
            $this->fail('6th student allowed');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('up to 5 students', $e->getMessage());
        }

        // inactive students do not count against the plan
        Student::first()->update(['status' => 'inactive']);
        Entitlements::assertCanAdd('students');

        $hall = StudyHall::create(['name' => 'H']);
        foreach (range(1, 4) as $i) {
            Seat::create(['study_hall_id' => $hall->id, 'code' => "A-$i"]);
        }
        $this->expectException(BusinessRuleException::class);
        Entitlements::assertCanAdd('seats');
    }

    public function test_limits_are_enforced_by_the_pages(): void
    {
        $this->subscribe(trial: true);
        $this->actingAs($this->owner);
        foreach (range(1, 5) as $i) {
            Student::create(['name' => "S$i", 'phone' => "900000000$i"]);
        }

        Livewire::test('pages::app.students.form')->set('name', 'One too many')->set('phone', '9111111111')->set('registered_on', today()->toDateString())
            ->call('save')->assertHasErrors('name');
        $this->assertSame(5, Student::count());

        $hall = StudyHall::create(['name' => 'H']);
        Livewire::test('pages::app.seats.index')->set('g_hall', (string) $hall->id)->set('g_prefix', 'A-')->set('g_count', '10')->call('generate')->assertHasErrors('g_count');
        $this->assertSame(0, Seat::count());

        Livewire::test('pages::app.seats.index')->set('g_hall', (string) $hall->id)->set('g_prefix', 'A-')->set('g_count', '4')->call('generate')->assertHasNoErrors();
        $this->assertSame(4, Seat::count());
    }

    public function test_staff_limit_is_enforced(): void
    {
        $this->subscribe(trial: true);          // max 2 staff accounts; the owner is the first
        $this->actingAs($this->owner);

        Livewire::test('pages::app.staff.index')->call('create')->set('name', 'B')->set('email', 'b@x.test')->set('selectedRoles', [LibraryRoles::MANAGER])->call('save')->assertHasNoErrors();
        Livewire::test('pages::app.staff.index')->call('create')->set('name', 'C')->set('email', 'c@x.test')->set('selectedRoles', [LibraryRoles::MANAGER])->call('save')->assertHasErrors('email');

        $this->assertNull(User::where('email', 'c@x.test')->first());
    }

    public function test_disabled_modules_are_blocked_and_hidden(): void
    {
        $this->subscribe(trial: true);        // modules: attendance + reports, NOT expenses
        $slug = $this->library->slug;
        $this->actingAs($this->owner);

        $this->get("/app/{$slug}/attendance")->assertOk();
        $this->get("/app/{$slug}/expenses")->assertForbidden();
        $this->get("/app/{$slug}/dashboard-does-not-exist")->assertNotFound();
        $this->get("/app/{$slug}")->assertOk()->assertDontSee('>Expenses<', false);
    }

    // ===================== payment submission =====================

    public function test_a_library_owner_submits_a_payment_and_nothing_else_changes(): void
    {
        $s = $this->subscribe();
        $p = $this->submit($s, ['reference' => 'UTR12345']);

        $this->assertSame('pending', $p->status);
        $this->assertSame('1000.00', $p->amount);
        $this->assertSame(1, $p->proofs()->count());
        Storage::disk('local')->assertExists($p->proofs->first()->path);
        $this->assertStringStartsWith('subscription-proofs/'.$this->library->id, $p->proofs->first()->path);
        $this->assertSame('pending', $s->fresh()->status, 'only an approval changes the subscription');
        $this->assertNotNull(AuditLog::where('action', 'subscription.payment_submitted')->first());
    }

    public function test_submission_validation_rules(): void
    {
        $s = $this->subscribe();
        $bad = [
            'wrong amount' => ['amount' => 999],
            'method not accepted' => ['method' => 'cash'],
            'no reference' => ['reference' => '  '],
            'future date' => ['paidOn' => today()->addDay()],
            'no proof' => ['files' => []],
            'unsold cycle' => ['cycle' => 'weekly'],
        ];

        foreach ($bad as $why => $override) {
            try {
                $this->submit($s, $override);
                $this->fail("accepted: $why");
            } catch (BusinessRuleException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, SubscriptionPayment::count());
    }

    public function test_settings_control_exact_amount_and_proof(): void
    {
        $s = $this->subscribe();
        BillingSettings::set(['require_exact_amount' => false, 'proof_required' => false, 'payment_methods' => ['cash']]);

        $p = $this->submit($s, ['amount' => 500, 'method' => 'cash', 'files' => []]);

        $this->assertSame('500.00', $p->amount);
    }

    public function test_only_one_open_payment_and_no_reused_reference(): void
    {
        $s = $this->subscribe();
        $first = $this->submit($s, ['reference' => 'UTR-A']);

        try {
            $this->submit($s, ['reference' => 'UTR-B']);
            $this->fail('second open payment accepted');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already have a payment', $e->getMessage());
        }

        $this->actingAs($this->admin);
        app(ReviewSubscriptionPayment::class)->reject($first, 'Blurry proof');

        try {
            $this->submit($s, ['reference' => 'UTR-A']);   // rejected references may be reused
            $this->assertTrue(true);
        } catch (BusinessRuleException) {
            $this->fail('a rejected reference should be reusable');
        }
    }

    public function test_only_people_with_billing_manage_can_submit(): void
    {
        $s = $this->subscribe();
        $this->actingAs($this->libraryUser(LibraryRoles::MANAGER));   // no billing.manage

        $this->expectException(HttpException::class);
        app(SubmitSubscriptionPayment::class)($this->library, $this->plan, 'monthly', 'REF', 1000, 'upi', today(), null, [UploadedFile::fake()->image('p.png')]);
    }

    // ===================== approval =====================

    public function test_approval_activates_extends_invoices_and_notifies(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $s = $this->subscribe();
        $p = $this->submit($s);

        $p = $this->approve($p, 'Checked bank statement');

        $s = $s->fresh();
        $this->assertSame('active', $s->status);
        $this->assertTrue($s->expires_on->isSameDay(today()->addMonth()->subDay()));
        $this->assertTrue($s->starts_on->isSameDay(today()));
        $this->assertSame('approved', $p->status);
        $this->assertSame($this->admin->id, $p->reviewed_by);

        $invoice = $p->fresh()->invoice;
        $this->assertSame('INV-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT), $invoice->invoice_no);
        $this->assertSame('1000.00', $invoice->amount);

        $this->assertNotNull(AuditLog::where('action', 'subscription.payment_approved')->first());
        $this->assertNotNull(SubscriptionEvent::where('subscription_id', $s->id)->where('type', 'payment_approved')->first());
        \Illuminate\Support\Facades\Notification::assertSentTo($this->owner, InAppNotification::class, fn ($n) => $n->kind === 'subscription.payment_approved');
    }

    public function test_a_payment_can_only_be_approved_once(): void
    {
        $s = $this->subscribe();
        $p = $this->submit($s);
        $this->approve($p);
        $expires = $s->fresh()->expires_on;

        try {
            $this->approve($p->fresh());
            $this->fail('approved twice');
        } catch (BusinessRuleException) {
            $this->assertTrue(true);
        }

        $this->assertTrue($s->fresh()->expires_on->isSameDay($expires), 'second approval must not extend again');
        $this->assertSame(1, \App\Models\SubscriptionInvoice::count());
    }

    public function test_renewal_stacks_on_the_current_period_and_expired_restarts_today(): void
    {
        $s = $this->subscribe();
        $this->approve($this->submit($s));                       // paid until +1 month
        $first = $s->fresh()->expires_on->copy();

        $this->approve($this->submit($s, ['reference' => 'RENEW1']));   // early renewal continues from the old end
        $this->assertTrue($s->fresh()->expires_on->isSameDay($first->copy()->addMonthNoOverflow()), 'renewal continues the period');

        // let it lapse long ago, then pay again: the new period starts today, not in the past
        $s->forceFill(['expires_on' => today()->subMonths(3)])->save();
        $this->approve($this->submit($s, ['reference' => 'RENEW2']));
        $this->assertTrue($s->fresh()->expires_on->isSameDay(today()->addMonth()->subDay()));
    }

    public function test_quarterly_and_annual_periods(): void
    {
        $s = $this->subscribe(cycle: 'quarterly');
        $this->approve($this->submit($s, ['cycle' => 'quarterly', 'amount' => 2700, 'reference' => 'Q1']));
        $this->assertTrue($s->fresh()->expires_on->isSameDay(today()->addMonthsNoOverflow(3)->subDay()));
    }

    public function test_approval_needs_the_platform_permission(): void
    {
        $s = $this->subscribe();
        $p = $this->submit($s);

        foreach ([$this->owner, $this->platformAdmin(['plans.manage', 'audit.view']), $this->libraryUser(LibraryRoles::MANAGER)] as $who) {
            $this->actingAs($who);
            try {
                app(ApproveSubscriptionPayment::class)($p);
                $this->fail('approval allowed without payments.approve');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame('pending', $p->fresh()->status);

        $this->actingAs($this->platformAdmin(['payments.approve']));
        $this->assertSame('approved', app(ApproveSubscriptionPayment::class)($p)->status);
    }

    public function test_approval_does_not_reactivate_a_suspended_subscription(): void
    {
        $s = $this->subscribe(trial: true);
        $p = $this->submit($s);
        $this->actingAs($this->admin);
        app(ManageSubscription::class)->suspend($s, 'Chargeback dispute');

        $this->approve($p);

        $this->assertSame('suspended', $s->fresh()->status, 'payment and subscription status stay separate');
        $this->assertSame('approved', $p->fresh()->status);
    }

    public function test_plan_edits_take_effect_at_renewal_not_before(): void
    {
        $s = $this->subscribe(trial: true);
        $this->assertSame(5, $s->max_students);

        $this->plan->update(['max_students' => 50, 'price_monthly' => 1200]);
        $this->assertSame(5, $s->fresh()->max_students, 'editing a plan never silently changes a subscription');
        $this->assertSame('1000.00', (string) $s->fresh()->price);

        $this->approve($this->submit($s, ['amount' => 1200]));   // renewal at the plan's new price

        $this->assertSame(50, $s->fresh()->max_students);
        $this->assertSame('1200.00', (string) $s->fresh()->price);
    }

    public function test_next_plan_applies_at_renewal(): void
    {
        $premium = SubscriptionPlan::create(['name' => 'Premium', 'price_monthly' => 3000, 'max_students' => null, 'modules' => ['attendance', 'reports', 'expenses', 'exports']]);
        $s = $this->subscribe(trial: true);
        $this->actingAs($this->admin);

        app(ManageSubscription::class)->changePlan($s, $premium, 'monthly', 'renewal', 'Agreed upgrade next cycle');
        $this->assertSame('Standard', $s->fresh()->plan_name);
        $this->assertSame($premium->id, $s->fresh()->next_plan_id);

        $this->approve($this->submit($s, ['plan' => $premium, 'amount' => 3000]));

        $this->assertSame('Premium', $s->fresh()->plan_name);
        $this->assertNull($s->fresh()->next_plan_id);
        $this->assertNull($s->fresh()->max_students);
    }

    // ===================== reject / ask for information =====================

    public function test_rejection_needs_a_reason_and_never_activates(): void
    {
        $s = $this->subscribe();
        $p = $this->submit($s);
        $this->actingAs($this->admin);

        try {
            app(ReviewSubscriptionPayment::class)->reject($p, ' ');
            $this->fail('rejected without a reason');
        } catch (BusinessRuleException) {
        }

        app(ReviewSubscriptionPayment::class)->reject($p, 'Amount not received');
        $this->assertSame('rejected', $p->fresh()->status);
        $this->assertSame('pending', $s->fresh()->status);

        // a rejected payment cannot be approved afterwards
        $this->expectException(BusinessRuleException::class);
        app(ApproveSubscriptionPayment::class)($p->fresh());
    }

    public function test_ask_for_information_then_library_replies_then_approve(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $s = $this->subscribe();
        $p = $this->submit($s);

        $this->actingAs($this->admin);
        app(ReviewSubscriptionPayment::class)->requestInfo($p, 'Which account did you pay from?');
        $this->assertSame('info_requested', $p->fresh()->status);

        // cannot approve while we are waiting for the answer
        try {
            app(ApproveSubscriptionPayment::class)($p->fresh());
            $this->fail('approved while waiting for information');
        } catch (BusinessRuleException) {
        }

        $this->actingAs($this->owner);
        app(RespondToPaymentInfoRequest::class)($p->fresh(), 'From HDFC account ending 4521', [UploadedFile::fake()->image('statement.png')]);
        $this->assertSame('pending', $p->fresh()->status);
        $this->assertSame(2, $p->fresh()->proofs()->count());
        $this->assertStringContainsString('HDFC', $p->fresh()->note);

        $this->assertSame('approved', $this->approve($p->fresh())->status);
        \Illuminate\Support\Facades\Notification::assertSentTo($this->owner, InAppNotification::class, fn ($n) => $n->kind === 'subscription.payment_info_requested');
    }

    // ===================== manual management =====================

    public function test_manual_changes_need_reasons_follow_the_rules_and_record_history(): void
    {
        $s = $this->subscribe(trial: true);
        $this->actingAs($this->admin);
        $manage = app(ManageSubscription::class);

        try {
            $manage->suspend($s, '');
            $this->fail('suspended without a reason');
        } catch (BusinessRuleException) {
        }

        $manage->suspend($s, 'Unpaid invoice');
        $e = SubscriptionEvent::where('subscription_id', $s->id)->where('type', 'suspended')->first();
        $this->assertSame('trial', $e->from_status);
        $this->assertSame('suspended', $e->to_status);
        $this->assertSame('Unpaid invoice', $e->reason);
        $this->assertSame($this->admin->id, $e->user_id);
        $this->assertNotNull($e->created_at);

        $manage->reactivate($s->fresh(), 'Paid by cheque');
        $this->assertSame('trial', $s->fresh()->status, 'back to what the dates say');

        // invalid transitions
        try {
            $manage->reactivate($s->fresh(), 'not suspended');
            $this->fail('reactivated a non-suspended subscription');
        } catch (BusinessRuleException) {
        }

        $manage->cancel($s->fresh(), 'Library closed');
        $this->assertSame('cancelled', $s->fresh()->status);
        $this->assertNotNull($s->fresh()->cancelled_at);

        $this->expectException(BusinessRuleException::class);
        $manage->extend($s->fresh(), today()->addYear(), 'cannot extend a cancelled one');
    }

    public function test_extend_and_manual_activation(): void
    {
        $s = $this->subscribe(trial: true);
        $this->actingAs($this->admin);
        $manage = app(ManageSubscription::class);

        try {
            $manage->extend($s, $s->trial_ends_on->copy()->subDay(), 'earlier date');
            $this->fail('extended to an earlier date');
        } catch (BusinessRuleException) {
        }

        $manage->extend($s, today()->addDays(30), 'Goodwill extension');
        $this->assertTrue($s->fresh()->trial_ends_on->isSameDay(today()->addDays(30)));
        $e = SubscriptionEvent::where('type', 'extended')->first();
        $this->assertSame($s->trial_ends_on->toDateString(), $e->data['previous_expiry']);

        $manage->activate($s->fresh(), today()->addMonths(2), 'Annual deal paid offline');
        $this->assertSame('active', $s->fresh()->status);
        $this->assertTrue($s->fresh()->expires_on->isSameDay(today()->addMonths(2)));
    }

    public function test_change_plan_now_guards_against_downgrading_below_usage(): void
    {
        $s = $this->subscribe(trial: true);
        foreach (range(1, 5) as $i) {
            Student::create(['name' => "S$i", 'phone' => "900000000$i"]);
        }
        $tiny = SubscriptionPlan::create(['name' => 'Tiny', 'price_monthly' => 100, 'max_students' => 2, 'max_seats' => 1, 'max_staff' => 1]);
        $big = SubscriptionPlan::create(['name' => 'Big', 'price_monthly' => 5000, 'max_students' => 500]);

        $this->actingAs($this->admin);
        try {
            app(ManageSubscription::class)->changePlan($s, $tiny, 'monthly', 'now', 'Downgrade');
            $this->fail('downgrade below current usage allowed');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('5 students', $e->getMessage());
        }

        app(ManageSubscription::class)->changePlan($s, $big, 'monthly', 'now', 'Upgrade');
        $this->assertSame(500, $s->fresh()->max_students);
        $this->assertSame('Big', $s->fresh()->plan_name);
    }

    public function test_subscription_history_cannot_be_edited_or_deleted(): void
    {
        $s = $this->subscribe();
        $event = SubscriptionEvent::where('subscription_id', $s->id)->first();

        foreach ([fn () => $event->update(['reason' => 'tampered']), fn () => $event->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('history was modified');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    // ===================== enforcement over HTTP =====================

    public function test_restricted_library_can_only_use_the_billing_page(): void
    {
        $s = $this->subscribe();                     // pending = restricted
        $this->actingAs($this->owner);
        $slug = $this->library->slug;

        $this->get("/app/{$slug}/students")->assertRedirect("/app/{$slug}/subscription");
        $this->get("/app/{$slug}")->assertRedirect("/app/{$slug}/subscription");
        $this->get("/app/{$slug}/subscription")->assertOk()->assertSee('Your library is paused');

        // data is untouched
        $this->assertDatabaseHas('libraries', ['id' => $this->library->id]);
    }

    public function test_after_approval_the_library_works_again(): void
    {
        $s = $this->subscribe();
        $this->approve($this->submit($s));

        $this->actingAs($this->owner);
        $this->get("/app/{$this->library->slug}/students")->assertOk();
    }

    public function test_grace_period_keeps_access_with_a_warning_then_restricts(): void
    {
        $s = $this->subscribe();
        $s->forceFill(['status' => 'active', 'starts_on' => today()->subMonth(), 'expires_on' => today()->subDays(2)])->save();
        $this->actingAs($this->owner);
        $slug = $this->library->slug;

        $this->get("/app/{$slug}/students")->assertOk();
        $this->get("/app/{$slug}")->assertOk()->assertSee('Your subscription has ended');

        $s->forceFill(['expires_on' => today()->subDays(30)])->save();
        Entitlements::flush();
        $this->get("/app/{$slug}/students")->assertRedirect("/app/{$slug}/subscription");
    }

    public function test_livewire_updates_are_refused_while_restricted(): void
    {
        $s = $this->subscribe();
        $this->actingAs($this->owner);

        $this->withHeader('X-Livewire', 'true')->get("/app/{$this->library->slug}/students")->assertForbidden();
    }

    // ===================== pages & permissions =====================

    public function test_platform_billing_pages_render_with_data(): void
    {
        $s = $this->subscribe(trial: true);
        $p = $this->submit($s, ['reference' => 'UTRPAGE']);
        $this->actingAs($this->admin);

        $this->get('/admin')->assertOk()->assertSee('Pending payment approvals');
        $this->get('/admin/plans')->assertOk()->assertSee('Standard');
        $this->get('/admin/subscriptions')->assertOk()->assertSee('Alpha');
        $this->get("/admin/subscriptions/{$s->id}")->assertOk()->assertSee('Plan');
        $this->get('/admin/payment-approvals')->assertOk()->assertSee('UTRPAGE');
        $this->get("/admin/libraries/{$this->library->id}?tab=subscription")->assertOk()->assertSee('Standard');
        $this->get("/admin/libraries/{$this->library->id}?tab=payments")->assertOk()->assertSee('UTRPAGE');
        $this->get('/admin/libraries/create')->assertOk()->assertSee('Initial plan');
    }

    public function test_platform_permissions_gate_each_area(): void
    {
        $this->subscribe();
        $paymentsOnly = $this->platformAdmin(['payments.approve']);
        $this->actingAs($paymentsOnly);

        $this->get('/admin')->assertOk();                          // every platform admin sees the dashboard
        $this->get('/admin/payment-approvals')->assertOk();
        $this->get('/admin/plans')->assertForbidden();
        $this->get('/admin/subscriptions')->assertForbidden();
        $this->get('/admin/libraries')->assertForbidden();
        $this->get('/admin/audit-logs')->assertForbidden();

        $this->actingAs($this->libraryUser(LibraryRoles::OWNER));
        foreach (['/admin/plans', '/admin/subscriptions', '/admin/payment-approvals'] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_plan_management_through_the_page(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::admin.plans.index')->call('create')->call('save')->assertHasErrors(['name']);
        Livewire::test('pages::admin.plans.index')->call('create')->set('name', 'NoPrice')->call('save')->assertHasErrors('price_monthly');

        Livewire::test('pages::admin.plans.index')->call('create')
            ->set('name', 'Gold')->set('price_monthly', '1500')->set('price_annual', '15000')->set('max_students', '300')->set('modules', ['reports'])
            ->call('save')->assertHasNoErrors();

        $gold = SubscriptionPlan::where('name', 'Gold')->first();
        $this->assertSame(300, $gold->max_students);
        $this->assertNull($gold->max_seats, 'empty means unlimited');
        $this->assertSame(['reports'], $gold->modules);

        Livewire::test('pages::admin.plans.index')->call('duplicate', $gold->id);
        $copy = SubscriptionPlan::where('name', 'Gold (copy)')->first();
        $this->assertNotNull($copy);
        $this->assertSame('inactive', $copy->status);

        Livewire::test('pages::admin.plans.index')->call('toggle', $copy->id);
        $this->assertSame('active', $copy->fresh()->status);

        $this->actingAs($this->platformAdmin(['audit.view']));
        Livewire::test('pages::admin.plans.index')->assertForbidden();
    }

    public function test_registration_can_assign_a_plan_and_trial(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages::admin.libraries.create')
            ->set('name', 'Gamma Hub')->set('owner_name', 'Gina')->set('owner_email', 'gina@gamma.test')->set('owner_phone', '9000011111')
            ->set('plan_id', (string) $this->plan->id)->assertSet('trial', true)->call('save')->assertHasNoErrors();

        $gamma = Library::where('name', 'Gamma Hub')->first();
        $sub = Subscription::currentFor($gamma->id);
        $this->assertSame('trial', $sub->status);
        $this->assertSame('Standard', $sub->plan_name);
    }

    public function test_payment_approval_page_flow(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $s = $this->subscribe(trial: true);
        $p = $this->submit($s, ['reference' => 'FLOW1']);
        $this->actingAs($this->admin);

        $page = Livewire::test('pages::admin.payment-approvals.index')->call('review', $p->id);
        $page->call('decide')->assertHasErrors('note');                           // no decision chosen
        $page->set('decision', 'reject')->set('note', '')->call('decide')->assertHasErrors('note');
        $page->set('decision', 'approve')->call('decide')->assertHasNoErrors();

        $this->assertSame('approved', $p->fresh()->status);
        $this->assertSame('active', $s->fresh()->status);
    }

    public function test_plan_picker_preselects_plan_and_cycle(): void
    {
        $this->subscribe(trial: true);
        $this->actingAs($this->owner);
        $plan = SubscriptionPlan::where('status', 'active')->whereNotNull('price_quarterly')->first();

        $this->get("/app/{$this->library->slug}/subscription/plans")->assertOk()->assertSee('Choose Plan');
        Livewire::withQueryParams(['plan' => $plan->id, 'cycle' => 'quarterly'])->test('pages::app.subscription.index')
            ->assertSet('showPay', true)->assertSet('planId', (string) $plan->id)->assertSet('cycle', 'quarterly');
    }

    public function test_library_subscription_page_and_submission_flow(): void
    {
        $s = $this->subscribe(trial: true);
        $this->actingAs($this->owner);

        $this->get("/app/{$this->library->slug}/subscription")->assertOk()->assertSee('Standard')->assertSee('Submit a payment');

        $page = Livewire::test('pages::app.subscription.index')->call('openPay')->assertSet('amount', '1000.00')
            ->set('reference', 'PAGEREF')->set('method', 'upi')->set('paidOn', today()->toDateString())
            ->set('proofs', [UploadedFile::fake()->image('p.png')])->call('savePay')->assertHasNoErrors();
        $this->assertSame(1, SubscriptionPayment::where('reference', 'PAGEREF')->count());

        // a bad file type is rejected
        Livewire::test('pages::app.subscription.index')->call('openPay')->set('reference', 'BADFILE')
            ->set('proofs', [UploadedFile::fake()->create('virus.exe', 20)])->call('savePay')->assertHasErrors('proofs.0');

        // people without billing.manage cannot open the form
        $this->actingAs($this->libraryUser(LibraryRoles::MANAGER));
        Livewire::test('pages::app.subscription.index')->assertForbidden();   // no billing.view either
    }

    public function test_proofs_and_invoices_are_private_and_tenant_scoped(): void
    {
        $s = $this->subscribe();
        $p = $this->submit($s);
        $proof = $p->proofs->first();
        $this->approve($p);
        $invoice = $p->fresh()->invoice;
        $slug = $this->library->slug;

        // own library
        $this->actingAs($this->owner);
        $this->get("/app/{$slug}/subscription/proofs/{$proof->id}")->assertOk();
        $this->get("/app/{$slug}/subscription/invoices/{$invoice->id}")->assertOk()->assertSee($invoice->invoice_no);

        // platform staff with the right permission
        $this->actingAs($this->platformAdmin(['payments.approve']));
        $this->get("/admin/subscription-proofs/{$proof->id}")->assertOk();
        $this->get("/admin/subscription-invoices/{$invoice->id}")->assertOk();

        // platform staff without payment/subscription permissions
        $this->actingAs($this->platformAdmin(['audit.view']));
        $this->get("/admin/subscription-proofs/{$proof->id}")->assertForbidden();
        $this->get("/admin/subscription-invoices/{$invoice->id}")->assertForbidden();

        // another library cannot see them even by guessing ids
        $this->actingAs($this->admin);
        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        $this->actingAs(User::where('email', 'b@beta.test')->first());
        $this->get("/app/{$beta->slug}/subscription/proofs/{$proof->id}")->assertNotFound();
        $this->get("/app/{$beta->slug}/subscription/invoices/{$invoice->id}")->assertNotFound();

        // a library member without billing.view
        $this->actingAs($this->libraryUser(LibraryRoles::RECEPTIONIST));
        $this->get("/app/{$slug}/subscription/proofs/{$proof->id}")->assertForbidden();

        // the file itself is on the private disk, never web-accessible
        $this->assertFalse(Storage::disk('public')->exists($proof->path));
    }

    public function test_revenue_counts_only_approved_payments(): void
    {
        $s = $this->subscribe();
        $this->submit($s, ['reference' => 'R1']);                       // pending: not revenue
        $this->actingAs($this->admin);

        $dash = Livewire::test('pages::admin.dashboard');
        $this->assertEquals(0, $dash->get('revenue'));

        $this->approve(SubscriptionPayment::first());
        $this->assertEquals(1000, Livewire::test('pages::admin.dashboard')->get('revenue'));
        $this->assertSame(0, Livewire::test('pages::admin.dashboard')->get('pendingApprovals'));
    }

    public function test_owner_role_gained_the_billing_permissions(): void
    {
        $this->assertTrue($this->owner->fresh()->can('billing.manage'));
        $this->assertFalse($this->libraryUser(LibraryRoles::MANAGER)->can('billing.manage'));
        $this->assertFalse($this->libraryUser(LibraryRoles::ACCOUNTANT)->can('billing.view'));
    }
}
