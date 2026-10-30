<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\Billing\ApproveSubscriptionPayment;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\SubmitSubscriptionPayment;
use App\Actions\RegisterLibrary;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Student;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class StudentViewsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Library $lib;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_super_admin' => true])->save();
        $this->admin = $this->admin->fresh();
        $sub = SubscriptionPlan::create(['name' => 'Std', 'price_monthly' => 1000, 'trial_days' => 0, 'max_students' => 50, 'max_seats' => 50, 'max_staff' => 5, 'modules' => ['exports'], 'grace_days' => 7]);

        $this->actingAs($this->admin);
        $this->lib = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@sv.test']);
        $this->owner = User::where('email', 'o@sv.test')->first();
        app(CreateSubscription::class)($this->lib, $sub, 'monthly', false);
        Tenancy::set($this->lib);
        $this->actingAs($this->owner);
        $p = app(SubmitSubscriptionPayment::class)($this->lib, $sub, 'monthly', 'ACT', 1000, 'upi', today(), null, [UploadedFile::fake()->image('p.png')]);
        $this->actingAs($this->admin);
        app(ApproveSubscriptionPayment::class)($p);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Tenancy::flush();
        parent::tearDown();
    }

    protected function student(string $name, array $extra = []): Student
    {
        static $n = 0;

        return Student::create(array_merge(['name' => $name, 'phone' => '90000'.str_pad((string) ++$n, 5, '0', STR_PAD_LEFT), 'registered_on' => today(), 'status' => 'active'], $extra));
    }

    public function test_the_students_menu_has_the_five_member_views(): void
    {
        $this->get("/app/{$this->lib->slug}/students")->assertOk()
            ->assertSee('Total Members')->assertSee('VIP Members')->assertSee('Blocked Members')->assertSee('Left Members')->assertSee('Frozen Members')
            ->assertSee('view=vip', false)->assertSee('status=suspended', false)->assertSee('status=inactive', false)->assertSee('view=frozen', false);
    }

    public function test_each_view_lists_only_its_own_members(): void
    {
        $plan = MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 1000]);
        $regular = $this->student('Regular Ravi');
        $vip = $this->student('Vip Vimala', ['is_vip' => true]);
        $blocked = $this->student('Blocked Bhanu', ['status' => 'suspended']);
        $left = $this->student('Left Lata', ['status' => 'inactive']);
        $frozen = $this->student('Frozen Farid');
        $m = app(AssignMembership::class)($frozen, $plan, today());
        $m->forceFill(['status' => 'suspended'])->save();

        $names = fn (array $q) => Livewire::withQueryParams($q)->test('pages::app.students.index')->instance()->students->pluck('name')->sort()->values()->all();

        $this->assertCount(5, $names([]));                                                    // total members
        $this->assertSame(['Vip Vimala'], $names(['view' => 'vip']));
        $this->assertSame(['Blocked Bhanu'], $names(['status' => 'suspended']));
        $this->assertSame(['Left Lata'], $names(['status' => 'inactive']));
        $this->assertSame(['Frozen Farid'], $names(['view' => 'frozen']));
    }

    public function test_a_student_can_be_marked_vip_and_shows_a_badge(): void
    {
        $s = $this->student('Asha');

        Livewire::test('pages::app.students.form', ['student' => $s->id])->set('is_vip', true)->call('save')->assertHasNoErrors();

        $this->assertTrue($s->fresh()->is_vip);
        $this->get("/app/{$this->lib->slug}/students")->assertSee('VIP');
    }

    public function test_the_list_filters_by_registration_date_range(): void
    {
        $old = $this->student('Old Olive', ['registered_on' => '2026-01-10']);
        $mid = $this->student('Mid Mala', ['registered_on' => '2026-03-15']);
        $new = $this->student('New Nikhil', ['registered_on' => '2026-05-20']);

        $names = fn (array $q) => Livewire::withQueryParams($q)->test('pages::app.students.index')->instance()->students->pluck('name')->sort()->values()->all();

        $this->assertSame(['Mid Mala', 'New Nikhil', 'Old Olive'], $names([]));
        $this->assertSame(['Mid Mala', 'New Nikhil'], $names(['from' => '2026-03-01']));
        $this->assertSame(['Mid Mala', 'Old Olive'], $names(['until' => '2026-03-31']));
        $this->assertSame(['Mid Mala'], $names(['from' => '2026-03-01', 'until' => '2026-03-31']));
        $this->assertSame(['Mid Mala'], $names(['from' => '2026-03-15', 'until' => '2026-03-15']));           // both ends are included
        $this->assertSame([], $names(['from' => '2026-06-01']));
        $this->assertCount(3, $names(['from' => 'garbage; drop table']));                                        // a bad date is ignored
    }

    public function test_quick_ranges_set_and_clear_the_dates_and_combine_with_other_filters(): void
    {
        $today = $this->student('Today Tara', ['registered_on' => today()]);
        $this->student('Old Omar', ['registered_on' => today()->subDays(90)]);
        $vipToday = $this->student('Vip Today', ['registered_on' => today(), 'is_vip' => true]);

        $page = Livewire::test('pages::app.students.index')->call('range', 'today');
        $page->assertSet('from', today()->toDateString())->assertSet('until', today()->toDateString());
        $this->assertSame(['Today Tara', 'Vip Today'], $page->instance()->students->pluck('name')->sort()->values()->all());

        $page->set('view', 'vip');
        $this->assertSame(['Vip Today'], $page->instance()->students->pluck('name')->all());

        $page->call('range', 'clear')->assertSet('from', '')->assertSet('until', '');
        $this->assertSame(['Vip Today'], $page->instance()->students->pluck('name')->all());                  // the VIP filter is still on
    }

    public function test_the_export_follows_the_chosen_dates(): void
    {
        $this->get("/app/{$this->lib->slug}/students?from=2026-03-01&until=2026-03-31")->assertOk()
            ->assertSee('from=2026-03-01', false)->assertSee('until=2026-03-31', false);
    }
    public function test_a_deactivated_student_keeps_and_shows_the_seats_they_used(): void
    {
        $plan = MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 1000]);
        $hall = \App\Models\StudyHall::create(['name' => 'Hall A', 'status' => 'active']);
        $first = \App\Models\Seat::create(['study_hall_id' => $hall->id, 'code' => 'A-01']);
        $second = \App\Models\Seat::create(['study_hall_id' => $hall->id, 'code' => 'A-02']);
        $student = $this->student('Amit');
        $m = app(AssignMembership::class)($student, $plan, today());

        $a1 = app(\App\Actions\AllocateSeat::class)($first, $student);
        app(\App\Actions\ReleaseSeat::class)($a1, 'changed_seat');
        app(\App\Actions\AllocateSeat::class)($second, $student);

        Livewire::test('pages::app.students.index')->call('deactivate', $student->id);

        $this->assertSame('inactive', $student->fresh()->status);
        $this->assertSame(2, $student->allocations()->count(), 'both seats stay in the history');
        $this->assertNull($student->fresh()->activeAllocation);

        $this->get("/app/{$this->lib->slug}/students")->assertSee('A-02')->assertSee('released '.today()->format('d M Y'));
        $this->get("/app/{$this->lib->slug}/students/{$student->id}")->assertSee('Not allocated now')->assertSee('Last seat A-02')->assertSee('student deactivated')->assertSee('A-01')->assertSee('changed seat');
    }
    protected function seatSetup(): array
    {
        $plan = MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 1000]);
        $hall = \App\Models\StudyHall::create(['name' => 'Hall A', 'status' => 'active']);
        $s1 = \App\Models\Seat::create(['study_hall_id' => $hall->id, 'code' => 'A-01']);
        $s2 = \App\Models\Seat::create(['study_hall_id' => $hall->id, 'code' => 'A-02']);

        return [$plan, $s1, $s2];
    }

    protected function register(string $name, string $phone)
    {
        return Livewire::test('pages::app.students.form')->set('name', $name)->set('phone', $phone)->set('registered_on', today()->toDateString())->call('save');
    }

    public function test_registering_a_student_offers_the_seat_popup_and_skip_leaves_them_unseated(): void
    {
        [$plan, $s1] = $this->seatSetup();

        $page = $this->register('Asha Rao', '9333333301');
        $page->assertHasNoErrors()->assertSet('showSeat', true)->assertSee('Seat selection')->assertSee('A-01')->assertSee('A-02')->assertNoRedirect();

        $student = Student::where('phone', '9333333301')->firstOrFail();
        $page->call('skipSeat')->assertRedirect(route('app.students.show', ['library' => $this->lib->slug, 'student' => $student->id]));

        $this->assertSame([0, 0], [$student->allocations()->count(), $student->memberships()->count()]);
        $this->assertSame('available', $s1->fresh()->status);
    }

    public function test_choosing_a_seat_starts_the_plan_and_gives_the_seat_together(): void
    {
        [$plan, $s1, $s2] = $this->seatSetup();

        $page = $this->register('Bala Iyer', '9333333302');
        $student = Student::where('phone', '9333333302')->firstOrFail();

        $page->call('addSeat')->assertHasErrors('pickSeat');                                  // nothing chosen yet
        $page->call('selectSeat', $s2->id)->set('pickPlan', (string) $plan->id)->call('addSeat')->assertHasNoErrors()
            ->assertRedirect(route('app.students.show', ['library' => $this->lib->slug, 'student' => $student->id]));

        $this->assertSame('A-02', $student->fresh()->activeAllocation->seat->code);
        $this->assertSame('occupied', $s2->fresh()->status);
        $this->assertSame(1, $student->memberships()->count());
        $this->assertSame('available', $s1->fresh()->status);
    }

    public function test_a_seat_taken_meanwhile_is_refused_and_nothing_is_created(): void
    {
        [$plan, $s1, $s2] = $this->seatSetup();

        $page = $this->register('Chitra Das', '9333333303');
        $page->call('selectSeat', $s1->id);

        $other = $this->student('Someone Else');                                              // grabs A-01 while the pop-up is open
        app(AssignMembership::class)($other, $plan, today());
        app(\App\Actions\AllocateSeat::class)($s1, $other);

        $page->set('pickPlan', (string) $plan->id)->call('addSeat')->assertHasErrors('pickSeat');

        $student = Student::where('phone', '9333333303')->firstOrFail();
        $this->assertSame([0, 0], [$student->memberships()->count(), $student->allocations()->count()], 'the plan is not started when the seat fails');
    }

    public function test_an_occupied_seat_cannot_be_picked_and_the_hall_filter_works(): void
    {
        [$plan, $s1, $s2] = $this->seatSetup();
        $other = $this->student('Occupier');
        app(AssignMembership::class)($other, $plan, today());
        app(\App\Actions\AllocateSeat::class)($s1, $other);
        $hallB = \App\Models\StudyHall::create(['name' => 'Hall B', 'status' => 'active']);
        \App\Models\Seat::create(['study_hall_id' => $hallB->id, 'code' => 'B-01']);

        $page = $this->register('Divya Nair', '9333333304')->assertSee('Occupier');
        $page->call('selectSeat', $s1->id)->assertSet('pickSeat', null);
        $page->set('hallFilter', (string) $hallB->id)->assertSee('B-01')->assertDontSee('A-02');
    }

    public function test_there_is_no_popup_without_permission_seats_or_plans_and_editing_never_shows_it(): void
    {
        // no seats or plans yet: straight to the profile as before
        $this->register('Esha Gill', '9333333305')->assertSet('showSeat', false)->assertRedirect();

        [$plan, $s1] = $this->seatSetup();

        // a receptionist can register but cannot start memberships
        $desk = User::factory()->create();
        $this->lib->users()->attach($desk->id, ['is_active' => true]);
        Tenancy::run($this->lib, fn () => $desk->assignRole(\App\Support\LibraryRoles::RECEPTIONIST));
        $this->actingAs($desk->fresh());
        $this->register('Farah Khan', '9333333306')->assertSet('showSeat', false)->assertRedirect();

        // the same rule when editing: a receptionist cannot start a plan, so a student with none gets no pop-up
        $this->actingAs($this->owner);
        $existing = Student::where('phone', '9333333305')->firstOrFail();
        $this->actingAs($desk->fresh());
        Livewire::test('pages::app.students.form', ['student' => $existing->id])->set('name', 'Esha G')->call('save')->assertSet('showSeat', false)->assertRedirect();
    }

    protected function edit(Student $s)
    {
        return Livewire::test('pages::app.students.form', ['student' => $s->id]);
    }

    public function test_updating_a_student_with_no_seat_asks_for_one_and_a_seated_student_is_not_asked(): void
    {
        [$plan, $s1, $s2] = $this->seatSetup();
        $unseated = $this->student('Gita Menon');

        $this->edit($unseated)->set('name', 'Gita M')->call('save')->assertSet('showSeat', true)->assertSet('seatAfterSave', true)->assertNoRedirect()->assertSee('Seat selection');

        $seated = $this->student('Hari Om');
        app(AssignMembership::class)($seated, $plan, today());
        app(\App\Actions\AllocateSeat::class)($s1, $seated);
        $this->edit($seated)->set('name', 'Hari O')->call('save')->assertSet('showSeat', false)->assertRedirect();
    }

    public function test_the_allot_seat_button_opens_the_popup_and_skip_only_closes_it(): void
    {
        [$plan, $s1] = $this->seatSetup();
        $student = $this->student('Indu Sen');

        $page = $this->edit($student)->assertSee('Allot Seat')->assertSee('Select seat')->call('openSeatPicker');
        $page->assertSet('showSeat', true)->assertSet('seatAfterSave', false)->assertSee('Seat selection');
        $page->call('skipSeat')->assertSet('showSeat', false)->assertNoRedirect();
    }

    public function test_a_student_with_a_running_membership_is_seated_without_choosing_a_plan_and_can_be_moved_or_unseated(): void
    {
        [$plan, $s1, $s2] = $this->seatSetup();
        $student = $this->student('Jaya Pal');
        app(AssignMembership::class)($student, $plan, today());

        $page = $this->edit($student)->call('openSeatPicker');
        $page->assertDontSee('Membership plan')->call('selectSeat', $s1->id)->call('addSeat')->assertHasNoErrors()->assertRedirect();
        $this->assertSame([1, 'A-01'], [$student->memberships()->count(), $student->fresh()->activeAllocation->seat->code]);

        $this->edit($student)->assertSee('Change seat')->call('openSeatPicker')->assertSee('Current')
            ->call('selectSeat', $s2->id)->call('addSeat')->assertRedirect();
        $this->assertSame(['A-02', 'available', 2], [$student->fresh()->activeAllocation->seat->code, $s1->fresh()->status, $student->allocations()->count()]);
        $this->assertSame('seat_changed', $student->allocations()->orderBy('id')->first()->release_reason);

        $this->edit($student)->call('openSeatPicker')->call('removeSeat')->assertRedirect();
        $this->assertSame([null, 'available'], [$student->fresh()->activeAllocation, $s2->fresh()->status]);
        $this->assertSame('seat_removed', $student->allocations()->orderByDesc('id')->first()->release_reason);
    }

    public function test_an_inactive_student_cannot_be_given_a_seat_and_the_row_is_hidden(): void
    {
        [$plan, $s1] = $this->seatSetup();
        $gone = $this->student('Kabir Jha', ['status' => 'inactive']);

        $this->edit($gone)->assertDontSee('Allot Seat')->call('openSeatPicker')->assertForbidden();
    }}
