<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\MembershipPlan;
use App\Models\Student;
use App\Models\StudentMembership;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AssignMembership
{
    /**
     * Creates a membership. Dates come from the plan; price/fee are snapshotted so later plan
     * edits never change what a student already bought. It does NOT mark anything as paid.
     */
    public function __invoke(Student $student, MembershipPlan $plan, CarbonInterface $startsOn, string|float $discount = 0, ?string $notes = null): StudentMembership
    {
        $discount = (string) number_format((float) $discount, 2, '.', '');

        if ($student->status !== Student::ACTIVE) {
            throw new BusinessRuleException("{$student->name} is {$student->status}; reactivate the student first.");
        }
        if ($plan->status !== 'active') {
            throw new BusinessRuleException("Plan {$plan->name} is not active.");
        }
        if (bccomp($discount, '0', 2) < 0) {
            throw new BusinessRuleException('Discount cannot be negative.');
        }
        if (bccomp($discount, '0', 2) > 0 && ! (auth()->user()?->can('memberships.discount') ?? false)) {
            throw new BusinessRuleException('You are not allowed to give a discount.');
        }
        if (bccomp($discount, bcadd((string) $plan->price, (string) $plan->registration_fee, 2), 2) > 0) {
            throw new BusinessRuleException('Discount cannot exceed the plan price plus registration fee.');
        }

        $startsOn = $startsOn->copy()->startOfDay();
        $endsOn = $plan->endDateFor($startsOn);

        return DB::transaction(function () use ($student, $plan, $startsOn, $endsOn, $discount, $notes) {
            // Serialise memberships per student so two clerks cannot create overlapping periods.
            Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

            $overlaps = StudentMembership::query()
                ->where('student_id', $student->id)
                ->whereIn('status', ['active', 'suspended'])
                ->whereDate('starts_on', '<=', $endsOn)
                ->whereDate('ends_on', '>=', $startsOn)
                ->exists();

            if ($overlaps) {
                throw new BusinessRuleException('This period overlaps an existing membership. Renew from its end date instead.');
            }

            // Registration fee only on a student's first membership.
            $fee = $student->memberships()->exists() ? '0.00' : (string) $plan->registration_fee;

            $membership = StudentMembership::create([
                'student_id' => $student->id,
                'membership_plan_id' => $plan->id,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'price' => $plan->price,
                'registration_fee' => $fee,
                'discount' => $discount,
                'status' => 'active',
                'notes' => $notes,
            ]);
            $membership->forceFill(['created_by' => auth()->id()])->save();

            AuditLog::record('membership.created', $membership, [
                'student' => $student->student_code,
                'plan' => $plan->name,
                'discount' => $discount,
            ]);

            return $membership;
        });
    }
}
