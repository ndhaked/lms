<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Circulation\RenewLoanItem;
use App\Actions\Circulation\Reservations;
use App\Http\Controllers\ReceiptController;
use App\Http\Resources\Api\V1\Present;
use App\Models\Attendance;
use App\Models\Book;
use App\Models\Fine;
use App\Models\LoanItem;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\PaymentSubmission;
use App\Models\RenewalRequest;
use App\Models\Reservation;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Support\DigitalId;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use App\Support\Notifier;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The student app: only the data of the student linked to this login. No endpoint here takes a student id;
 * the student is always found from the signed-in user, so one student can never read another's records.
 */
class StudentSelfController extends Controller
{
    protected function me(): Student
    {
        $this->authorizePermission('self.view');
        $s = Student::with('activeAllocation.seat.hall')->where('user_id', auth()->id())->first();
        abort_unless($s, 404, 'No student record is linked to your login.');

        return $s;
    }

    /** Home: membership, seat, dues, books, today's visit, reservations. */
    public function home(): JsonResponse
    {
        $s = $this->me();
        $current = $s->currentMembership()?->load('plan');
        $books = Entitlements::hasModule('books');

        return $this->ok([
            'student' => Present::student($s),
            'membership' => $current ? Present::membership($current) + ['days_left' => (int) today()->diffInDays($current->ends_on, false)] : null,
            'seat' => $s->activeAllocation ? Present::allocation($s->activeAllocation) : null,
            'dues' => Present::money(StudentMembership::owing()->withBalance()->where('student_id', $s->id)->get()->sum('balance')),
            'today' => ($a = Attendance::where('student_id', $s->id)->whereDate('checked_in_at', today())->latest('checked_in_at')->first()) ? Present::attendance($a) : null,
            'visits_this_month' => Attendance::where('student_id', $s->id)->whereBetween('checked_in_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            'books' => $books ? ['borrowed' => LoanItem::open()->where('student_id', $s->id)->count(), 'overdue' => LoanItem::overdue()->where('student_id', $s->id)->count(),
                'fines' => Present::money(Fine::pending()->where('student_id', $s->id)->get()->sum(fn ($f) => (float) $f->outstanding()))] : null,
            'features' => [
                'books' => $books, 'ebooks' => Entitlements::hasModule('ebooks') && auth()->user()->can('ebooks.read'),
                'reservations' => $books && (bool) LibrarySettings::get('reservations_enabled'),
                'payment_submissions' => (bool) LibrarySettings::get('student_payment_submissions'),
                'self_renew_books' => (bool) LibrarySettings::get('student_can_renew'),
            ],
        ]);
    }

    /** The digital ID: a QR token valid for 60 seconds. The app asks for a new one before it expires. */
    public function digitalId(): JsonResponse
    {
        $s = $this->me();
        abort_unless($s->status === Student::ACTIVE, 422, 'Your membership card is not active. Contact the library.');

        return $this->ok(DigitalId::make($s) + ['student' => ['name' => $s->name, 'code' => $s->student_code, 'photo_url' => Present::photo($s->photo_path)],
            'valid_until' => Present::d($s->currentMembership()?->ends_on), 'library' => $this->library()->name]);
    }

