<?php

namespace Tests\Feature\Api;

use App\Actions\RecordPayment;
use App\Models\PaymentSubmission;
use App\Models\User;
use App\Support\DigitalId;
use App\Support\LibrarySettings;
use Illuminate\Support\Facades\Notification;

class StudentAppApiTest extends ApiTestCase
{
    public function test_student_login_opens_the_student_workspace_and_staff_endpoints_are_closed(): void
    {
        [$student, $user] = $this->studentLogin();
        $res = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123', 'device_name' => 'Phone'])->assertOk();
        $this->assertSame('student', $res->json('libraries.0.workspace'));
        $t = $res->json('token');

        $this->as($t)->getJson($this->lib('students'))->assertForbidden();
        $this->as($t)->getJson($this->lib('dashboard'))->assertForbidden();
        $this->as($t)->getJson($this->lib('payments'))->assertForbidden();
        $this->as($t)->getJson($this->lib('my'))->assertOk()->assertJsonPath('data.student.id', $student->id)->assertJsonPath('data.membership.balance', 1200);
    }

    public function test_digital_id_rotates_and_works_at_the_scanner(): void
    {
        [$student, $user] = $this->studentLogin();
        $t = $this->login($user->email);
        $token = $this->as($t)->getJson($this->lib('my/id'))->assertOk()->assertJsonPath('data.ttl', DigitalId::TTL_SECONDS)->json('data.token');

        $owner = $this->ownerToken();
        $this->as($owner)->postJson($this->lib('attendance/scan'), ['token' => $token])->assertOk()->assertJsonPath('data.student.id', $student->id);
        $this->as($t)->getJson($this->lib('my'))->assertJsonPath('data.today.source', 'manual');
        $this->as($t)->getJson($this->lib('my/attendance'))->assertOk()->assertJsonPath('data.summary.visits', 1);
    }

    public function test_renewal_request_once_and_payments_with_own_receipt_only(): void
    {
        [$student, $user] = $this->studentLogin();
        $t = $this->login($user->email);
        $this->as($t)->postJson($this->lib('my/renewal-request'), ['message' => 'Renew please'])->assertCreated();
        $this->as($t)->postJson($this->lib('my/renewal-request'), [])->assertStatus(422);
        $this->as($t)->getJson($this->lib('my/memberships'))->assertOk()->assertJsonPath('data.pending_request.message', 'Renew please');

        $other = $this->member();
        $this->actingAs($this->owner->fresh());
        $payment = $this->inLibrary(fn () => app(RecordPayment::class)($student, 500, 'cash', today()));
        $foreign = $this->inLibrary(fn () => app(RecordPayment::class)($other, 100, 'cash', today()));
        $this->app['auth']->forgetGuards();

        $this->as($t)->getJson($this->lib('my/payments'))->assertOk()->assertJsonPath('data.dues', 700)->assertJsonCount(1, 'data.payments');
        $this->as($t)->get($this->lib("my/payments/{$payment->id}/receipt"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->as($t)->get($this->lib("my/payments/{$foreign->id}/receipt"))->assertNotFound();
    }

    public function test_i_have_paid_follows_the_limits(): void
    {
        [$student, $user] = $this->studentLogin();
        $t = $this->login($user->email);
        $m = $this->inLibrary(fn () => $student->currentMembership());

        $this->as($t)->postJson($this->lib('my/payment-submissions'), ['membership_id' => $m->id, 'amount' => 100, 'method' => 'upi', 'reference' => 'U1', 'paid_on' => today()->toDateString()])->assertForbidden();
        $this->inLibrary(fn () => LibrarySettings::update(['student_payment_submissions' => true]));

        $this->as($t)->postJson($this->lib('my/payment-submissions'), ['membership_id' => $m->id, 'amount' => 5000, 'method' => 'upi', 'reference' => 'U1', 'paid_on' => today()->toDateString()])->assertStatus(422);
        $this->as($t)->postJson($this->lib('my/payment-submissions'), ['membership_id' => $m->id, 'amount' => 300, 'method' => 'upi', 'reference' => 'U1', 'paid_on' => today()->toDateString()])->assertCreated();
        $this->as($t)->postJson($this->lib('my/payment-submissions'), ['membership_id' => $m->id, 'amount' => 100, 'method' => 'upi', 'reference' => 'U1', 'paid_on' => today()->toDateString()])->assertStatus(422);   // same reference
        // someone else's membership id cannot be used
        $other = $this->member();
        $om = $this->inLibrary(fn () => $other->currentMembership());
        $this->as($t)->postJson($this->lib('my/payment-submissions'), ['membership_id' => $om->id, 'amount' => 100, 'method' => 'upi', 'reference' => 'U9', 'paid_on' => today()->toDateString()])->assertStatus(422);
        $this->assertSame(1, PaymentSubmission::count());
    }

    public function test_staff_management_rules(): void
    {
        Notification::fake();
        $t = $this->ownerToken();
        $id = $this->as($t)->postJson($this->lib('staff'), ['name' => 'Desk', 'email' => 'desk@alpha.test', 'roles' => ['Receptionist']])->assertCreated()->json('data.id');
        $this->as($t)->postJson($this->lib('staff'), ['name' => 'Desk', 'email' => 'desk@alpha.test', 'roles' => ['Receptionist']])->assertStatus(422);
        $this->as($t)->getJson($this->lib('staff'))->assertOk()->assertJsonCount(2, 'data.staff');
        $this->as($t)->postJson($this->lib("staff/{$this->owner->id}/access"), ['active' => false])->assertStatus(422);   // not yourself
        $this->as($t)->postJson($this->lib("staff/{$id}/access"), ['active' => false])->assertOk();

        // a manager cannot hand out the Owner role
        $mgr = $this->staff($this->roleName('MANAGER'), 'mgr@alpha.test');
        $this->inLibrary(fn () => \Spatie\Permission\Models\Role::where('name', 'Library Manager')->where('library_id', $this->library->id)->firstOrFail()->givePermissionTo('staff.manage'));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $m = $this->login('mgr@alpha.test');
        $this->as($m)->postJson($this->lib('staff'), ['name' => 'X', 'email' => 'x@alpha.test', 'roles' => ['Library Owner']])->assertStatus(422);
    }

    public function test_settings_profile_and_general(): void
    {
        $t = $this->ownerToken();
        $this->as($t)->getJson($this->lib('settings'))->assertOk()->assertJsonPath('data.profile.name', 'Alpha');
        $this->as($t)->post($this->lib('settings/profile'), ['name' => 'Alpha Library', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR', 'opening_time' => '08:00', 'closing_time' => '22:00'])->assertOk();
        $this->as($t)->putJson($this->lib('settings/general'), ['renewal_window_days' => 10, 'seat_prefix' => 'B-', 'working_days' => [1, 2, 3, 4, 5]])->assertOk();
        $this->as($t)->getJson($this->lib('settings'))->assertJsonPath('data.profile.name', 'Alpha Library')->assertJsonPath('data.general.renewal_window_days', 10)->assertJsonPath('data.general.seat_prefix', 'B-');
        $this->as($t)->putJson($this->lib('settings/general'), ['seat_padding' => 9])->assertStatus(422);
    }
}
