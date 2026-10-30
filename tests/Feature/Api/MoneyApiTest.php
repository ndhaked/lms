<?php

namespace Tests\Feature\Api;

use App\Models\PaymentSubmission;
use App\Models\RenewalRequest;

class MoneyApiTest extends ApiTestCase
{
    public function test_plans_and_memberships_assign_renew_freeze(): void
    {
        $t = $this->ownerToken();
        $planId = $this->as($t)->postJson($this->lib('plans'), ['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 1300, 'registration_fee' => 200, 'seat_type' => 'flexible', 'grace_days' => 3, 'status' => 'active', 'shift_start' => '08:00', 'shift_end' => '14:00'])
            ->assertCreated()->json('data.id');
        $this->as($t)->postJson($this->lib('plans'), ['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 1, 'registration_fee' => 0, 'seat_type' => 'flexible', 'grace_days' => 0, 'status' => 'active'])->assertStatus(422);
        $this->as($t)->getJson($this->lib('plans?active_only=1'))->assertOk()->assertJsonPath('data.0.price', 1300);

        $s = $this->student();
        $m = $this->as($t)->postJson($this->lib('memberships'), ['student_id' => $s->id, 'plan_id' => $planId, 'starts_on' => today()->toDateString(), 'discount' => 100])
            ->assertCreated()->assertJsonPath('data.total', 1400)->assertJsonPath('data.balance', 1400)->json('data.id');
        // overlapping period: refused with the rule's message
        $this->as($t)->postJson($this->lib('memberships'), ['student_id' => $s->id, 'plan_id' => $planId, 'starts_on' => today()->toDateString()])->assertStatus(422)->assertJsonStructure(['message']);

        $this->as($t)->postJson($this->lib("memberships/{$m}/renew"))->assertCreated()->assertJsonPath('data.registration_fee', 0);
        $this->as($t)->getJson($this->lib("memberships?student_id={$s->id}"))->assertOk()->assertJsonCount(2, 'data');
        $this->as($t)->putJson($this->lib("memberships/{$m}"), ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->as($t)->getJson($this->lib('students?view=frozen'))->assertJsonCount(1, 'data');
    }

    public function test_receptionist_cannot_give_discounts_or_manage_plans(): void
    {
        $this->staff($this->roleName('RECEPTIONIST'), 'desk@alpha.test');
        $desk = $this->login('desk@alpha.test');
        $this->as($desk)->postJson($this->lib('plans'), ['name' => 'X'])->assertForbidden();
        $this->as($desk)->postJson($this->lib('memberships'), ['student_id' => 1, 'plan_id' => 1, 'starts_on' => today()->toDateString()])->assertForbidden();
    }

    public function test_record_payment_idempotency_receipt_dues_refund_reverse(): void
    {
        $s = $this->member();   // owes 1200
        $t = $this->ownerToken();
        $this->as($t)->getJson($this->lib('dues'))->assertOk()->assertJsonPath('total', 1200)->assertJsonCount(1, 'data');
        $this->as($t)->getJson($this->lib("students/{$s->id}/owing"))->assertOk()->assertJsonPath('data.0.balance', 1200);

        $body = ['student_id' => $s->id, 'amount' => 500, 'method' => 'upi', 'paid_on' => today()->toDateString(), 'reference' => 'UTR1'];
        $first = $this->as($t)->postJson($this->lib('payments'), $body + ['idempotency_key' => 'abc-1'])->assertCreated();
        $again = $this->as($t)->postJson($this->lib('payments'), $body + ['idempotency_key' => 'abc-1'])->assertOk();
        $this->assertSame($first->json('data.id'), $again->json('data.id'), 'a retried request does not pay twice');
        $pid = $first->json('data.id');

        // overpaying and future dates are refused
        $this->as($t)->postJson($this->lib('payments'), ['student_id' => $s->id, 'amount' => 5000, 'method' => 'cash', 'paid_on' => today()->toDateString()])->assertStatus(422);
        $this->as($t)->postJson($this->lib('payments'), ['student_id' => $s->id, 'amount' => 5, 'method' => 'cash', 'paid_on' => today()->addDay()->toDateString()])->assertStatus(422);

        $this->as($t)->getJson($this->lib('payments'))->assertOk()->assertJsonPath('summary.today', 500)->assertJsonPath('summary.dues', 700);
        $this->as($t)->getJson($this->lib("payments/{$pid}"))->assertOk()->assertJsonPath('data.allocations.0.amount', 500);
        $this->as($t)->get($this->lib("payments/{$pid}/receipt"))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $opt = $this->as($t)->getJson($this->lib("payments/{$pid}/refund-options"))->assertOk()->json('data.0');
        $this->as($t)->postJson($this->lib("payments/{$pid}/refund"), ['membership_id' => $opt['membership_id'], 'amount' => 100, 'method' => 'cash', 'reason' => 'Overcharged'])->assertCreated();
        $p2 = $this->as($t)->postJson($this->lib('payments'), ['student_id' => $s->id, 'amount' => 100, 'method' => 'cash', 'paid_on' => today()->toDateString()])->assertCreated()->json('data.id');
        $this->as($t)->postJson($this->lib("payments/{$p2}/reverse"), ['reason' => 'Wrong student'])->assertOk()->assertJsonPath('data.status', 'reversed');
    }

    public function test_payment_claims_from_students_are_approved_or_rejected_once(): void
    {
        [$student] = $this->studentLogin();
        $make = fn () => $this->inLibrary(fn () => PaymentSubmission::create(['student_id' => $student->id, 'amount' => 300, 'method' => 'upi', 'paid_on' => today(), 'reference' => 'UTR9', 'status' => 'pending']));
        $a = $make();
        $b = $make();
        $t = $this->ownerToken();

        $this->as($t)->getJson($this->lib('payment-submissions?status=pending'))->assertOk()->assertJsonPath('pending', 2);
        $this->as($t)->postJson($this->lib("payment-submissions/{$a->id}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->as($t)->postJson($this->lib("payment-submissions/{$a->id}/approve"))->assertStatus(422);
        $this->as($t)->postJson($this->lib("payment-submissions/{$b->id}/reject"), [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($t)->postJson($this->lib("payment-submissions/{$b->id}/reject"), ['reason' => 'No such UTR'])->assertOk()->assertJsonPath('data.review_note', 'No such UTR');
    }

    public function test_renewal_requests_can_be_answered_or_dismissed(): void
    {
        $s = $this->member();
        $r = $this->inLibrary(fn () => RenewalRequest::create(['student_id' => $s->id, 'message' => 'Please renew']));
        $t = $this->ownerToken();
        $this->as($t)->getJson($this->lib('renewal-requests'))->assertOk()->assertJsonPath('data.0.message', 'Please renew');
        $this->as($t)->postJson($this->lib("renewal-requests/{$r->id}/dismiss"))->assertOk();
        $this->as($t)->getJson($this->lib('renewal-requests'))->assertJsonCount(0, 'data');
    }
}
