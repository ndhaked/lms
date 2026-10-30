<?php

namespace Tests\Feature\Api;

use App\Models\ExpenseCategory;
use App\Support\DigitalId;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class OperationsApiTest extends ApiTestCase
{
    public function test_check_in_out_history_and_correction(): void
    {
        $s = $this->member();
        $t = $this->ownerToken();

        $this->as($t)->getJson($this->lib('attendance/candidates?q='.substr($s->name, 0, 6)))->assertOk()->assertJsonPath('data.0.id', $s->id);
        $a = $this->as($t)->postJson($this->lib('attendance/check-in'), ['student_id' => $s->id])->assertCreated()->assertJsonPath('data.source', 'manual')->json('data.id');
        $this->as($t)->postJson($this->lib('attendance/check-in'), ['student_id' => $s->id])->assertStatus(422);
        $this->as($t)->getJson($this->lib('attendance/today'))->assertOk()->assertJsonPath('data.summary.inside', 1);
        $this->as($t)->postJson($this->lib("attendance/{$a}/check-out"))->assertOk();
        $this->as($t)->getJson($this->lib('attendance'))->assertOk()->assertJsonCount(1, 'data');

        $this->as($t)->postJson($this->lib("attendance/{$a}/correct"), ['checked_in_at' => now()->subHours(3)->toDateTimeString(), 'checked_out_at' => now()->subHour()->toDateTimeString()])->assertStatus(422);
        $this->as($t)->postJson($this->lib("attendance/{$a}/correct"), ['checked_in_at' => now()->subHours(3)->toDateTimeString(), 'checked_out_at' => now()->subHour()->toDateTimeString(), 'reason' => 'Forgot'])->assertOk()->assertJsonPath('data.minutes', 120);
    }

    public function test_scanning_the_digital_id_checks_in_then_out_and_expired_or_foreign_codes_fail(): void
    {
        $s = $this->member();
        $t = $this->ownerToken();
        $token = $this->inLibrary(fn () => DigitalId::make($s)['token']);

        $this->as($t)->postJson($this->lib('attendance/scan'), ['token' => $token, 'mode' => 'lookup'])->assertOk()->assertJsonPath('data.student.id', $s->id);
        $this->as($t)->postJson($this->lib('attendance/scan'), ['token' => $token])->assertOk()->assertJsonPath('data.action', 'checked_in');
        $this->travel(5)->minutes();
        $this->as($t)->postJson($this->lib('attendance/scan'), ['token' => $token])->assertStatus(422);   // expired
        $fresh = $this->inLibrary(fn () => DigitalId::make($s)['token']);
        $this->as($t)->postJson($this->lib('attendance/scan'), ['token' => $fresh])->assertOk()->assertJsonPath('data.action', 'checked_out');

        $this->as($t)->postJson($this->lib('attendance/scan'), ['token' => $fresh.'x'])->assertStatus(422);   // tampered
        $beta = $this->makeLibrary('Beta', 'owner@beta.test');
        $foreign = $this->student([], $beta);
        $other = $this->inLibrary(fn () => DigitalId::make($foreign)['token'], $beta);
        $this->as($t)->postJson($this->lib('attendance/scan'), ['token' => $other])->assertStatus(422);
    }

    public function test_biometric_devices_and_punch_log(): void
    {
        $t = $this->ownerToken();
        $res = $this->as($t)->postJson($this->lib('biometric/devices'), ['name' => 'Main gate', 'serial' => 'ZK-77'])->assertCreated();
        $this->assertStringStartsWith('bio_', $res->json('data.access_token'));
        $id = $res->json('data.id');
        $this->as($t)->getJson($this->lib('biometric/devices'))->assertOk()->assertJsonMissingPath('data.devices.0.access_token')->assertJsonPath('data.devices.0.serial', 'ZK-77');

        // the machine pushes a punch; it shows in the log
        $this->member(['biometric_id' => '7']);
        Carbon::setTestNow(today()->setTime(10, 0));
        $this->call('POST', '/iclock/cdata?SN=ZK-77&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "7\t".today()->setTime(9, 30)->format('Y-m-d H:i:s')."\t0\t1\n")->assertOk();
        $this->as($t)->getJson($this->lib('biometric/punches'))->assertOk()->assertJsonPath('data.0.result', 'checked_in');
        $this->as($t)->getJson($this->lib('attendance/today'))->assertJsonPath('data.inside.0.source', 'biometric');

        $this->as($t)->putJson($this->lib("biometric/devices/{$id}"), ['name' => 'Main gate', 'serial' => 'ZK-77', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->as($t)->postJson($this->lib("biometric/devices/{$id}/token"))->assertOk();
        $this->staff($this->roleName('RECEPTIONIST'), 'desk@alpha.test');
        $desk = $this->login('desk@alpha.test');
        $this->as($desk)->deleteJson($this->lib("biometric/devices/{$id}"))->assertForbidden();
        $this->as($t)->deleteJson($this->lib("biometric/devices/{$id}"))->assertOk();
    }

    public function test_expenses_with_private_attachments(): void
    {
        Storage::fake('local');
        $t = $this->ownerToken();
        $cat = $this->as($t)->postJson($this->lib('expense-categories'), ['name' => 'Electricity'])->assertCreated()->json('data.id');
        $id = $this->as($t)->post($this->lib('expenses'), ['category_id' => $cat, 'description' => 'Bill', 'amount' => 2500, 'expense_date' => today()->toDateString(), 'method' => 'upi', 'attachment' => UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf')])
            ->assertCreated()->assertJsonPath('data.has_attachment', true)->json('data.id');
        $this->as($t)->getJson($this->lib('expenses'))->assertOk()->assertJsonPath('month_total', 2500);
        $this->as($t)->get($this->lib("expenses/{$id}/attachment"))->assertOk();
        $this->as($t)->postJson($this->lib("expenses/{$id}"), ['category_id' => $cat, 'description' => 'Bill Oct', 'amount' => 2600, 'expense_date' => today()->toDateString(), 'method' => 'cash'])->assertOk()->assertJsonPath('data.amount', 2600);

        $beta = $this->makeLibrary('Beta', 'owner@beta.test');
        $foreignCat = $this->inLibrary(fn () => ExpenseCategory::create(['name' => 'X']), $beta);
        $this->as($t)->postJson($this->lib('expenses'), ['category_id' => $foreignCat->id, 'description' => 'x', 'amount' => 1, 'expense_date' => today()->toDateString(), 'method' => 'cash'])->assertStatus(422);
    }

    public function test_reports_list_view_and_export(): void
    {
        $this->member();
        $t = $this->ownerToken();
        $keys = collect($this->as($t)->getJson($this->lib('reports'))->assertOk()->json('data'))->pluck('key');
        $this->assertTrue($keys->contains('student-registration'));
        $this->as($t)->getJson($this->lib('reports/student-registration?from=2000-01-01'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonStructure(['data' => ['headers', 'rows', 'summary']]);
        $this->as($t)->get($this->lib('reports/student-registration/export/csv?from=2000-01-01'))->assertOk();
        $this->as($t)->getJson($this->lib('reports/nope'))->assertNotFound();
    }
}
