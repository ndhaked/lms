<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLibrary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentMembership extends Model
{
    use BelongsToLibrary;

    protected $fillable = [
        'student_id', 'membership_plan_id', 'starts_on', 'ends_on', 'price', 'registration_fee',
        'discount', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'price' => 'decimal:2',
            'registration_fee' => 'decimal:2',
            'discount' => 'decimal:2',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    /** Total charge for this membership (price + registration fee - discount). Not a payment status. */
    public function totalDue(): string
    {
        return bcsub(bcadd((string) $this->price, (string) $this->registration_fee, 2), (string) $this->discount, 2);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** Money received for this membership: completed payments minus refunds. Reversed payments never count. */
    public function paidAmount(): string
    {
        $paid = PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.student_membership_id', $this->id)
            ->where('payments.status', Payment::COMPLETED)
            ->sum('payment_allocations.amount');

        $refunded = $this->refunds()->sum('amount');

        return bcsub(number_format((float) $paid, 2, '.', ''), number_format((float) $refunded, 2, '.', ''), 2);
    }

    /** What the student still owes (never negative). */
    public function outstanding(): string
    {
        $due = bcsub($this->totalDue(), $this->paidAmount(), 2);

        return bccomp($due, '0', 2) > 0 ? $due : '0.00';
    }

    /** SQL for "amount paid" so lists can compute balances without a query per row. */
    public const PAID_SQL = "(coalesce((select sum(pa.amount) from payment_allocations pa join payments p on p.id = pa.payment_id
        where pa.student_membership_id = student_memberships.id and p.status = 'completed'), 0)
        - coalesce((select sum(r.amount) from refunds r where r.student_membership_id = student_memberships.id), 0))";

    public const CHARGE_SQL = '(student_memberships.price + student_memberships.registration_fee - student_memberships.discount)';

    public function scopeWithBalance(Builder $q): Builder
    {
        return $q->addSelect([
            'student_memberships.*',
            \Illuminate\Support\Facades\DB::raw(self::CHARGE_SQL.' as charge'),
            \Illuminate\Support\Facades\DB::raw(self::PAID_SQL.' as paid'),
            \Illuminate\Support\Facades\DB::raw('('.self::CHARGE_SQL.' - '.self::PAID_SQL.') as balance'),
        ]);
    }

    /** Memberships that still owe money (cancelled ones are not chased). */
    public function scopeOwing(Builder $q): Builder
    {
        return $q->where('student_memberships.status', '!=', 'cancelled')
            ->whereRaw('('.self::CHARGE_SQL.' - '.self::PAID_SQL.') > 0');
    }

    /** active | upcoming | expired | suspended | cancelled, derived from status and dates. */
    public function effectiveStatus(): string
    {
        if (in_array($this->status, ['suspended', 'cancelled'], true)) {
            return $this->status;
        }
        if ($this->starts_on->isFuture()) {
            return 'upcoming';
        }

        return $this->ends_on->lt(today()) ? 'expired' : 'active';
    }

    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where('status', 'active')->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today());
    }

    public function scopeExpiringWithin(Builder $q, int $days): Builder
    {
        return $q->where('status', 'active')->whereBetween('ends_on', [today(), today()->addDays($days)]);
    }
}
