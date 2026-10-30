<?php

namespace Tests\Feature\Api;

use App\Models\Seat;
use App\Models\Student;
use App\Models\StudyHall;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class StudentsSeatsApiTest extends ApiTestCase
{
    protected function seats(int $n = 3): array
    {
        return $this->inLibrary(function () use ($n) {
            $hall = StudyHall::firstOrCreate(['name' => 'Hall A'], ['status' => 'active']);

            return collect(range(1, $n))->map(fn ($i) => Seat::create(['study_hall_id' => $hall->id, 'code' => 'A-0'.$i]))->all();
        });
    }

    public function test_register_list_filter_show_and_edit_a_student(): void
    {
        Storage::fake('public');
        $t = $this->ownerToken();

        $res = $this->as($t)->post($this->lib('students'), ['name' => 'Rohit Kumar', 'phone' => '9876543210', 'biometric_id' => '114', 'is_vip' => '1', 'photo' => UploadedFile::fake()->image('p.jpg')])
            ->assertCreated()->assertJsonPath('data.student.name', 'Rohit Kumar')->assertJsonPath('data.student.is_vip', true)->assertJsonPath('data.can_choose_seat', true);
        $id = $res->json('data.student.id');
        $this->assertNotNull($res->json('data.student.photo_url'));

        // same phone: refused; same name: asks to confirm
        $this->as($t)->postJson($this->lib('students'), ['name' => 'Someone', 'phone' => '9876543210'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->as($t)->postJson($this->lib('students'), ['name' => 'rohit kumar', 'phone' => '9000000001'])->assertStatus(409)->assertJsonPath('code', 'possible_duplicate');
        $this->as($t)->postJson($this->lib('students'), ['name' => 'rohit kumar', 'phone' => '9000000001', 'confirm_duplicate' => true])->assertCreated();
        $this->as($t)->postJson($this->lib('students'), ['name' => 'X', 'phone' => '9000000002', 'biometric_id' => '114'])->assertStatus(422)->assertJsonValidationErrors('biometric_id');

        $this->as($t)->getJson($this->lib('students?view=vip'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('counts.total', 2)->assertJsonPath('counts.vip', 1);
        $this->as($t)->getJson($this->lib('students?q=rohit&from='.today()->toDateString()))->assertOk()->assertJsonCount(2, 'data');
        $this->as($t)->getJson($this->lib('students?from=2001-01-01&until=2001-12-31'))->assertOk()->assertJsonCount(0, 'data');

        $this->as($t)->getJson($this->lib("students/{$id}"))->assertOk()->assertJsonPath('data.student.biometric_id', '114')->assertJsonPath('data.actions.allot_seat', true);
        $this->as($t)->putJson($this->lib("students/{$id}"), ['name' => 'Rohit K', 'phone' => '9876543210', 'email' => 'r@k.test'])->assertOk()->assertJsonPath('data.email', 'r@k.test');
    }

    public function test_students_of_another_library_are_invisible(): void
    {
        $beta = $this->makeLibrary('Beta', 'owner@beta.test');
        $foreign = $this->student([], $beta);
        $t = $this->ownerToken();
        $this->as($t)->getJson($this->lib("students/{$foreign->id}"))->assertNotFound();
        $this->as($t)->putJson($this->lib("students/{$foreign->id}"), ['name' => 'Hack', 'phone' => '1'])->assertNotFound();
    }

    public function test_permissions_are_checked_on_every_endpoint(): void
    {
        $this->staff($this->roleName('SUPERVISOR'), 'floor@alpha.test');
        $floor = $this->login('floor@alpha.test');
        $s = $this->student();
        $this->as($floor)->getJson($this->lib('students'))->assertForbidden();
        $this->as($floor)->postJson($this->lib('students'), ['name' => 'A', 'phone' => '1234567890'])->assertForbidden();
        $this->as($floor)->postJson($this->lib("students/{$s->id}/status"), ['status' => 'inactive'])->assertForbidden();
        $this->as($floor)->getJson($this->lib('seats'))->assertOk();   // supervisors may look at seats
        $this->as($floor)->postJson($this->lib('seats/generate'), ['hall_id' => 1, 'start' => 1, 'count' => 2, 'type' => 'standard'])->assertForbidden();
    }

    public function test_seat_map_by_shift_allocation_sharing_and_release(): void
    {
        [$s1, $s2] = $this->seats(2);
        $morning = $this->member();
        $evening = $this->member();
        $t = $this->ownerToken();

        $this->as($t)->postJson($this->lib("seats/{$s1->id}/allocate"), ['student_id' => $morning->id, 'shift' => 'morning'])->assertCreated()->assertJsonPath('data.shift', 'morning');
        $this->as($t)->postJson($this->lib("seats/{$s1->id}/allocate"), ['student_id' => $evening->id, 'shift' => 'evening'])->assertCreated();
        // the same shift twice is refused with a readable message
        $third = $this->member();
        $this->as($t)->postJson($this->lib("seats/{$s1->id}/allocate"), ['student_id' => $third->id, 'shift' => 'morning'])->assertStatus(422)->assertJsonStructure(['message']);

        $map = $this->as($t)->getJson($this->lib('seats?shift=morning'))->assertOk();
        $this->assertSame('occupied', collect($map->json('data.seats'))->firstWhere('id', $s1->id)['state']);
        $this->assertSame(1, $map->json('data.summary.allotted'));
        $night = $this->as($t)->getJson($this->lib('seats?shift=night'))->json('data.seats');
        $this->assertSame('available', collect($night)->firstWhere('id', $s1->id)['state']);
        $this->assertSame('occupied', collect($this->as($t)->getJson($this->lib('seats?shift=fullday'))->json('data.seats'))->firstWhere('id', $s1->id)['state']);

        $this->as($t)->postJson($this->lib("seats/{$s1->id}/release"), ['shift' => 'morning'])->assertOk();
        $this->assertSame('occupied', $s1->fresh()->status, 'the evening student still holds it');
        $this->as($t)->postJson($this->lib("seats/{$s1->id}/release"), ['shift' => 'morning'])->assertNotFound();
        $this->as($t)->getJson($this->lib("seats/{$s1->id}?shift=evening"))->assertOk()->assertJsonPath('data.holder.student.id', $evening->id)->assertJsonCount(2, 'data.history');
    }

    public function test_seat_selection_after_registering_starts_the_plan_and_moves_seats(): void
    {
        [$s1, $s2] = $this->seats(2);
        $plan = $this->plan();
        $t = $this->ownerToken();
        $new = $this->student();

        // no running membership: a plan is required
        $this->as($t)->postJson($this->lib("students/{$new->id}/seat"), ['seat_id' => $s1->id, 'shift' => 'fullday'])->assertStatus(422)->assertJsonValidationErrors('plan_id');
        $this->as($t)->postJson($this->lib("students/{$new->id}/seat"), ['seat_id' => $s1->id, 'shift' => 'fullday', 'plan_id' => $plan->id, 'starts_on' => today()->toDateString()])
            ->assertCreated()->assertJsonPath('data.seat', 'A-01');
        $this->assertNotNull($this->inLibrary(fn () => $new->fresh()->currentMembership()));

        // change seat: the old one is released and kept in history
        $this->as($t)->postJson($this->lib("students/{$new->id}/seat"), ['seat_id' => $s2->id, 'shift' => 'morning'])->assertCreated()->assertJsonPath('message', "{$new->name} moved from seat A-01 to A-02.");
        $this->assertSame('available', $s1->fresh()->status);
        $this->as($t)->deleteJson($this->lib("students/{$new->id}/seat"))->assertOk();
        $this->as($t)->getJson($this->lib("students/{$new->id}"))->assertOk()->assertJsonCount(2, 'data.seat_history')->assertJsonPath('data.student.seat', null);
    }

    public function test_leaving_frees_the_seat_and_halls_and_seats_can_be_managed(): void
    {
        $t = $this->ownerToken();
        $hall = $this->as($t)->postJson($this->lib('halls'), ['name' => 'Silent Hall', 'status' => 'active', 'opening_time' => '08:00', 'closing_time' => '20:00'])->assertCreated()->json('data.id');
        $this->as($t)->postJson($this->lib('halls'), ['name' => 'Silent Hall', 'status' => 'active'])->assertStatus(422);
        $this->as($t)->postJson($this->lib('seats/generate'), ['hall_id' => $hall, 'prefix' => 'S-', 'start' => 1, 'count' => 3, 'type' => 'standard'])->assertCreated()->assertJsonCount(3, 'data.created');
        $this->as($t)->postJson($this->lib('seats/generate'), ['hall_id' => $hall, 'prefix' => 'S-', 'start' => 2, 'count' => 3, 'type' => 'standard'])->assertCreated()->assertJsonCount(1, 'data.created');
        $seatId = $this->as($t)->postJson($this->lib('seats'), ['hall_id' => $hall, 'code' => 'VIP-1', 'type' => 'cabin', 'status' => 'reserved'])->assertCreated()->json('data.id');
        $this->as($t)->putJson($this->lib("seats/{$seatId}"), ['hall_id' => $hall, 'code' => 'VIP-1', 'type' => 'cabin', 'status' => 'maintenance'])->assertOk()->assertJsonPath('data.status', 'maintenance');

        $m = $this->member();
        $seat = $this->inLibrary(fn () => Seat::where('code', 'S-01')->first());
        $this->as($t)->postJson($this->lib("seats/{$seat->id}/allocate"), ['student_id' => $m->id, 'shift' => 'fullday'])->assertCreated();
        $this->as($t)->postJson($this->lib("students/{$m->id}/status"), ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->assertSame('available', $seat->fresh()->status);
    }
}
