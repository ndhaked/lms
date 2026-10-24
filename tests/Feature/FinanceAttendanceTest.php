<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\CheckInStudent;
use App\Actions\CheckOutStudent;
use App\Actions\CorrectAttendance;
use App\Actions\RecordPayment;
use App\Actions\RecordRefund;
use App\Actions\RegisterLibrary;
use App\Actions\ReversePayment;
use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Models\User;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FinanceAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;

    protected function setUp(): void
    {
        parent::setUp();

        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'owner@alpha.test']);
        $this->actingAs(User::where('email', 'owner@alpha.test')->first());
        Tenancy::set($this->library);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function plan(float $price = 1000, float $fee = 0, string $unit = 'monthly'): MembershipPlan
    {
        return MembershipPlan::create(['name' => 'Plan '.uniqid(), 'duration_unit' => $unit, 'price' => $price, 'registration_fee' => $fee]);
    }

    protected function student(): Student
    {
        return Student::create(['name' => 'S '.uniqid(), 'phone' => (string) random_int(7000000000, 9999999999)]);
    }

    /** A student with one running membership costing $price. */
    protected function enrolled(float $price = 1000, float $fee = 0): array
    {
        $student = $this->student();
        $membership = app(AssignMembership::class)($student, $this->plan($price, $fee), today());

        return [$student, $membership];
    }

    protected function pay(Student $s, float $amount, ?StudentMembership $m = null, string $method = 'cash', ?string $key = null): Payment
    {
        return app(RecordPayment::class)($s, $amount, $method, today(), $m, null, null, $key);
    }

    protected function userWithRole(string $role): User
    {
        $u = User::factory()->create();
        $this->library->users()->attach($u->id, ['is_active' => true]);
        Tenancy::run($this->library, fn () => $u->assignRole($role));

        return $u->fresh();
    }

    // ---------------- payments ----------------

    public function test_creating_a_membership_leaves_the_full_amount_outstanding(): void
    {
        [, $m] = $this->enrolled(1000, 100);

        $this->assertSame('1100.00', $m->totalDue());
        $this->assertSame('0.00', $m->paidAmount());
        $this->assertSame('1100.00', $m->outstanding());
    }

    public function test_partial_payments_reduce_the_balance(): void
    {
        [$s, $m] = $this->enrolled(1000);

        $this->pay($s, 400, $m);
        $this->assertSame('600.00', $m->fresh()->outstanding());

        $this->pay($s, 250.50, $m);
        $this->assertSame('349.50', $m->fresh()->outstanding());
        $this->assertSame('650.50', $m->fresh()->paidAmount());

        $this->pay($s, 349.50, $m);
        $this->assertSame('0.00', $m->fresh()->outstanding());
    }

    public function test_overpayment_is_refused_and_nothing_is_recorded(): void
    {
        [$s, $m] = $this->enrolled(1000);
        $this->pay($s, 900, $m);

        try {
            $this->pay($s, 200, $m);
            $this->fail('overpayment accepted');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('exceeds', $e->getMessage());
        }
        $this->assertSame(1, Payment::count());
        $this->assertSame('100.00', $m->fresh()->outstanding());
    }

    public function test_invalid_amounts_methods_and_dates_are_refused(): void
    {
        [$s, $m] = $this->enrolled(1000);

        foreach ([[0, 'cash'], [-5, 'cash'], [100, 'bitcoin']] as [$amount, $method]) {
            try {
                app(RecordPayment::class)($s, $amount, $method, today(), $m);
                $this->fail("accepted $amount/$method");
            } catch (BusinessRuleException) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(BusinessRuleException::class);
        app(RecordPayment::class)($s, 100, 'cash', today()->addDay(), $m);
    }

    public function test_payment_without_a_membership_clears_the_oldest_first(): void
    {
        $s = $this->student();
        $first = app(AssignMembership::class)($s, $this->plan(1000), today()->subMonths(2));
        $second = app(AssignMembership::class)($s, $this->plan(500), today());

        $payment = $this->pay($s, 1200);

        $this->assertSame('0.00', $first->fresh()->outstanding());
        $this->assertSame('300.00', $second->fresh()->outstanding());
        $this->assertSame(2, $payment->allocations()->count());
    }

    public function test_a_payment_cannot_be_applied_to_another_students_membership(): void
    {
        [, $m] = $this->enrolled(1000);
        $other = $this->student();

        $this->expectException(BusinessRuleException::class);
        $this->pay($other, 100, $m);
    }

    public function test_duplicate_submission_with_the_same_key_records_one_payment(): void
    {
        [$s, $m] = $this->enrolled(1000);

        $a = $this->pay($s, 300, $m, 'cash', 'form-token-1');
        $b = $this->pay($s, 300, $m, 'cash', 'form-token-1');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Payment::count());
        $this->assertSame('700.00', $m->fresh()->outstanding());
    }

    public function test_receipt_numbers_are_sequential_per_library(): void
    {
        [$s, $m] = $this->enrolled(1000);
        $this->assertSame('RC-000001', $this->pay($s, 100, $m)->receipt_no);
        $this->assertSame('RC-000002', $this->pay($s, 100, $m)->receipt_no);

        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        Tenancy::set($beta);
        $bs = $this->student();
        $bm = app(AssignMembership::class)($bs, $this->plan(500), today());
        $this->assertSame('RC-000001', $this->pay($bs, 100, $bm)->receipt_no, 'each library has its own sequence');
    }

    public function test_reversal_keeps_the_record_and_restores_the_balance(): void
    {
        [$s, $m] = $this->enrolled(1000);
        $p = $this->pay($s, 1000, $m);
        $this->assertSame('0.00', $m->fresh()->outstanding());

        app(ReversePayment::class)($p, 'Entered against the wrong student');

        $this->assertDatabaseHas('payments', ['id' => $p->id, 'status' => 'reversed', 'reversal_reason' => 'Entered against the wrong student']);
        $this->assertSame('1000.00', $m->fresh()->outstanding());
        $this->assertNotNull($p->fresh()->reversed_at);
        $this->assertNotNull(AuditLog::where('action', 'payment.reversed')->first());

        // not twice, and a reason is mandatory
        try {
            app(ReversePayment::class)($p->fresh(), 'again');
            $this->fail('double reversal');
        } catch (BusinessRuleException) {
            $this->assertTrue(true);
        }
    }

    public function test_reversal_needs_a_reason_and_is_blocked_after_a_refund(): void
    {
        [$s, $m] = $this->enrolled(1000);
        $p = $this->pay($s, 1000, $m);

        try {
            app(ReversePayment::class)($p, '  ');
            $this->fail('reason required');
        } catch (BusinessRuleException) {
        }

        app(RecordRefund::class)($p, $m, 100, 'cash', 'Goodwill');

        $this->expectException(BusinessRuleException::class);
        app(ReversePayment::class)($p->fresh(), 'oops');
    }

    public function test_refunds_preserve_history_and_respect_limits(): void
    {
        [$s, $m] = $this->enrolled(1000);
        $p = $this->pay($s, 800, $m);

        $refund = app(RecordRefund::class)($p, $m, 300, 'cash', 'Student left early');

        $this->assertSame('RF-000001', $refund->refund_no);
        $this->assertSame('800.00', Payment::find($p->id)->amount, 'the original payment is untouched');
        $this->assertSame('500.00', $m->fresh()->paidAmount());
        $this->assertSame('500.00', $m->fresh()->outstanding());

        try {
            app(RecordRefund::class)($p->fresh(), $m, 600, 'cash', 'too much'); // only 500 left
            $this->fail('over-refund accepted');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('500.00', $e->getMessage());
        }

        app(RecordRefund::class)($p->fresh(), $m, 500, 'cash', 'Rest');
        $this->assertSame(2, Refund::count());
        $this->assertSame('0.00', $m->fresh()->paidAmount());
    }

    public function test_refund_and_reversal_need_the_refund_permission(): void
    {
        [$s, $m] = $this->enrolled(1000);
        $p = $this->pay($s, 500, $m);

        $this->actingAs($this->userWithRole(LibraryRoles::RECEPTIONIST)); // may record, not refund

        foreach ([fn () => app(RecordRefund::class)($p, $m, 50, 'cash', 'x'), fn () => app(ReversePayment::class)($p, 'x')] as $attempt) {
            try {
                $attempt();
                $this->fail('receptionist must not refund or reverse');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame(0, Refund::count());
    }

    public function test_recording_a_payment_needs_the_record_permission(): void
    {
        [$s, $m] = $this->enrolled(1000);
        $this->actingAs($this->userWithRole(LibraryRoles::SUPERVISOR));

        $this->expectException(HttpException::class);
        $this->pay($s, 100, $m);
    }

    public function test_payments_are_isolated_between_libraries(): void
    {
        [$s, $m] = $this->enrolled(1000);
        $p = $this->pay($s, 100, $m);

        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        Tenancy::set($beta);

        $this->assertSame(0, Payment::count());
        $this->assertNull(Payment::find($p->id));
    }

    public function test_owing_scope_lists_only_memberships_with_a_balance(): void
    {
        [$s1, $m1] = $this->enrolled(1000);
        [$s2, $m2] = $this->enrolled(500);
        $this->pay($s2, 500, $m2);
        [$s3, $m3] = $this->enrolled(700);
        $this->pay($s3, 100, $m3);

        $owing = StudentMembership::owing()->withBalance()->get()->keyBy('id');

        $this->assertEqualsCanonicalizing([$m1->id, $m3->id], $owing->keys()->all());
        $this->assertEquals(600, $owing[$m3->id]->balance);
        $this->assertEquals(100, $owing[$m3->id]->paid);
    }

    // ---------------- attendance ----------------

    public function test_check_in_and_out(): void
    {
        [$s] = $this->enrolled();

        $a = app(CheckInStudent::class)($s);
        $this->assertTrue($a->isOpen());

        $a = app(CheckOutStudent::class)($a);
        $this->assertFalse($a->isOpen());
        $this->assertNotNull($a->checked_out_by);

        // and may check in again afterwards
        $this->assertTrue(app(CheckInStudent::class)($s)->isOpen());
    }

    public function test_duplicate_active_check_in_is_blocked(): void
    {
        [$s] = $this->enrolled();
        app(CheckInStudent::class)($s);

        try {
            app(CheckInStudent::class)($s);
            $this->fail('second check-in accepted');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already checked in', $e->getMessage());
        }
        $this->assertSame(1, Attendance::count());
    }

    public function test_database_blocks_a_second_open_visit_even_if_checks_are_bypassed(): void
    {
        [$s] = $this->enrolled();
        app(CheckInStudent::class)($s);

        $this->expectException(UniqueConstraintViolationException::class);
        Attendance::create(['student_id' => $s->id, 'checked_in_at' => now(), 'active_student_id' => $s->id]);
    }

    public function test_student_without_membership_or_inactive_cannot_check_in(): void
    {
        $bare = $this->student();
        try {
            app(CheckInStudent::class)($bare);
            $this->fail('no membership');
        } catch (BusinessRuleException) {
        }

        [$s] = $this->enrolled();
        $s->update(['status' => Student::SUSPENDED]);
        $this->expectException(BusinessRuleException::class);
        app(CheckInStudent::class)($s->fresh());
    }

    public function test_attendance_and_seat_occupancy_stay_separate(): void
    {
        [$s] = $this->enrolled(); // no seat allocated

        $a = app(CheckInStudent::class)($s);

        $this->assertNull($a->seat_id);
        $this->assertSame(0, \App\Models\SeatAllocation::count());
    }

    public function test_missed_checkout_needs_an_authorised_correction(): void
    {
        $s = $this->student();
        app(AssignMembership::class)($s, $this->plan(), today()->subDays(10)); // already running two days ago
        Carbon::setTestNow(now()->subDays(2)->setTime(10, 0));
        $a = app(CheckInStudent::class)($s);
        Carbon::setTestNow();

        $this->assertTrue($a->fresh()->isStale());

        try {
            app(CheckOutStudent::class)($a->fresh());
            $this->fail('stale visit closed without correction');
        } catch (BusinessRuleException) {
        }

        $closed = app(CorrectAttendance::class)($a->fresh(), $a->checked_in_at, $a->checked_in_at->copy()->addHours(6), 'Forgot to check out');

        $this->assertFalse($closed->isOpen());
        $c = AttendanceCorrection::first();
        $this->assertSame('Forgot to check out', $c->reason);
        $this->assertNull($c->before['checked_out_at']);
        $this->assertNotNull($c->after['checked_out_at']);
        $this->assertNotNull(AuditLog::where('action', 'attendance.corrected')->first());

        // the student can check in again after the correction
        $this->assertTrue(app(CheckInStudent::class)($s)->isOpen());
    }

    public function test_correction_rules(): void
    {
        [$s] = $this->enrolled();
        $a = app(CheckInStudent::class)($s);
        $out = now();

        foreach ([[$a->checked_in_at, $out, ''], [$a->checked_in_at, $a->checked_in_at->copy()->subHour(), 'bad'], [$a->checked_in_at, now()->addDay(), 'future']] as [$in, $o, $reason]) {
            try {
                app(CorrectAttendance::class)($a->fresh(), $in, $o, $reason);
                $this->fail('invalid correction accepted');
            } catch (BusinessRuleException) {
                $this->assertTrue(true);
            }
        }

        $done = app(CorrectAttendance::class)($a->fresh(), $a->checked_in_at, $out, 'Closing');
        $this->expectException(BusinessRuleException::class); // a finished visit cannot be reopened
        app(CorrectAttendance::class)($done->fresh(), $done->checked_in_at, null, 'reopen');
    }

    public function test_only_authorised_roles_can_record_or_correct_attendance(): void
    {
        [$s] = $this->enrolled();
        $visit = app(CheckInStudent::class)($s);

        $this->actingAs($this->userWithRole(LibraryRoles::ACCOUNTANT)); // no attendance permissions
        foreach ([fn () => app(CheckInStudent::class)($this->student()), fn () => app(CheckOutStudent::class)($visit)] as $attempt) {
            try {
                $attempt();
                $this->fail('accountant must not touch attendance');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        $this->actingAs($this->userWithRole(LibraryRoles::RECEPTIONIST)); // can record, cannot correct
        $this->expectException(HttpException::class);
        app(CorrectAttendance::class)($visit->fresh(), $visit->checked_in_at, now(), 'nope');
    }
}
