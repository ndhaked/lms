<?php

namespace Tests\Feature\Api;

use App\Actions\AssignMembership;
use App\Actions\CreateStudentLogin;
use App\Actions\RegisterLibrary;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Student;
use App\Models\User;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Shared set-up for the mobile API tests: a library, its owner, staff, students and bearer tokens. */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected Library $library;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = $this->makeLibrary('Alpha', 'owner@alpha.test');
        $this->owner = User::where('email', 'owner@alpha.test')->first();
    }

    protected function tearDown(): void
    {
        Tenancy::flush();
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function makeLibrary(string $name, string $email): Library
    {
        $library = app(RegisterLibrary::class)(['name' => $name], ['name' => "$name Owner", 'email' => $email, 'password' => 'password123']);
        Tenancy::flush();

        return $library->fresh();
    }

    /** Base path for library endpoints. */
    protected function lib(string $path = '', ?Library $library = null): string
    {
        return '/api/v1/libraries/'.($library ?? $this->library)->slug.($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /** A real sign-in through the API; returns the bearer token. */
    protected function login(string $email, string $password = 'password123', array $extra = []): string
    {
        $res = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password, 'device_name' => 'Test phone'] + $extra)->assertOk();
        $this->app['auth']->forgetGuards();

        return $res->json('token');
    }

    /** Headers for an authenticated request. */
    protected function as(string $token): static
    {
        $this->app['auth']->forgetGuards();
        Tenancy::flush();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    protected function ownerToken(): string
    {
        return $this->login('owner@alpha.test');
    }

    /** A staff member with one of the default roles in the library. */
    protected function staff(string $role, string $email): User
    {
        $user = User::create(['name' => $role, 'email' => $email, 'password' => Hash::make('password123')]);
        $user->forceFill(['is_active' => true])->save();
        $this->library->users()->attach($user->id, ['is_active' => true]);
        $previous = getPermissionsTeamId();
        setPermissionsTeamId($this->library->id);
        $user->assignRole($role);
        setPermissionsTeamId($previous);

        return $user;
    }

    protected function inLibrary(\Closure $fn, ?Library $library = null): mixed
    {
        return Tenancy::run($library ?? $this->library, $fn);
    }

    protected function student(array $extra = [], ?Library $library = null): Student
    {
        return $this->inLibrary(fn () => Student::create(array_merge(['name' => 'Student '.uniqid(), 'phone' => (string) random_int(7000000000, 9999999999)], $extra)), $library);
    }

    protected function plan(array $extra = [], ?Library $library = null): MembershipPlan
    {
        return $this->inLibrary(fn () => MembershipPlan::create(array_merge(['name' => 'Monthly '.uniqid(), 'duration_unit' => 'monthly', 'price' => 1200, 'registration_fee' => 0, 'seat_type' => 'flexible', 'status' => 'active'], $extra)), $library);
    }

    /** A student with a running membership. */
    protected function member(array $extra = []): Student
    {
        $student = $this->student($extra);
        $this->actingAs($this->owner);
        $this->inLibrary(fn () => app(AssignMembership::class)($student, $this->plan(), today()));
        $this->app['auth']->forgetGuards();

        return $student->fresh();
    }

    /** A student with a login (Student role); returns [student, user] with password "password123". */
    protected function studentLogin(): array
    {
        $student = $this->member(['email' => 'stu'.uniqid().'@alpha.test']);
        $this->actingAs($this->owner);
        $user = $this->inLibrary(fn () => app(CreateStudentLogin::class)($student));
        $user->forceFill(['password' => Hash::make('password123'), 'is_active' => true])->save();
        $this->app['auth']->forgetGuards();

        return [$student->fresh(), $user->fresh()];
    }

    protected function roleName(string $const): string
    {
        return constant(LibraryRoles::class.'::'.$const);
    }
}
