<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Circulation\IssueBooks;
use App\Actions\Circulation\RenewLoanItem;
use App\Actions\Circulation\Reservations;
use App\Actions\Circulation\ReturnBooks;
use App\Actions\Circulation\SettleFine;
use App\Http\Resources\Api\V1\Present;
use App\Models\Book;
use App\Models\Fine;
use App\Models\LoanItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Student;
use App\Support\CirculationRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lending (Books module): the member's borrowing panel, issue by scanned barcodes, return (good / damaged / lost),
 * renew, loans, reservations and fines. All rules (limits, membership, unpaid fines, holds) are the server's.
 */
class CirculationController extends Controller
{
    /** Before issuing: can this member borrow, what do they have, what is waiting for them, what do they owe. */
    public function member(int $student): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('circulation.view|circulation.issue|circulation.return');
        $s = Student::findOrFail($student);
        $rule = CirculationRules::rule($s->member_type);

        return $this->ok([
            'student' => Present::studentRow($s),
            'block_reason' => IssueBooks::blockReason($s),
            'rules' => $rule,
            'open_loans' => LoanItem::with(['book:id,title,author', 'copy:id,accession_no,barcode'])->where('student_id', $s->id)->open()->orderBy('due_on')->get()->map(fn ($i) => $this->item($i))->all(),
            'ready_reservations' => Reservation::with(['book:id,title', 'copy:id,accession_no,barcode'])->where('student_id', $s->id)->where('status', Reservation::READY)->get()->map(fn ($r) => $this->reservation($r))->all(),
            'unpaid_fines' => Present::money(Fine::where('student_id', $s->id)->pending()->get()->sum(fn ($f) => (float) $f->outstanding())),
        ]);
    }

    /** Issue scanned copies to one member, all or nothing. Returns the loan with due dates. */
    public function issue(Request $request): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('circulation.issue');
        $this->writable();
        $d = $request->validate(['student_id' => ['required', 'integer'], 'barcodes' => ['required', 'array', 'min:1', 'max:20'], 'barcodes.*' => ['required', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:500']]);
        $loan = app(IssueBooks::class)(Student::findOrFail($d['student_id']), array_values($d['barcodes']), $d['notes'] ?? null);
        $loan->load(['items.book:id,title,author', 'items.copy:id,accession_no,barcode']);

        return $this->message(count($loan->items).' book(s) issued — '.$loan->loan_no.'.', ['loan_no' => $loan->loan_no, 'items' => $loan->items->map(fn ($i) => $this->item($i))->all()], 201);
    }

    /**
     * Return books: returns = [{item: id | barcode: code, condition: good|damaged|lost, fee?}].
     * Lost and damaged need circulation.manage. One return receipt; fines are created automatically.
     */
    public function returnBooks(Request $request): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('circulation.return');
        $this->writable();
        $d = $request->validate([
            'returns' => ['required', 'array', 'min:1', 'max:20'],
            'returns.*.item' => ['nullable', 'integer'],
            'returns.*.barcode' => ['nullable', 'string', 'max:60'],
            'returns.*.condition' => ['nullable', 'in:good,damaged,lost'],
            'returns.*.fee' => ['nullable', 'numeric', 'min:0'],
        ]);
        $r = app(ReturnBooks::class)(array_map(fn ($x) => array_filter($x, fn ($v) => $v !== null), $d['returns']));

        return $this->message(count($r['items']).' book(s) returned — '.$r['receipt_no'].'.', [
            'receipt_no' => $r['receipt_no'],
            'items' => collect($r['items'])->map(fn ($i) => $this->item($i->loadMissing(['book:id,title', 'copy:id,accession_no,barcode'])))->all(),
            'fines' => collect($r['fines'])->map(fn ($f) => $this->fine($f))->all(),
        ]);
    }

    public function renew(int $item): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('circulation.renew');
        $this->writable();
        $i = app(RenewLoanItem::class)->staff(LoanItem::findOrFail($item));

        return $this->message('Renewed until '.$i->due_on->format('d M Y').'.', $this->item($i->load(['book:id,title', 'copy:id,accession_no,barcode'])));
    }

    public function loans(Request $request): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('circulation.view');
        $f = $request->validate(['status' => ['nullable', 'in:open,overdue,returned,all'], 'q' => ['nullable', 'string', 'max:100'], 'student_id' => ['nullable', 'integer']]);
        $q = trim((string) ($f['q'] ?? ''));
        $status = $f['status'] ?? 'open';

        $page = LoanItem::with(['student:id,name,student_code', 'book:id,title,author', 'copy:id,accession_no,barcode'])
            ->when($status === 'open', fn ($w) => $w->open())
            ->when($status === 'overdue', fn ($w) => $w->overdue())
            ->when($status === 'returned', fn ($w) => $w->whereIn('status', [LoanItem::RETURNED, LoanItem::LOST]))
            ->when($f['student_id'] ?? null, fn ($w, $s) => $w->where('student_id', $s))
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->whereHas('student', fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('student_code', 'like', "%{$q}%"))
                ->orWhereHas('book', fn ($b) => $b->where('title', 'like', "%{$q}%"))->orWhereHas('copy', fn ($c) => $c->where('barcode', $q)->orWhere('accession_no', $q))))
            ->orderBy($status === 'returned' ? 'returned_at' : 'due_on', $status === 'returned' ? 'desc' : 'asc')->paginate($this->perPage());

        return $this->paged($page, fn ($i) => $this->item($i) + ['student' => ['id' => $i->student?->id, 'name' => $i->student?->name, 'code' => $i->student?->student_code]],
            ['summary' => ['open' => LoanItem::open()->count(), 'overdue' => LoanItem::overdue()->count()]]);
    }

    // ---------- reservations ----------

    public function reservations(Request $request): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('circulation.view');
        $status = $request->query('status');
        $page = Reservation::with(['student:id,name,student_code', 'book:id,title', 'copy:id,accession_no,barcode'])
            ->when(in_array($status, [Reservation::WAITING, Reservation::READY, Reservation::FULFILLED, Reservation::CANCELLED, Reservation::EXPIRED], true), fn ($q) => $q->where('status', $status), fn ($q) => $q->active())
            ->orderBy('requested_at')->paginate($this->perPage());

        return $this->paged($page, fn ($r) => $this->reservation($r) + ['student' => ['id' => $r->student?->id, 'name' => $r->student?->name, 'code' => $r->student?->student_code]]);
    }

    public function reserve(Request $request): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('circulation.issue');
        $this->writable();
        $d = $request->validate(['student_id' => ['required', 'integer'], 'book_id' => ['required', 'integer']]);
        $r = app(Reservations::class)->staff(Student::findOrFail($d['student_id']), Book::findOrFail($d['book_id']));

        return $this->message('Reserved.', $this->reservation($r->load(['book:id,title', 'copy'])), 201);
    }

    public function cancelReservation(Request $request, int $reservation): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('circulation.issue');
        $this->writable();
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $r = app(Reservations::class)->cancel(Reservation::findOrFail($reservation), $d['reason'] ?? null);

        return $this->message('Reservation cancelled.', $this->reservation($r->load(['book:id,title', 'copy'])));
    }

    // ---------- fines ----------

    public function fines(Request $request): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('fines.view');
        $status = $request->query('status', Fine::PENDING);
        $page = Fine::with(['student:id,name,student_code', 'loanItem.book:id,title'])
            ->when(in_array($status, [Fine::PENDING, Fine::PAID, Fine::WAIVED], true), fn ($q) => $q->where('status', $status))
            ->when($request->integer('student_id'), fn ($q, $s) => $q->where('student_id', $s))
            ->latest('id')->paginate($this->perPage());

        return $this->paged($page, fn ($f) => $this->fine($f) + ['student' => ['id' => $f->student?->id, 'name' => $f->student?->name, 'code' => $f->student?->student_code], 'book' => $f->loanItem?->book?->title],
            ['unpaid_total' => Present::money(Fine::pending()->get()->sum(fn ($f) => (float) $f->outstanding()))]);
    }

    public function collectFine(Request $request, int $fine): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('fines.collect');
        $this->writable();
        $d = $request->validate(['method' => ['required', Rule::in(array_keys(Payment::METHODS))], 'reference' => ['nullable', 'string', 'max:100']]);
        $f = app(SettleFine::class)->collect(Fine::findOrFail($fine), $d['method'], $d['reference'] ?? null);

        return $this->message('Fine collected — receipt '.$f->receipt_no.'.', $this->fine($f));
    }

    public function waiveFine(Request $request, int $fine): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('fines.waive');
        $this->writable();
        $d = $request->validate(['reason' => ['required', 'string', 'max:300'], 'amount' => ['nullable', 'numeric', 'gt:0']]);
        $f = app(SettleFine::class)->waive(Fine::findOrFail($fine), $d['reason'], $d['amount'] ?? null);

        return $this->message('Fine waived.', $this->fine($f));
    }

    // ---------- shapes ----------

    protected function item(LoanItem $i): array
    {
        return [
            'id' => $i->id, 'book' => $i->book ? ['id' => $i->book->id, 'title' => $i->book->title, 'author' => $i->book->author ?? null] : null,
            'copy' => $i->copy ? ['id' => $i->copy->id, 'accession_no' => $i->copy->accession_no, 'barcode' => $i->copy->barcode] : null,
            'issued_at' => Present::t($i->issued_at), 'due_on' => Present::d($i->due_on), 'returned_at' => Present::t($i->returned_at),
            'status' => $i->status, 'overdue' => $i->isOverdue(), 'renewals' => (int) $i->renewals, 'return_condition' => $i->return_condition,
        ];
    }

    protected function reservation(Reservation $r): array
    {
        return [
            'id' => $r->id, 'book' => $r->book ? ['id' => $r->book->id, 'title' => $r->book->title] : null, 'status' => $r->status,
            'requested_at' => Present::t($r->requested_at), 'ready_at' => Present::t($r->ready_at), 'expires_on' => Present::d($r->expires_on),
            'copy' => $r->copy ? ['accession_no' => $r->copy->accession_no, 'barcode' => $r->copy->barcode] : null,
        ];
    }

    protected function fine(Fine $f): array
    {
        return [
            'id' => $f->id, 'type' => $f->type, 'type_label' => Fine::TYPES[$f->type] ?? $f->type, 'days' => (int) $f->days,
            'amount' => Present::money($f->amount), 'paid' => Present::money($f->paid_amount), 'waived' => Present::money($f->waived_amount),
            'outstanding' => Present::money($f->outstanding()), 'status' => $f->status, 'receipt_no' => $f->receipt_no, 'note' => $f->note,
            'created_at' => Present::t($f->created_at),
        ];
    }
}