    public function memberships(): JsonResponse
    {
        $s = $this->me();

        return $this->ok([
            'memberships' => StudentMembership::with('plan')->where('student_id', $s->id)->orderByDesc('ends_on')->get()->map(fn ($m) => Present::membership($m))->all(),
            'seat' => $s->activeAllocation ? Present::allocation($s->activeAllocation) : null,
            'plans' => MembershipPlan::where('status', 'active')->orderBy('price')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => Present::money($p->price), 'duration' => MembershipPlan::DURATIONS[$p->duration_unit] ?? $p->duration_unit])->all(),
            'pending_request' => ($r = RenewalRequest::where('student_id', $s->id)->where('status', 'pending')->latest('id')->first()) ? ['id' => $r->id, 'plan_id' => $r->membership_plan_id, 'message' => $r->message, 'created_at' => Present::t($r->created_at)] : null,
        ]);
    }

    public function requestRenewal(Request $request): JsonResponse
    {
        $s = $this->me();
        $this->writable();
        $d = $request->validate([
            'plan_id' => ['nullable', Rule::exists('membership_plans', 'id')->where('library_id', Tenancy::currentId())->where('status', 'active')],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        abort_if(RenewalRequest::where('student_id', $s->id)->where('status', 'pending')->exists(), 422, 'You already have a request waiting.');
        RenewalRequest::create(['student_id' => $s->id, 'membership_plan_id' => $d['plan_id'] ?? null, 'message' => $d['message'] ?? null]);
        Notifier::library($this->library(), 'memberships.manage', 'membership.renewal_requested', "{$s->name} asked to renew", ($d['message'] ?? null) ?: 'Open Memberships to respond.', route('app.memberships.index', ['library' => Tenancy::slug()]));

        return $this->message('Request sent. The library will contact you.', [], 201);
    }

    public function attendance(Request $request): JsonResponse
    {
        $s = $this->me();
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : now()->format('Y-m');
        $start = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $visits = Attendance::with('seat:id,code')->where('student_id', $s->id)->whereBetween('checked_in_at', [$start, $start->copy()->endOfMonth()])->orderBy('checked_in_at')->get();

        return $this->ok([
            'month' => $month,
            'visits' => $visits->map(fn ($a) => Present::attendance($a))->all(),
            'days_present' => $visits->map(fn ($a) => $a->checked_in_at->toDateString())->unique()->values()->all(),
            'summary' => ['visits' => $visits->count(), 'late' => $visits->where('is_late', true)->count(), 'by_fingerprint' => $visits->where('source', 'biometric')->count(),
                'minutes' => (int) $visits->sum(fn ($a) => (int) $a->minutes())],
        ]);
    }

    public function payments(): JsonResponse
    {
        $s = $this->me();
        $owing = StudentMembership::with('plan')->withBalance()->where('student_id', $s->id)->get();

        return $this->ok([
            'dues' => Present::money($owing->sum('balance')),
            'owing' => $owing->filter(fn ($m) => (float) $m->balance > 0)->map(fn ($m) => Present::membership($m))->values()->all(),
            'payments' => Payment::where('student_id', $s->id)->latest('paid_on')->latest('id')->limit(30)->get()->map(fn ($p) => Present::payment($p) + [
                'receipt_pdf_url' => route('api.v1.library.my.receipt', ['library' => Tenancy::slug(), 'payment' => $p->id])])->all(),
            'submissions' => PaymentSubmission::with('payment:id,receipt_no')->where('student_id', $s->id)->latest('id')->limit(10)->get()->map(fn ($x) => [
                'id' => $x->id, 'amount' => Present::money($x->amount), 'method' => $x->method, 'reference' => $x->reference, 'paid_on' => Present::d($x->paid_on),
                'status' => $x->status, 'review_note' => $x->review_note, 'receipt_no' => $x->payment?->receipt_no])->all(),
            'can_submit' => (bool) LibrarySettings::get('student_payment_submissions'),
            'methods' => collect(Payment::METHODS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
        ]);
    }

    /** The student's own receipt as a PDF. */
    public function receipt(Request $request, int $payment)
    {
        $this->me();
        $request->query->set('format', 'pdf');

        return app(ReceiptController::class)->mine($request, Tenancy::slug(), (string) $payment);
    }

    /**
     * "I have paid": tell the library about a payment made outside (UPI, bank, cash). It becomes a real payment only
     * when staff confirm it. Not more than what is due, at most 3 waiting, a reference cannot be used twice.
     */
    public function submitPayment(Request $request): JsonResponse
    {
        $s = $this->me();
        $this->writable();
        abort_unless(LibrarySettings::get('student_payment_submissions'), 403, 'This library does not take payment details from students.');
        $d = $request->validate([
            'membership_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reference' => [$request->input('method') === 'cash' ? 'nullable' : 'required', 'string', 'max:100'],
            'paid_on' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.today()->subDays(90)->toDateString()],
            'note' => ['nullable', 'string', 'max:500'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ], ['reference.required' => 'Enter the transaction or reference number.'], ['paid_on' => 'payment date']);

        $m = StudentMembership::withBalance()->where('student_id', $s->id)->where('status', '!=', 'cancelled')->find($d['membership_id']);
        abort_unless($m, 422, 'Choose one of your memberships with an amount due.');
        $waiting = (float) PaymentSubmission::where('student_id', $s->id)->where('student_membership_id', $m->id)->where('status', 'pending')->sum('amount');
        $claimable = round(max(0, (float) $m->balance) - $waiting, 2);
        $amount = round((float) $d['amount'], 2);
        abort_if($claimable <= 0 || $amount > $claimable, 422, 'That is more than the amount due (₹'.number_format(max(0, $claimable), 2).').');
        abort_if(PaymentSubmission::where('student_id', $s->id)->where('status', 'pending')->count() >= 3, 422, 'You already have 3 payments waiting. Please wait for the library to confirm them.');
        abort_if(filled($d['reference'] ?? null) && PaymentSubmission::where('reference', $d['reference'])->whereIn('status', ['pending', 'approved'])->exists(), 422, 'This reference number has already been submitted.');

        $sub = PaymentSubmission::create([
            'student_id' => $s->id, 'student_membership_id' => $m->id, 'amount' => number_format($amount, 2, '.', ''), 'method' => $d['method'],
            'reference' => $d['reference'] ?? null, 'paid_on' => $d['paid_on'], 'note' => $d['note'] ?? null,
            'proof_path' => $request->hasFile('proof') ? $request->file('proof')->store('payment-proofs/'.Tenancy::currentId(), 'local') : null,
        ]);
        Notifier::library($this->library(), 'payments.record', 'payment_submission.new', "{$s->name} says they paid ₹".number_format($amount, 2), 'Open Student Payments to confirm or reject.', route('app.payment-submissions.index', ['library' => Tenancy::slug()]));

        return $this->message('Sent. The library will confirm it, then your receipt appears here.', ['id' => $sub->id, 'status' => $sub->status], 201);
    }

    public function submissionProof(int $submission)
    {
        $s = $this->me();
        $x = PaymentSubmission::where('student_id', $s->id)->findOrFail($submission);
        abort_unless($x->proof_path && Storage::disk('local')->exists($x->proof_path), 404);

        return Storage::disk('local')->response($x->proof_path, null, ['X-Content-Type-Options' => 'nosniff']);
    }

    // ---------- books ----------

    public function books(): JsonResponse
    {
        $s = $this->me();
        $this->requireModule('books');

        return $this->ok([
            'borrowed' => LoanItem::with(['book:id,title,author', 'copy:id,accession_no'])->open()->where('student_id', $s->id)->orderBy('due_on')->get()->map(fn ($i) => [
                'id' => $i->id, 'book' => ['id' => $i->book?->id, 'title' => $i->book?->title, 'author' => $i->book?->author], 'copy' => $i->copy?->accession_no,
                'issued_at' => Present::t($i->issued_at), 'due_on' => Present::d($i->due_on), 'overdue' => $i->isOverdue(), 'renewals' => (int) $i->renewals])->all(),
            'reservations' => Reservation::with('book:id,title')->active()->where('student_id', $s->id)->orderBy('requested_at')->get()->map(fn ($r) => [
                'id' => $r->id, 'book' => ['id' => $r->book?->id, 'title' => $r->book?->title], 'status' => $r->status, 'ready_at' => Present::t($r->ready_at), 'expires_on' => Present::d($r->expires_on)])->all(),
            'fines' => Fine::pending()->where('student_id', $s->id)->latest('id')->get()->map(fn ($f) => ['id' => $f->id, 'type' => Fine::TYPES[$f->type] ?? $f->type, 'outstanding' => Present::money($f->outstanding()), 'created_at' => Present::t($f->created_at)])->all(),
            'can_renew' => (bool) LibrarySettings::get('student_can_renew'),
            'can_reserve' => (bool) LibrarySettings::get('reservations_enabled'),
        ]);
    }

    /** Search the catalogue (physical books) to reserve one. Reserving is only offered when no copy is on the shelf. */
    public function searchBooks(Request $request): JsonResponse
    {
        $this->me();
        $this->requireModule('books');
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->ok([]);
        }

        return $this->ok(Book::where('type', Book::PHYSICAL)->where('status', 'active')->search($q)
            ->withCount(['copies as available' => fn ($c) => $c->where('status', 'available')])->orderBy('title')->limit(20)->get()
            ->map(fn ($b) => ['id' => $b->id, 'title' => $b->title, 'author' => $b->author, 'available' => (int) $b->available,
                'can_reserve' => (int) $b->available === 0 && (bool) LibrarySettings::get('reservations_enabled')])->all());
    }

    public function reserve(Request $request): JsonResponse
    {
        $this->me();
        $this->writable();
        $this->requireModule('books');
        abort_unless(LibrarySettings::get('reservations_enabled'), 403, 'This library does not take reservations.');
        $d = $request->validate(['book_id' => ['required', 'integer']]);
        app(Reservations::class)->self(Book::findOrFail($d['book_id']));

        return $this->message('Reserved. We will tell you when it is ready.', [], 201);
    }

    public function cancelReservation(int $reservation): JsonResponse
    {
        $s = $this->me();
        $this->writable();
        $r = Reservation::where('student_id', $s->id)->findOrFail($reservation);
        app(Reservations::class)->cancel($r);

        return $this->message('Reservation cancelled.');
    }

    public function renewBook(int $item): JsonResponse
    {
        $s = $this->me();
        $this->writable();
        $i = app(RenewLoanItem::class)->self(LoanItem::where('student_id', $s->id)->findOrFail($item));

        return $this->message('Renewed. New due date: '.$i->due_on->format('d M Y').'.', ['due_on' => Present::d($i->due_on)]);
    }

    /** E-books the student may read (open them with GET ebooks/{book}/open). */
    public function ebooks(Request $request): JsonResponse
    {
        $this->me();
        $this->requireModule('ebooks');
        $this->authorizePermission('ebooks.read');
        $q = trim((string) $request->query('q', ''));
        $page = Book::where('type', Book::EBOOK)->where('status', 'active')->search($q !== '' ? $q : null)->with('category:id,name')->orderBy('title')->paginate($this->perPage());

        return $this->paged($page, fn ($b) => ['id' => $b->id, 'title' => $b->title, 'author' => $b->author, 'category' => $b->category?->name,
            'format' => $b->ebook_mime === 'application/pdf' ? 'pdf' : ($b->hasFile() ? 'file' : 'link'), 'allow_download' => (bool) $b->allow_download,
            'open_url' => route('api.v1.library.ebooks.open', ['library' => Tenancy::slug(), 'book' => $b->id])]);
    }
}
