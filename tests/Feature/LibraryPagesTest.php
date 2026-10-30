<?php

namespace Tests\Feature;

use App\Actions\AllocateSeat;
use App\Actions\AssignMembership;
use App\Actions\RegisterLibrary;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Seat;
use App\Models\SeatAllocation;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Models\StudyHall;
use App\Models\User;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LibraryPagesTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->library = app(RegisterLibrary::class)(
            ['name' => 'Alpha', 'status' => Library::ACTIVE],
            ['name' => 'Olive Owner', 'email' => 'owner@alpha.test', 'phone' => '9000000000'],
        );
        $this->owner = User::where('email', 'owner@alpha.test')->first();
        $this->actingAs($this->owner);
        Tenancy::set($this->library);
    }

    protected function tearDown(): void
    {
        Tenancy::flush();
        parent::tearDown();
    }

    protected function userWithRole(string $role): User
    {
        $u = User::factory()->create();
        $this->library->users()->attach($u->id, ['is_active' => true]);
        Tenancy::run($this->library, fn () => $u->assignRole($role));

        return $u->fresh();
    }

    protected function makeData(): array
    {
        $hall = StudyHall::create(['name' => 'Hall A']);
        $seat = Seat::create(['study_hall_id' => $hall->id, 'code' => 'A-01']);
        $plan = MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 1200, 'registration_fee' => 100]);
        $student = Student::create(['name' => 'Priya Singh', 'phone' => '9111111111']);
        $membership = app(AssignMembership::class)($student, $plan, today());

        return compact('hall', 'seat', 'plan', 'student', 'membership');
    }

    public function test_every_library_page_renders_with_data(): void
    {
        $d = $this->makeData();
        app(AllocateSeat::class)($d['seat'], $d['student']);
        $slug = $this->library->slug;

        foreach (['', '/students', '/students/create', "/students/{$d['student']->id}", "/students/{$d['student']->id}/edit", '/halls', '/seats', '/seats?view=map', '/membership-plans', '/memberships'] as $path) {
            $this->get("/app/{$slug}{$path}")->assertOk();
        }

        $this->get("/app/{$slug}/students")->assertSee('Priya Singh')->assertSee('STU-00001');
        $this->get("/app/{$slug}/seats")->assertSee('A-01')->assertSee('Priya Singh');
        $this->get("/app/{$slug}/memberships")->assertSee('Monthly');
        $this->get("/app/{$slug}/halls")->assertSee('Hall A');
    }

    public function test_student_form_creates_validates_and_blocks_duplicate_phone(): void
    {
        Livewire::test('pages::app.students.form')
            ->set('name', 'Amit Verma')->set('phone', '9222222222')->set('registered_on', today()->toDateString())
            ->call('save')->assertHasNoErrors();

        $student = Student::where('phone', '9222222222')->first();
        $this->assertSame('STU-00001', $student->student_code);
        $this->assertSame(Student::ACTIVE, $student->status);

        Livewire::test('pages::app.students.form')
            ->set('name', 'Someone Else')->set('phone', '9222222222')->set('registered_on', today()->toDateString())
            ->call('save')->assertHasErrors(['phone']);

        Livewire::test('pages::app.students.form')->call('save')->assertHasErrors(['name', 'phone']);
    }

    public function test_student_edit_page_and_foreign_ids(): void
    {
        $student = $this->makeData()['student'];

        Livewire::test('pages::app.students.form', ['student' => (string) $student->id])
            ->assertSet('name', 'Priya Singh')
            ->set('name', 'Priya S.')->call('save')->assertHasNoErrors();
        $this->assertSame('Priya S.', $student->fresh()->name);

        // A student of another library is invisible to this one.
        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        Tenancy::set($other);
        $foreign = Student::create(['name' => 'Foreign', 'phone' => '9333333333']);
        Tenancy::set($this->library);

        Livewire::test('pages::app.students.form', ['student' => (string) $foreign->id])->assertNotFound();
    }

    public function test_deactivating_a_student_releases_the_seat_and_keeps_history(): void
    {
        $d = $this->makeData();
        app(AllocateSeat::class)($d['seat'], $d['student']);

        Livewire::test('pages::app.students.index')->call('deactivate', $d['student']->id);

        $this->assertSame(Student::INACTIVE, $d['student']->fresh()->status);
        $this->assertSame(Seat::AVAILABLE, $d['seat']->fresh()->status);
        $this->assertSame(1, SeatAllocation::count());
        $this->assertDatabaseHas('students', ['id' => $d['student']->id]);
    }

    public function test_allocate_and_release_through_the_seats_page(): void
    {
        $d = $this->makeData();

        $page = Livewire::test('pages::app.seats.index')
            ->call('startAllocate', $d['seat']->id)
            ->set('chosenStudent', $d['student']->id)
            ->call('allocate')->assertHasNoErrors();

        $this->assertSame(Seat::OCCUPIED, $d['seat']->fresh()->status);

        $page->call('release', $d['seat']->id);
        $this->assertSame(Seat::AVAILABLE, $d['seat']->fresh()->status);
    }

    public function test_allocating_without_choosing_or_to_an_ineligible_student_shows_an_error(): void
    {
        $d = $this->makeData();
        $bare = Student::create(['name' => 'No Plan', 'phone' => '9444444444']);

        Livewire::test('pages::app.seats.index')
            ->call('startAllocate', $d['seat']->id)->call('allocate')->assertHasErrors('chosenStudent');

        Livewire::test('pages::app.seats.index')
            ->call('startAllocate', $d['seat']->id)->set('chosenStudent', $bare->id)->call('allocate')->assertHasErrors('chosenStudent');
        $this->assertSame(Seat::AVAILABLE, $d['seat']->fresh()->status);
    }

    public function test_generating_seats_skips_existing_numbers(): void
    {
        $d = $this->makeData(); // A-01 exists

        Livewire::test('pages::app.seats.index')
            ->set('g_hall', (string) $d['hall']->id)->set('g_prefix', 'A-')->set('g_start', '1')->set('g_count', '5')
            ->call('generate')->assertHasNoErrors();

        $this->assertSame(5, Seat::count());
        $this->assertSame(['A-01', 'A-02', 'A-03', 'A-04', 'A-05'], Seat::orderBy('code')->pluck('code')->all());
    }

    public function test_seat_cannot_be_put_in_another_librarys_hall(): void
    {
        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        Tenancy::set($other);
        $foreignHall = StudyHall::create(['name' => 'Foreign Hall']);
        Tenancy::set($this->library);

        Livewire::test('pages::app.seats.index')
            ->set('s_hall', (string) $foreignHall->id)->set('s_code', 'X-1')
            ->call('saveSeat')->assertHasErrors('s_hall');

        $this->assertSame(0, Seat::count());
    }

    public function test_membership_assign_renew_and_overlap_through_the_page(): void
    {
        $d = $this->makeData(); // student already has a running monthly membership

        // overlapping period -> error, nothing created
        Livewire::test('pages::app.memberships.index')
            ->call('openAssign', $d['student']->id)
            ->set('planId', (string) $d['plan']->id)->set('startsOn', today()->addDays(5)->toDateString())
            ->call('saveAssign')->assertHasErrors('planId');
        $this->assertSame(1, StudentMembership::count());

        // renew starts the day after the current one ends
        Livewire::test('pages::app.memberships.index')->call('renew', $d['membership']->id);
        $this->assertSame(2, StudentMembership::count());
        $this->assertTrue(StudentMembership::orderByDesc('id')->first()->starts_on->eq($d['membership']->ends_on->copy()->addDay()));

        // a new student can be assigned
        $new = Student::create(['name' => 'Neha', 'phone' => '9555555555']);
        Livewire::test('pages::app.memberships.index')
            ->call('openAssign', $new->id)
            ->set('planId', (string) $d['plan']->id)->set('startsOn', today()->toDateString())
            ->call('saveAssign')->assertHasNoErrors();
        $this->assertSame(3, StudentMembership::count());
    }

    public function test_halls_and_plans_can_be_saved_and_validated(): void
    {
        Livewire::test('pages::app.halls.index')->call('create')->call('save')->assertHasErrors('name');
        Livewire::test('pages::app.halls.index')->call('create')->set('name', 'Floor 2')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('study_halls', ['name' => 'Floor 2', 'library_id' => $this->library->id]);

        Livewire::test('pages::app.plans.index')->call('create')->set('name', 'Quarterly')->set('price', '-5')->call('save')->assertHasErrors('price');
        Livewire::test('pages::app.plans.index')->call('create')->set('name', 'Quarterly')->set('duration_unit', 'quarterly')->set('price', '3300')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('membership_plans', ['name' => 'Quarterly', 'library_id' => $this->library->id]);
    }

    public function test_actions_enforce_permissions_even_when_called_directly(): void
    {
        $d = $this->makeData();
        $this->actingAs($this->userWithRole(LibraryRoles::SUPERVISOR)); // seats.view only

        // can open the page but cannot invoke management actions
        foreach ([['pages::app.seats.index', 'createSeat', []], ['pages::app.seats.index', 'startAllocate', [$d['seat']->id]],
            ['pages::app.halls.index', 'create', []]] as [$component, $method, $args]) {
            Livewire::test($component)->call($method, ...$args)->assertForbidden();
        }

        // a receptionist can allocate seats but cannot create plans
        $this->actingAs($this->userWithRole(LibraryRoles::RECEPTIONIST));
        Livewire::test('pages::app.seats.index')->call('startAllocate', $d['seat']->id)->assertOk()->assertSet('allocatingSeat', $d['seat']->id);
        Livewire::test('pages::app.plans.index')->call('create')->assertForbidden();
        Livewire::test('pages::app.halls.index')->call('create')->assertForbidden();

        // pages themselves are gated by the view permission
        $this->actingAs($this->userWithRole(LibraryRoles::SUPERVISOR));
        $this->get("/app/{$this->library->slug}/students")->assertForbidden();
    }

    public function test_guests_are_sent_to_login_and_login_works(): void
    {
        auth()->logout();
        $this->get("/app/{$this->library->slug}")->assertRedirect('/login');
        $this->get('/admin')->assertRedirect('/login');

        $user = User::factory()->create(['password' => 'secret-pass-1']);
        $this->library->users()->attach($user->id, ['is_active' => true]);

        Livewire::test('pages::auth.login')->set('email', $user->email)->set('password', 'wrong')->call('login')->assertHasErrors('email');
        Livewire::test('pages::auth.login')->set('email', $user->email)->set('password', 'secret-pass-1')->call('login')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_accounts_cannot_sign_in(): void
    {
        auth()->logout();
        $user = User::factory()->create(['password' => 'secret-pass-1']);
        $user->forceFill(['is_active' => false])->save();

        Livewire::test('pages::auth.login')->set('email', $user->email)->set('password', 'secret-pass-1')->call('login')->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_home_redirects_by_role(): void
    {
        $this->get('/workspace')->assertRedirect("/app/{$this->library->slug}");

        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin->fresh())->get('/workspace')->assertRedirect('/admin');
    }

    public function test_password_setup_link_lets_the_owner_choose_a_password(): void
    {
        auth()->logout();
        $user = User::factory()->create(['email' => 'new@owner.test']);
        $token = \Illuminate\Support\Facades\Password::broker()->createToken($user);

        Livewire::test('pages::auth.reset-password', ['token' => $token])
            ->set('email', 'new@owner.test')->set('password', 'brand-new-pass')->set('password_confirmation', 'brand-new-pass')
            ->call('save')->assertHasNoErrors();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('brand-new-pass', $user->fresh()->password));

        // the same link cannot be reused
        Livewire::test('pages::auth.reset-password', ['token' => $token])
            ->set('email', 'new@owner.test')->set('password', 'another-pass-1')->set('password_confirmation', 'another-pass-1')
            ->call('save')->assertHasErrors('email');
    }
}
