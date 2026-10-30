<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordPayment;
use App\Actions\RecordRefund;
use App\Actions\ReversePayment;
use App\Actions\ReviewPaymentSubmission;
use App\Http\Resources\Api\V1\Present;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentSubmission;
use App\Models\Refund;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Support\Pdf;
use App\Support\PdfDocs;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Money: payments (record, refund, reverse, receipt PDF), outstanding dues, and the "I have paid" claims students send.
 * Recording uses the same RecordPayment rules as the web: no overpayment, oldest dues first, and an idempotency key so a
 * retried request from a phone on a bad connection never records the payment twice.
 */
class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('payments.view');
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'method' => ['nullable', Rule::in(array_keys(Payment::METHODS))],
            'status' => ['nullable', Rule::in([Payment::COMPLETED, Payment::REVERSED])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
            'student_id' => ['nullable', 'integer'],
        ]);
        $q = trim((string) ($f['q'] ?? ''));

        $page = Payment::query()->with(['student:id,name,student_code', 'recorder:id,name'])
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('receipt_no', 'like', "%{$q}%")->orWhere('reference', 'like', "%{$q}%")
                ->orWhereHas('student', fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('student_code', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))))
            ->when($f['method'] ?? null, fn ($w, $m) => $w->where('method', $m))
            ->when($f['status'] ?? null, fn ($w, $s) => $w->where('status', $s))
            ->when($f['student_id'] ?? null, fn ($w, $s) => $w->where('student_id', $s))
            ->when($f['from'] ?? null, fn ($w, $d) => $w->whereDate('paid_on', '>=', $d))
            ->when($f['until'] ?? null, fn ($w, $d) => $w->whereDate('paid_on', '<=', $d))
            ->latest('paid_on')->latest('id')->paginate($this->perPage());

        return $this->paged($page, fn ($p) => Present::payment($p), ['summary' => [
            'today' => $this->collected(today(), today()),
            'month' => $this->collected(now()->startOfMonth(), now()->endOfMonth()),
            'dues' => Present::money(StudentMembership::owing()->withBalance()->get()->sum('balance')),
            'methods' => collect(Payment::METHODS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
        ]]);
    }

    /** Money in minus refunds paid out. Reversed payments never count. */
    protected function collected(Carbon $from, Carbon $to): float
    {
        $in = Payment::where('status', Payment::COMPLETED)->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->sum('amount');
        $out = Refund::whereBetween('refunded_on', [$from->toDateString(), $to->toDateString()])->sum('amount');

        return Present::money((float) $in - (float) $out);
    }

    public function show(int $payment): JsonResponse
    {
        $this->authorizePermission('payments.view');
        $p = Payment::with(['student:id,name,student_code', 'recorder:id,name', 'allocations.membership.plan', 'refunds'])->findOrFail($payment);

        return $this->ok(Present::payment($p) + [
            'allocations' => $p->allocations->map(fn ($a) => ['membership_id' => $a->student_membership_id, 'plan' => $a->membership?->plan?->name, 'amount' => Present::money($a->amount)])->all(),
            'refunds' => $p->refunds->map(fn ($r) => ['refund_no' => $r->refund_no, 'amount' => Present::money($r->amount), 'method' => $r->method, 'reason' => $r->reason, 'refunded_on' => Present::d($r->refunded_on)])->all(),
            'reversal' => $p->isReversed() ? ['at' => Present::t($p->reversed_at), 'reason' => $p->reversal_reason] : null,
            'receipt_pdf_url' => $this->can('receipts.print') ? route('api.v1.library.payments.receipt', ['library' => Tenancy::slug(), 'payment' => $p->id]) : null,
        ]);
    }

    /** What a student owes, membership by membership, for the Record payment screen. */
    public function owing(int $student): JsonResponse
    {
        $this->authorizePermission('payments.record|payments.view');
        $s = Student::findOrFail($student);

        return $this->ok(StudentMembership::owing()->withBalance()->with('plan')->where('student_id', $s->id)->orderBy('ends_on')->get()
            ->map(fn ($m) => Present::membership($m))->all());
    }

    /**
     * Record a payment. Send an "Idempotency-Key" header (or idempotency_key field), unique per attempt:
     * repeating the same request returns the first payment instead of creating another.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('payments.record');
        $this->writable();
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'membership_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ], ['student_id.required' => 'Choose a student.'], ['paid_on' => 'payment date']);
        $key = $request->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? null);

        $payment = app(RecordPayment::class)(
            Student::findOrFail($data['student_id']),
            (string) $data['amount'],
            $data['method'],
            Carbon::parse($data['paid_on']),
            ! empty($data['membership_id']) ? StudentMembership::findOrFail($data['membership_id']) : null,
            $data['reference'] ?? null,
            $data['notes'] ?? null,
            $key ? 'api-'.mb_substr($key, 0, 60) : null,
        );

        return $this->message("Payment recorded — receipt {$payment->receipt_no}.", Present::payment($payment->load('student')), $payment->wasRecentlyCreated ? 201 : 200);
    }

    /** Refund part of a payment for one membership. */
    public function refund(Request $request, int $payment): JsonResponse
    {
        $this->authorizePermission('payments.refund');
        $this->writable();
        $data = $request->validate([
            'membership_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $refund = app(RecordRefund::class)(Payment::findOrFail($payment), StudentMembership::findOrFail($data['membership_id']), (string) $data['amount'], $data['method'], $data['reason']);

        return $this->message("Refund {$refund->refund_no} recorded.", ['refund_no' => $refund->refund_no, 'amount' => Present::money($refund->amount)], 201);
    }

    /** How much of each membership in this payment can still be refunded. */
    public function refundOptions(int $payment): JsonResponse
    {
        $this->authorizePermission('payments.refund');
        $p = Payment::findOrFail($payment);

        return $this->ok(PaymentAllocation::with('membership.plan')->where('payment_id', $p->id)->get()->map(function ($a) {
            $done = (float) Refund::where('payment_id', $a->payment_id)->where('student_membership_id', $a->student_membership_id)->sum('amount');

            return ['membership_id' => $a->student_membership_id, 'plan' => $a->membership?->plan?->name, 'refundable' => round((float) $a->amount - $done, 2)];
        })->filter(fn ($r) => $r['refundable'] > 0)->values()->all());
    }

    /** Undo a payment recorded by mistake (it stays on record, marked reversed). */
    public function reverse(Request $request, int $payment): JsonResponse
    {
        $this->authorizePermission('payments.refund');
        $this->writable();
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $p = app(ReversePayment::class)(Payment::findOrFail($payment), $data['reason']);

        return $this->message('Payment reversed.', Present::payment($p));
    }

    /** The receipt as a PDF file (same document as the web). */
    public function receipt(int $payment)
    {
        $this->authorizePermission('receipts.print');
        $p = Payment::with(['student', 'recorder', 'allocations.membership.plan', 'refunds.membership.plan'])->findOrFail($payment);

        return Pdf::download('pdf.document', PdfDocs::payment($p, Tenancy::library()), "receipt-{$p->receipt_no}.pdf");
    }

    // ---------- dues ----------

    public function dues(Request $request): JsonResponse
    {
        $this->authorizePermission('payments.view');
        $q = trim((string) $request->query('q', ''));
        $overdue = $request->query('filter') === 'overdue';
        $base = fn () => StudentMembership::owing()
            ->when($q !== '', fn ($w) => $w->whereHas('student', fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('student_code', 'like', "%{$q}%")))
            ->when($overdue, fn ($w) => $w->whereDate('student_memberships.ends_on', '<', today()));

        $page = $base()->withBalance()->with(['student:id,name,phone,student_code', 'plan:id,name'])->orderBy('student_memberships.ends_on')->paginate($this->perPage());

        return $this->paged($page, fn ($m) => Present::membership($m) + ['student' => ['id' => $m->student?->id, 'name' => $m->student?->name, 'code' => $m->student?->student_code, 'phone' => $m->student?->phone]],
            ['total' => Present::money($base()->withBalance()->get()->sum('balance'))]);
    }

    // ---------- payment claims from students ("I have paid") ----------

    public function submissions(Request $request): JsonResponse
    {
        $this->authorizePermission('payments.record');
        $status = in_array($request->query('status'), [PaymentSubmission::PENDING, PaymentSubmission::APPROVED, PaymentSubmission::REJECTED], true) ? $request->query('status') : null;
        $page = PaymentSubmission::with(['student:id,name,student_code', 'membership.plan', 'payment:id,receipt_no', 'reviewer:id,name'])
            ->when($status, fn ($q) => $q->where('status', $status))->latest('id')->paginate($this->perPage());

        return $this->paged($page, fn ($s) => $this->submission($s), ['pending' => PaymentSubmission::where('status', PaymentSubmission::PENDING)->count()]);
    }

    public function approve(int $submission): JsonResponse
    {
        $this->authorizePermission('payments.record');
        $this->writable();
        $s = app(ReviewPaymentSubmission::class)->approve(PaymentSubmission::findOrFail($submission));

        return $this->message('Confirmed — receipt '.$s->payment?->receipt_no.'.', $this->submission($s->load(['student', 'membership.plan', 'payment', 'reviewer'])));
    }

    public function reject(Request $request, int $submission): JsonResponse
    {
        $this->authorizePermission('payments.record');
        $this->writable();
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tell the student why.']);
        $s = app(ReviewPaymentSubmission::class)->reject(PaymentSubmission::findOrFail($submission), $data['reason']);

        return $this->message('Rejected. The student was told why.', $this->submission($s->load(['student', 'membership.plan', 'payment', 'reviewer'])));
    }

    /** The proof photo / PDF. Private: staff who handle payments only. */
    public function submissionProof(int $submission)
    {
        $this->authorizePermission('payments.record|payments.view');
        $s = PaymentSubmission::findOrFail($submission);
        abort_unless($s->proof_path && Storage::disk('local')->exists($s->proof_path), 404);

        return Storage::disk('local')->response($s->proof_path, null, ['X-Content-Type-Options' => 'nosniff']);
    }

    protected function submission(PaymentSubmission $s): array
    {
        return [
            'id' => $s->id,
            'student' => $s->student ? ['id' => $s->student->id, 'name' => $s->student->name, 'code' => $s->student->student_code] : null,
            'membership' => $s->membership ? ['id' => $s->membership->id, 'plan' => $s->membership->plan?->name] : null,
            'amount' => Present::money($s->amount), 'method' => $s->method, 'method_label' => Payment::METHODS[$s->method] ?? $s->method,
            'reference' => $s->reference, 'paid_on' => Present::d($s->paid_on), 'note' => $s->note,
            'has_proof' => (bool) $s->proof_path,
            'status' => $s->status, 'review_note' => $s->review_note, 'reviewed_by' => $s->reviewer?->name, 'reviewed_at' => Present::t($s->reviewed_at),
            'receipt_no' => $s->payment?->receipt_no, 'created_at' => Present::t($s->created_at),
        ];
    }
}
