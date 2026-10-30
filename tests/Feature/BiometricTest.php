<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\CheckInStudent;
use App\Actions\RegisterLibrary;
use App\Models\Attendance;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BiometricTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenancy::flush();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Library, 1: BiometricDevice, 2: Student, 3: User} */
    protected function setUpLibrary(string $email = 'a@example.com', string $serial = 'ZK-001', string $pin = '101'): array
    {
        $library = app(RegisterLibrary::class)(['name' => 'Alpha '.$serial], ['name' => 'Owner', 'email' => $email, 'password' => 'password123']);
        $owner = User::where('email', $email)->first();
        Tenancy::set($library);
        $this->actingAs($owner);

        $device = $library->biometricDevices()->create(['name' => 'Main gate', 'serial' => $serial]);
        $token = $device->issueToken();

        $student = Student::create(['name' => 'Asha', 'phone' => '9'.random_int(100000000, 999999999), 'biometric_id' => $pin]);
        app(AssignMembership::class)($student, MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 1000, 'status' => 'active']), today());

        return [$library, $device, $student, $owner];
    }

    protected function push(string $serial, string $lines): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/iclock/cdata?SN='.$serial.'&table=ATTLOG&Stamp=1', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $lines);
    }

    public function test_a_known_machine_gets_its_settings_and_an_unknown_one_is_refused(): void
    {
        $this->setUpLibrary();

        $this->get('/iclock/cdata?SN=ZK-001&options=all')->assertOk()->assertSee('Realtime=1');
        $this->get('/iclock/getrequest?SN=zk-001')->assertOk()->assertSee('OK');   // serial match ignores case
        $this->get('/iclock/cdata?SN=NOPE')->assertNotFound();
        $this->push('NOPE', "101\t".now()->format('Y-m-d H:i:s')."\t0\t1\n")->assertNotFound();
        $this->assertNotNull(BiometricDevice::first()->last_seen_at);
        $this->assertSame(0, BiometricPunch::count());
    }

    public function test_punches_check_a_student_in_then_out_and_repeats_are_ignored(): void
    {
        [, , $student] = $this->setUpLibrary();
        Carbon::setTestNow(today()->setTime(10, 0));

        $in = today()->setTime(9, 0)->format('Y-m-d H:i:s');
        $this->push('ZK-001', "101\t{$in}\t0\t1\t0\n")->assertOk()->assertSee('OK: 1');

        $a = Attendance::first();
        $this->assertSame([$student->id, 'biometric', null], [$a->student_id, $a->source, $a->checked_in_by]);
        $this->assertSame('09:00', $a->checked_in_at->format('H:i'));
        $this->assertNull($a->checked_out_at);

        // same record sent again (the machine retries): still one punch, one visit
        $this->push('ZK-001', "101\t{$in}\t0\t1\t0\n");
        $this->assertSame(1, BiometricPunch::count());
        $this->assertSame(1, Attendance::count());

        // a double tap a minute later is not a check-out
        $tap = today()->setTime(9, 1)->format('Y-m-d H:i:s');
        $this->push('ZK-001', "101\t{$tap}\t0\t1\n");
        $this->assertNull(Attendance::first()->checked_out_at);
        $this->assertSame('ignored', BiometricPunch::latest('id')->first()->result);

        // a later punch checks out
        $out = today()->setTime(9, 45)->format('Y-m-d H:i:s');
        $this->push('ZK-001', "101\t{$out}\t1\t1\n");
        $this->assertSame('09:45', Attendance::first()->fresh()->checked_out_at->format('H:i'));
        $this->assertSame(['checked_in', 'ignored', 'checked_out'], BiometricPunch::orderBy('id')->pluck('result')->all());

        // and the next one starts a new visit
        Carbon::setTestNow(today()->setTime(18, 0));
        $again = today()->setTime(17, 0)->format('Y-m-d H:i:s');
        $this->push('ZK-001', "101\t{$again}\t0\t1\n");
        $this->assertSame(2, Attendance::count());
    }

    public function test_unknown_ids_blocked_students_and_old_punches_are_logged_but_change_nothing(): void
    {
        [, , $student] = $this->setUpLibrary();
        Carbon::setTestNow(today()->setTime(12, 0));
        $t = fn ($h, $d = 0) => today()->subDays($d)->setTime($h, 0)->format('Y-m-d H:i:s');

        $this->push('ZK-001', "999\t{$t(9)}\t0\t1\n");                       // nobody has 999
        $this->push('ZK-001', "101\t{$t(8, 2)}\t0\t1\n");                    // two days ago
        $this->push('ZK-001', "101\t".now()->addHours(3)->format('Y-m-d H:i:s')."\t0\t1\n");   // clock ahead
        $student->update(['status' => Student::SUSPENDED]);
        $this->push('ZK-001', "101\t{$t(10)}\t0\t1\n");                      // blocked

        $this->assertSame(0, Attendance::count());
        $this->assertSame(['unknown', 'ignored', 'rejected', 'rejected'], BiometricPunch::orderBy('id')->pluck('result')->all());
    }

    public function test_the_json_endpoint_needs_a_valid_token_and_reports_each_result(): void
    {
        [, $device] = $this->setUpLibrary();
        $token = $device->issueToken();
        Carbon::setTestNow(today()->setTime(11, 0));

        $this->postJson('/api/biometric/punches', ['pin' => '101'])->assertUnauthorized();
        $this->postJson('/api/biometric/punches', ['pin' => '101'], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

        $this->postJson('/api/biometric/punches', ['punches' => [['pin' => '101', 'time' => today()->setTime(10, 0)->format('Y-m-d H:i:s')], ['pin' => '555']]], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('results.0.result', 'checked_in')
            ->assertJsonPath('results.1.result', 'unknown');

        $this->assertSame(1, Attendance::count());
    }

    public function test_a_device_only_reaches_its_own_librarys_students(): void
    {
        [$a, $deviceA] = $this->setUpLibrary('a@example.com', 'ZK-A', '101');
        [$b, , $studentB] = $this->setUpLibrary('b@example.com', 'ZK-B', '101');   // same ID, other library
        Carbon::setTestNow(today()->setTime(10, 0));

        $this->push('ZK-A', "101\t".today()->setTime(9, 0)->format('Y-m-d H:i:s')."\t0\t1\n");

        Tenancy::set($a);
        $this->assertSame(1, Attendance::count());
        Tenancy::set($b);
        $this->assertSame(0, Attendance::count());
        $this->assertNull(Attendance::where('student_id', $studentB->id)->first());
    }

    public function test_a_switched_off_device_changes_nothing_and_manual_check_in_still_works(): void
    {
        [, $device, $student] = $this->setUpLibrary();
        Carbon::setTestNow(today()->setTime(10, 0));
        $device->update(['is_active' => false]);

        $this->push('ZK-001', "101\t".today()->setTime(9, 0)->format('Y-m-d H:i:s')."\t0\t1\n");
        $this->assertSame(0, Attendance::count());
        $this->assertSame('rejected', BiometricPunch::first()->result);

        $a = app(CheckInStudent::class)($student);
        $this->assertSame(['manual', auth()->id()], [$a->source, $a->checked_in_by]);
    }

    public function test_the_devices_page_adds_a_device_shows_the_token_once_and_rejects_a_duplicate_serial(): void
    {
        [$library, , , $owner] = $this->setUpLibrary();
        $this->actingAs($owner);

        Livewire::test('pages::app.biometric.index')
            ->assertSee('Main gate')
            ->call('create')->set('name', 'Back door')->set('serial', 'zk-002')->call('save')
            ->assertHasNoErrors()
            ->assertSee('Copy it now')
            ->call('dismissToken')->assertDontSee('Copy it now')
            ->call('create')->set('name', 'Dup')->set('serial', 'ZK-001')->call('save')
            ->assertHasErrors('serial');

        $this->assertSame(2, $library->biometricDevices()->count());
        $this->assertNotNull(BiometricDevice::where('serial', 'zk-002')->value('token_hash'));
    }

    public function test_two_students_cannot_share_a_biometric_id(): void
    {
        [, , $student, $owner] = $this->setUpLibrary();
        $other = Student::create(['name' => 'Ravi', 'phone' => '9000000099']);
        $this->actingAs($owner);

        Livewire::test('pages::app.students.form', ['student' => $other->id])
            ->set('biometric_id', '101')->call('save')->assertHasErrors('biometric_id');
        Livewire::test('pages::app.students.form', ['student' => $other->id])
            ->set('biometric_id', '102')->call('save')->assertHasNoErrors();

        $this->assertSame('102', $other->fresh()->biometric_id);
    }
}
