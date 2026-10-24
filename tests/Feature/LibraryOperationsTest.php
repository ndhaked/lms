<?php

namespace Tests\Feature;

use App\Actions\AllocateSeat;
use App\Actions\AssignMembership;
use App\Actions\RegisterLibrary;
use App\Actions\ReleaseSeat;
use App\Exceptions\BusinessRuleException;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Seat;
use App\Models\SeatAllocation;
use App\Models\Student;
use App\Models\StudyHall;
use App\Models\User;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenancy::flush();
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function library(string $name, string $email): Library
    {
        return app(RegisterLibrary::class)(['name' => $name], ['name' => "$name Owner", 'email' => $email, 'password' => 'password123']);
    }

    protected function owner(string $email): User
    {
        $user = User::where('email', $email)->first();
        $this->actingAs($user);

        return $user;
    }

    protected function plan(string $unit = 'monthly', array $extra = []): MembershipPlan
    {
        return MembershipPlan::create(array_merge([
            'name' => ucfirst($unit).' '.uniqid(), 'duration_unit' => $unit, 'price' => 1200, 'registration_fee' => 100,
            'seat_type' => 'flexible', 'status' => 'active',
        ], $extra));
    }

    protected function student(string $phone = null, array $extra = []): Student
    {
        return Student::create(array_merge(['name' => 'Student '.uniqid(), 'phone' => $phone ?? (string) random_int(7000000000, 9999999999)], $extra));
    }

    protected function seat(string $code = 'A-01'): Seat
    {
        $hall = StudyHall::firstOrCreate(['name' => 'Hall 1'], ['status' => 'active']);

        return Seat::create(['study_hall_id' => $hall->id, 'code' => $code]);
    }

    protected function seatedReady(): array
    {
        $this->library('Alpha', 'a@example.com');
        $this->owner('a@example.com');
        Tenancy::set(Library::first());

        $student = $this->student();
        app(AssignMembership::class)($student, $this->plan(), today());

        return [$student, $this->seat()];
    }

    // ---------- tenant isolation ----------

    public function test_students_seats_and_plans_are_isolated_per_library(): void
    {
        $a = $this->library('Alpha', 'a@example.com');
        $b = $this->library('Beta', 'b@example.com');

        Tenancy::set($a);
        $studentA = $this->student('9000000001');
        $this->seat('A-01');
        $this->plan();

        Tenancy::set($b);
        $this->assertSame(0, Student::count());
        $this->assertSame(0, Seat::count());
        $this->assertSame(0, MembershipPlan::count());
        $this->assertNull(Student::find($studentA->id), 'cannot load another library\'s student by id');

        // The same phone / seat number may exist independently in another library.
        $studentB = $this->student('9000000001');
        $this->assertSame('STU-00001', $studentB->student_code);
        $this->assertSame('STU-00001', $studentA->student_code);
    }

    public function test_changing_ids_in_the_url_cannot_reach_another_librarys_records(): void
    {
        $a = $this->library('Alpha', 'a@example.com');
        $b = $this->library('Beta', 'b@example.com');

        Tenancy::set($b);
        $foreign = $this->student();
        Tenancy::flush();

        $this->owner('a@example.com');
        // Own library works, foreign record id under own tenant is not found.
        $this->get("/app/{$a->slug}/students")->assertOk();
        $this->get("/app/{$a->slug}/students/{$foreign->id}")->assertNotFound();
        $this->get("/app/{$a->slug}/students/{$foreign->id}/edit")->assertNotFound();
        $this->get("/app/{$b->slug}/students")->assertNotFound();
    }

    public function test_cannot_allocate_another_librarys_seat(): void
    {
        $a = $this->library('Alpha', 'a@example.com');
        $b = $this->library('Beta', 'b@example.com');

        Tenancy::set($b);
        $foreignSeat = $this->seat('B-01');

        Tenancy::set($a);
        $student = $this->student();
        app(AssignMembership::class)($student, $this->plan(), today());

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(AllocateSeat::class)($foreignSeat, $student);
    }

    // ---------- membership rules ----------

    public function test_membership_end_dates_follow_the_plan_duration(): void
    {
        $this->library('Alpha', 'a@example.com');
        Tenancy::set(Library::first());

        $cases = [
            ['daily', '2026-01-31', '2026-01-31'],
            ['weekly', '2026-01-01', '2026-01-07'],
            ['monthly', '2026-01-31', '2026-02-27'],   // no overflow into March
            ['monthly', '2026-01-01', '2026-01-31'],
            ['quarterly', '2026-01-01', '2026-03-31'],
            ['half_yearly', '2026-01-01', '2026-06-30'],
            ['yearly', '2026-01-01', '2026-12-31'],
        ];

        foreach ($cases as [$unit, $start, $end]) {
            $plan = $this->plan($unit);
            $this->assertSame($end, $plan->endDateFor(Carbon::parse($start))->toDateString(), "$unit from $start");
        }
    }

    public function test_membership_snapshots_price_and_does_not_imply_payment(): void
    {
        $this->library('Alpha', 'a@example.com');
        $this->owner('a@example.com');
        Tenancy::set(Library::first());

        $student = $this->student();
        $plan = $this->plan();
        $m = app(AssignMembership::class)($student, $plan, today());

        $plan->update(['price' => 9999]);
        $this->assertSame('1200.00', $m->fresh()->price);
        $this->assertSame('100.00', $m->fresh()->registration_fee, 'fee on first membership');
        $this->assertSame('1300.00', $m->fresh()->totalDue());

        // No payment/paid column exists on a membership: creating one never marks it paid.
        $this->assertArrayNotHasKey('paid', $m->fresh()->getAttributes());
        $this->assertArrayNotHasKey('payment_status', $m->fresh()->getAttributes());
    }

    public function test_registration_fee_is_only_charged_once(): void
    {
        $this->library('Alpha', 'a@example.com');
        $this->owner('a@example.com');
        Tenancy::set(Library::first());

        $student = $this->student();
        $plan = $this->plan();
        $first = app(AssignMembership::class)($student, $plan, today());
        $second = app(AssignMembership::class)($student, $plan, $first->ends_on->copy()->addDay());

        $this->assertSame('100.00', $first->registration_fee);
        $this->assertSame('0.00', $second->registration_fee);
    }

    public function test_overlapping_memberships_are_rejected(): void
    {
        $this->library('Alpha', 'a@example.com');
        $this->owner('a@example.com');
        Tenancy::set(Library::first());

        $student = $this->student();
        $plan = $this->plan();
        app(AssignMembership::class)($student, $plan, today());

        $this->expectException(BusinessRuleException::class);
        app(AssignMembership::class)($student, $plan, today()->addDays(10));
    }

    public function test_discount_requires_permission_and_cannot_exceed_price(): void
    {
        $this->library('Alpha', 'a@example.com');
        $library = Library::first();
        Tenancy::set($library);

        $clerk = User::factory()->create();
        $library->users()->attach($clerk->id, ['is_active' => true]);
        $clerk->assignRole(LibraryRoles::RECEPTIONIST);
        $this->actingAs($clerk);

        $student = $this->student();
        $plan = $this->plan();

        try {
            app(AssignMembership::class)($student, $plan, today(), 100);
            $this->fail('Receptionist must not be able to discount.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }

        $this->owner('a@example.com');
        $this->expectException(BusinessRuleException::class);
        app(AssignMembership::class)($student, $plan, today(), 5000);
    }

    public function test_expired_and_upcoming_memberships_are_derived_from_dates(): void
    {
        $this->library('Alpha', 'a@example.com');
        $this->owner('a@example.com');
        Tenancy::set(Library::first());

        $student = $this->student();
        $plan = $this->plan('weekly');

        $past = app(AssignMembership::class)($student, $plan, today()->subDays(30));
        $future = app(AssignMembership::class)($student, $plan, today()->addDays(30));

        $this->assertSame('expired', $past->effectiveStatus());
        $this->assertSame('upcoming', $future->effectiveStatus());
        $this->assertNull($student->currentMembership());
    }

    // ---------- seat allocation ----------

    public function test_allocating_and_releasing_a_seat(): void
    {
        [$student, $seat] = $this->seatedReady();

        $allocation = app(AllocateSeat::class)($seat, $student);
        $this->assertSame(Seat::OCCUPIED, $seat->fresh()->status);
        $this->assertTrue($allocation->isActive());

        app(ReleaseSeat::class)($allocation);
        $this->assertSame(Seat::AVAILABLE, $seat->fresh()->status);
        $this->assertNotNull($allocation->fresh()->released_at);
        $this->assertSame(1, SeatAllocation::count(), 'history is kept');

        // The seat can be given to someone else, and the student to another seat.
        $again = app(AllocateSeat::class)($seat->fresh(), $student);
        $this->assertTrue($again->isActive());
        $this->assertSame(2, SeatAllocation::count());
    }

    public function test_a_seat_cannot_be_allocated_twice(): void
    {
        [$student, $seat] = $this->seatedReady();
        $other = $this->student();
        app(AssignMembership::class)($other, $this->plan(), today());

        app(AllocateSeat::class)($seat, $student);

        $this->expectException(BusinessRuleException::class);
        app(AllocateSeat::class)($seat->fresh(), $other);
    }

    public function test_a_student_cannot_hold_two_seats(): void
    {
        [$student, $seat] = $this->seatedReady();
        $second = $this->seat('A-02');

        app(AllocateSeat::class)($seat, $student);

        $this->expectException(BusinessRuleException::class);
        app(AllocateSeat::class)($second, $student);
    }

    public function test_student_without_membership_cannot_get_a_seat(): void
    {
        [, $seat] = $this->seatedReady();
        $bare = $this->student();

        $this->expectException(BusinessRuleException::class);
        app(AllocateSeat::class)($seat, $bare);
    }

    public function test_unavailable_seats_and_inactive_students_are_refused(): void
    {
        [$student, $seat] = $this->seatedReady();

        $seat->update(['status' => Seat::MAINTENANCE]);
        try {
            app(AllocateSeat::class)($seat->fresh(), $student);
            $this->fail('maintenance seat allocated');
        } catch (BusinessRuleException) {
            $this->assertTrue(true);
        }

        $seat->update(['status' => Seat::AVAILABLE]);
        $student->update(['status' => Student::SUSPENDED]);
        $this->expectException(BusinessRuleException::class);
        app(AllocateSeat::class)($seat->fresh(), $student->fresh());
    }

    public function test_database_blocks_a_second_active_allocation_even_if_app_checks_are_bypassed(): void
    {
        [$student, $seat] = $this->seatedReady();
        app(AllocateSeat::class)($seat, $student);
        $other = $this->student();

        $this->expectException(UniqueConstraintViolationException::class);
        SeatAllocation::create([
            'seat_id' => $seat->id, 'student_id' => $other->id, 'allocated_at' => now(),
            'active_seat_id' => $seat->id, 'active_student_id' => $other->id,
        ]);
    }

    // ---------- permissions on pages ----------

    public function test_role_permissions_control_access_to_pages(): void
    {
        $library = $this->library('Alpha', 'a@example.com');
        Tenancy::set($library);

        $make = function (string $role) use ($library) {
            $u = User::factory()->create();
            $library->users()->attach($u->id, ['is_active' => true]);
            Tenancy::run($library, fn () => $u->assignRole($role));

            return $u;
        };

        $supervisor = $make(LibraryRoles::SUPERVISOR);   // seats.view only
        $accountant = $make(LibraryRoles::ACCOUNTANT);   // students.view, memberships.view, no seats

        $this->actingAs($supervisor->fresh());
        $this->get("/app/{$library->slug}/seats")->assertOk();
        $this->get("/app/{$library->slug}/students")->assertForbidden();
        $this->get("/app/{$library->slug}/membership-plans")->assertForbidden();

        $this->actingAs($accountant->fresh());
        $this->get("/app/{$library->slug}/students")->assertOk();
        $this->get("/app/{$library->slug}/seats")->assertForbidden();
    }

    public function test_owner_can_open_every_phase_2_page(): void
    {
        $library = $this->library('Alpha', 'a@example.com');
        $this->owner('a@example.com');

        foreach (['students', 'students/create', 'seats', 'halls', 'membership-plans', 'memberships'] as $path) {
            $this->get("/app/{$library->slug}/{$path}")->assertOk();
        }
    }
}
