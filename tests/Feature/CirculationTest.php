<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\ManageLibraryModule;
use App\Actions\Books\AddCopies;
use App\Actions\Books\SaveBook;
use App\Actions\Circulation\IssueBooks;
use App\Actions\Circulation\RenewLoanItem;
use App\Actions\Circulation\ReturnBooks;
use App\Actions\Circulation\ReverseCirculation;
use App\Actions\Circulation\SettleFine;
use App\Actions\CreateStudentLogin;
use App\Actions\RegisterLibrary;
use App\Exceptions\BusinessRuleException;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Fine;
use App\Models\Library;
use App\Models\LoanItem;
use App\Models\MembershipPlan;
use App\Models\Student;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\CirculationRules;
use App\Support\Entitlements;
use App\Support\LibraryRoles;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CirculationTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;
    protected User $owner;
    protected User $root;
    protected Student $student;
    protected Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-19 10:00:00');      // a Monday; the library is closed on Sundays by default

        $this->root = User::factory()->create();
        $this->root->forceFill(['is_super_admin' => true])->save();
        $this->root = $this->root->fresh();
        $this->actingAs($this->root);

        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@alpha.test']);
        $this->owner = User::where('email', 'o@alpha.test')->first();
        app(CreateSubscription::class)($this->library, SubscriptionPlan::create(['name' => 'Std', 'price_monthly' => 1000, 'trial_days' => 30]), 'monthly', true);
        app(ManageLibraryModule::class)->grant($this->library, 'books', 'complimentary', 'test');

        $this->actingAs($this->owner);
        Tenancy::set($this->library);

        $this->student = $this->member('9000000001');
        $this->book = app(SaveBook::class)(['type' => 'physical', 'title' => 'The Alchemist', 'isbn' => '9780062315007', 'purchase_price' => '350']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        BillingSettings::flush();
        LibrarySettings::flush();
        Entitlements::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function member(string $phone, array $attrs = [], bool $membership = true): Student
    {
        $s = Student::create(array_merge(['name' => 'Member '.$phone, 'phone' => $phone, 'email' => "m{$phone}@x.test"], $attrs));
        if ($membership) {
            app(AssignMembership::class)($s, MembershipPlan::first() ?? MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 500]), today());
        }

        return $s;
    }

    /** @return list<BookCopy> */
    protected function copies(int $n = 1, ?Book $book = null): array
    {
        return app(AddCopies::class)($book ?? $this->book, $n)->all();
    }

    protected function issue(array $codes, ?Student $s = null)
    {
        return app(IssueBooks::class)($s ?? $this->student, $codes);
    }

    protected function fails(callable $fn, ?string $contains = null): void
    {
        try {
            $fn();
            $this->fail('expected a business rule error');
        } catch (BusinessRuleException $e) {
            if ($contains) {
                $this->assertStringContainsString($contains, $e->getMessage());
            }
            $this->assertTrue(true);
        }
    }

    protected function actAs(string $role): User
    {
        $u = User::factory()->create();
        $this->library->users()->attach($u->id, ['is_active' => true]);
        Tenancy::run($this->library, fn () => $u->assignRole($role));
        $this->actingAs($u->fresh());
        Tenancy::set($this->library);

        return $u;
    }

    // ===================== rules =====================

    public function test_due_dates_skip_closed_days_and_holidays(): void
    {
        $this->assertSame('2026-11-02', CirculationRules::dueDate(today(), 14)->toDateString());              // Monday: fine
        $this->assertSame('2026-10-26', CirculationRules::dueDate(today(), 6)->toDateString(), 'Sunday 25th is closed, so Monday');

        LibrarySettings::update(['holidays' => [['date' => '2026-10-26', 'label' => 'Holiday']]]);
        $this->assertSame('2026-10-27', CirculationRules::dueDate(today(), 6)->toDateString(), 'the holiday is skipped too');
    }

    public function test_late_days_ignore_closed_days_grace_and_cap_the_fine(): void
    {
        $due = Carbon::parse('2026-10-26');                                                                    // Monday
        $this->assertSame(0, CirculationRules::overdueDays($due, Carbon::parse('2026-10-26')));
        $this->assertSame(5, CirculationRules::overdueDays($due, Carbon::parse('2026-10-31')));
        $this->assertSame(6, CirculationRules::overdueDays($due, Carbon::parse('2026-11-02')), '1 Nov is a Sunday: not counted');
        $this->assertSame(4, CirculationRules::overdueDays($due, Carbon::parse('2026-11-02'), 2), 'grace days come off');

        LibrarySettings::update(['fine_skip_closed_days' => false]);
        $this->assertSame(7, CirculationRules::overdueDays($due, Carbon::parse('2026-11-02')));

        $rule = CirculationRules::rule('student');
        $this->assertSame('14.00', CirculationRules::fineFor($rule, 7));
        $this->assertSame('10.00', CirculationRules::fineFor($rule + ['fine_cap' => '10.00'], 7) === '14.00' ? '10.00' : 'x');
        $this->assertSame('10.00', CirculationRules::fineFor(['fine_cap' => '10.00'] + $rule, 7));
    }

    public function test_rules_differ_by_member_type_and_follow_settings(): void
    {
        $this->assertSame(14, CirculationRules::rule('student')['loan_days']);
        $this->assertSame(30, CirculationRules::rule('faculty')['loan_days']);
        $this->assertSame(14, CirculationRules::rule('nonsense')['loan_days'], 'unknown types use the student rule');

        LibrarySettings::update(['loan_rules' => ['student' => ['loan_days' => '10', 'fine_per_day' => '5', 'fine_cap' => ''], 'staff' => ['max_items' => 8]]]);
        $this->assertSame(10, CirculationRules::rule('student')['loan_days']);
        $this->assertSame('5.00', CirculationRules::rule('student')['fine_per_day']);
        $this->assertNull(CirculationRules::rule('student')['fine_cap']);
        $this->assertSame(8, CirculationRules::rule('staff')['max_items']);
        $this->assertSame(21, CirculationRules::rule('staff')['loan_days'], 'unset values keep their default');
    }

    // ===================== issue =====================

    public function test_issuing_creates_a_numbered_loan_with_due_dates_and_marks_copies(): void
    {
        $a = $this->copies(1)[0];
        $b = $this->copies(1, app(SaveBook::class)(['type' => 'physical', 'title' => 'Second Title']))[0];
        $loan = $this->issue([$a->barcode, $b->accession_no]);          // a scanned barcode or the accession number

        $this->assertSame('LN-000001', $loan->loan_no);
        $this->assertCount(2, $loan->items);
        $this->assertTrue($loan->items->every(fn ($i) => $i->due_on->toDateString() === '2026-11-02'));
        $this->assertSame(['issued', 'issued'], BookCopy::orderBy('id')->pluck('status')->all());
        $this->assertSame(['acquired', 'issued'], $a->events()->pluck('type')->reverse()->values()->all());
        $this->assertNotNull(\App\Models\AuditLog::where('action', 'circulation.issued')->first());

        $this->assertSame('LN-000002', $this->issue([$this->copies(1, app(SaveBook::class)(['type' => 'physical', 'title' => 'Other']))[0]->barcode])->loan_no);
    }

    public function test_issue_rules(): void
    {
        [$a, $b, $c] = $this->copies(3);

        $this->fails(fn () => $this->issue([]), 'at least one');
        $this->fails(fn () => $this->issue(['NOPE-1']), 'No book found');
        $this->fails(fn () => $this->issue([$a->barcode, $b->barcode]), 'already has');        // same title twice
        $this->assertSame(0, LoanItem::count(), 'a failed issue changes nothing');
        $this->assertSame('available', $a->fresh()->status);

        $this->issue([$a->barcode]);
        $this->fails(fn () => $this->issue([$a->barcode], $this->member('9000000002')), 'it is issued');

        foreach ([['damaged', 'damaged'], ['lost', 'lost'], ['withdrawn', 'withdrawn']] as [$status, $word]) {
            $copy = $this->copies(1, app(SaveBook::class)(['type' => 'physical', 'title' => "T {$status}"]))[0];
            $copy->forceFill(['status' => $status])->save();
            $this->fails(fn () => $this->issue([$copy->barcode], $this->member('90000001'.strlen($status))), $word);
        }

        // archived titles and e-books cannot be issued
        $arch = $this->copies(1, app(SaveBook::class)(['type' => 'physical', 'title' => 'Archived']))[0];
        $arch->book->forceFill(['status' => 'archived'])->save();
        $this->fails(fn () => $this->issue([$arch->barcode], $this->member('9000000003')), 'not available');
    }

    public function test_the_member_limit_and_status_checks(): void
    {
        $titles = collect(range(1, 4))->map(fn ($i) => $this->copies(1, app(SaveBook::class)(['type' => 'physical', 'title' => "Book {$i}"]))[0]);

        $this->issue([$titles[0]->barcode, $titles[1]->barcode, $titles[2]->barcode]);              // student limit is 3
        $this->fails(fn () => $this->issue([$titles[3]->barcode]), '3 books');

        $faculty = $this->member('9000000004', ['member_type' => 'faculty']);
        $this->assertSame('Book 4', $this->issue([$titles[3]->barcode], $faculty)->items->first()->book->title);

        // no running membership / suspended
        $none = $this->member('9000000005', [], false);
        $extra = $this->copies(1, app(SaveBook::class)(['type' => 'physical', 'title' => 'Extra']))[0];
        $this->fails(fn () => $this->issue([$extra->barcode], $none), 'no running membership');
        LibrarySettings::update(['circulation_require_membership' => false]);
        $this->assertNotNull($this->issue([$extra->barcode], $none));

        $susp = $this->member('9000000006', ['status' => 'suspended'], false);
        $more = $this->copies(1, app(SaveBook::class)(['type' => 'physical', 'title' => 'More']))[0];
        $this->fails(fn () => $this->issue([$more->barcode], $susp), 'suspended');
    }

    public function test_unpaid_fines_can_block_new_issues(): void
    {
        [$a] = $this->copies(1);
        Fine::create(['student_id' => $this->student->id, 'type' => 'overdue', 'amount' => '50.00']);

        $this->assertNotNull($this->issue([$a->barcode]), 'no limit set: allowed');
        app(ReturnBooks::class)([['barcode' => $a->barcode]]);

        LibrarySettings::update(['block_issue_fines_over' => 40]);
        $this->fails(fn () => $this->issue([$a->barcode]), 'Unpaid fines');
        $this->assertNotNull(IssueBooks::blockReason($this->student));

        LibrarySettings::update(['block_issue_fines_over' => 60]);
        $this->assertNull(IssueBooks::blockReason($this->student));
    }

    public function test_only_one_open_loan_per_copy_is_enforced_by_the_database(): void
    {
        [$a] = $this->copies(1);
        $loan = $this->issue([$a->barcode]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('loan_items')->insert([
            'library_id' => $this->library->id, 'loan_id' => $loan->id, 'student_id' => $this->student->id, 'book_id' => $a->book_id, 'book_copy_id' => $a->id,
            'issued_at' => now(), 'due_on' => today()->addDays(7), 'status' => 'issued', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_permissions_and_module(): void
    {
        [$a] = $this->copies(1);

        $this->actAs(LibraryRoles::ACCOUNTANT);
        $this->expectException(HttpException::class);
        $this->issue([$a->barcode]);
    }

    public function test_a_library_without_the_module_cannot_issue(): void
    {
        [$a] = $this->copies(1);
        $this->actingAs($this->root);
        app(ManageLibraryModule::class)->revoke($this->library, 'books', 'test');
        $this->actingAs($this->owner);

        $this->fails(fn () => $this->issue([$a->barcode]), 'not enabled');
    }

    // ===================== return & fines =====================

    public function test_an_on_time_return_frees_the_copy_and_gives_a_receipt(): void
    {
        [$a] = $this->copies(1);
        $this->issue([$a->barcode]);

        $r = app(ReturnBooks::class)([['barcode' => $a->barcode]]);

        $this->assertSame('RT-000001', $r['receipt_no']);
        $this->assertSame([], $r['fines']);
        $this->assertSame('available', $a->fresh()->status);
        $item = LoanItem::first();
        $this->assertSame('returned', $item->status);
        $this->assertSame('RT-000001', $item->return_receipt_no);

        $this->fails(fn () => app(ReturnBooks::class)([['barcode' => $a->barcode]]), 'not out on loan');
        $this->fails(fn () => app(ReturnBooks::class)([['barcode' => 'NOPE']]), 'No book found');
        $this->fails(fn () => app(ReturnBooks::class)([]));
    }

    public function test_a_late_return_creates_an_overdue_fine_that_respects_closed_days(): void
    {
        [$a] = $this->copies(1);
        $this->issue([$a->barcode]);                         // due Mon 2 Nov
        Carbon::setTestNow('2026-11-09 10:00:00');           // returned the next Monday: 3-8 Nov, 1 Sunday (8th) skipped => 6 days

        $r = app(ReturnBooks::class)([['barcode' => $a->barcode]]);

        $this->assertCount(1, $r['fines']);
        $fine = $r['fines'][0];
        $this->assertSame('overdue', $fine->type);
        $this->assertSame(6, $fine->days);
        $this->assertSame('12.00', (string) $fine->amount);
        $this->assertSame('pending', $fine->status);
        $this->assertSame($this->student->id, $fine->student_id);
    }

    public function test_partial_returns_of_a_multi_book_loan(): void
    {
        $b1 = $this->copies(1)[0];
        $b2 = $this->copies(1, app(SaveBook::class)(['type' => 'physical', 'title' => 'Second']))[0];
        $this->issue([$b1->barcode, $b2->barcode]);

        app(ReturnBooks::class)([['barcode' => $b1->barcode]]);

        $this->assertSame(1, LoanItem::open()->count());
        $this->assertSame('issued', $b2->fresh()->status);
        $this->assertSame('available', $b1->fresh()->status);
    }

    public function test_lost_and_damaged_returns(): void
    {
        [$a, $b] = $this->copies(2);
        $other = $this->copies(1, app(SaveBook::class)(['type' => 'physical', 'title' => 'Other', 'purchase_price' => '100']))[0];
        $this->issue([$a->barcode, $other->barcode]);

        $r = app(ReturnBooks::class)([['barcode' => $a->barcode, 'condition' => 'lost'], ['barcode' => $other->barcode, 'condition' => 'damaged', 'fee' => '40']]);

        $this->assertSame(['lost', 'damaged'], collect($r['fines'])->pluck('type')->all());
        $this->assertSame('350.00', (string) $r['fines'][0]->amount, 'lost = the copy price');
        $this->assertSame('40.00', (string) $r['fines'][1]->amount);
        $this->assertSame('lost', $a->fresh()->status);
        $this->assertSame('damaged', $other->fresh()->status);
        $this->assertSame('lost', LoanItem::where('book_copy_id', $a->id)->first()->status);

        LibrarySettings::update(['lost_fine_multiplier' => 2, 'lost_fine_extra' => 25]);
        $b->book->forceFill([])->save();
        $this->issue([$b->barcode], $this->member('9000000002'));
        $r2 = app(ReturnBooks::class)([['barcode' => $b->barcode, 'condition' => 'lost']]);
        $this->assertSame('725.00', (string) $r2['fines'][0]->amount, '350 x 2 + 25');

        $this->fails(fn () => app(ReturnBooks::class)([['barcode' => 'x', 'condition' => 'broken']]));
    }

    public function test_lost_or_damaged_handling_needs_manager_authority(): void
    {
        [$a] = $this->copies(1);
        $this->issue([$a->barcode]);

        $this->actAs(LibraryRoles::RECEPTIONIST);
        $this->expectException(HttpException::class);
        app(ReturnBooks::class)([['barcode' => $a->barcode, 'condition' => 'lost']]);
    }

    public function test_receptionists_can_return_good_books(): void
    {
        [$a] = $this->copies(1);
        $this->issue([$a->barcode]);

        $this->actAs(LibraryRoles::RECEPTIONIST);
        $this->assertSame('RT-000001', app(ReturnBooks::class)([['barcode' => $a->barcode]])['receipt_no']);
    }

    public function test_repeat_late_returns_alert_the_library_once_a_day(): void
    {
        LibrarySettings::update(['repeat_overdue_alert' => 2]);
        foreach ([1, 2] as $i) {
            $copy = $this->copies(1, app(SaveBook::class)(['type' => 'physical', 'title' => "Late {$i}"]))[0];
            Carbon::setTestNow('2026-10-19 10:00:00');
            $this->issue([$copy->barcode]);
            Carbon::setTestNow('2026-11-09 10:00:00');
            app(ReturnBooks::class)([['barcode' => $copy->barcode]]);
        }

        $this->assertSame(1, $this->owner->notifications()->where('data->kind', 'circulation.repeat_overdue')->count());
    }

    // ===================== renew =====================

    public function test_renewal_extends_from_the_due_date_until_the_limit(): void
    {
        [$a] = $this->copies(1);
        $this->issue([$a->barcode]);                                                    // due 2 Nov
        $item = LoanItem::first();

        $item = app(RenewLoanItem::class)->staff($item);
        $this->assertSame('2026-11-16', $item->due_on->toDateString());                  // + 14 days from the due date, not from today
        $this->assertSame(1, $item->renewals);
        app(RenewLoanItem::class)->staff($item);
        $this->fails(fn () => app(RenewLoanItem::class)->staff($item->fresh()), 'most allowed');

        LibrarySettings::update(['loan_rules' => ['student' => ['max_renewals' => 0]]]);
        $this->assertSame('Renewals are not allowed.', RenewLoanItem::blockReason($item->fresh()));
    }

    public function test_overdue_ended_membership_and_returned_items_cannot_be_renewed(): void
    {
        [$a] = $this->copies(1);
        $this->issue([$a->barcode]);
        $item = LoanItem::first();

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->fails(fn () => app(RenewLoanItem::class)->staff($item), 'overdue');
        LibrarySettings::update(['allow_renew_overdue' => true]);
        $renewed = app(RenewLoanItem::class)->staff($item->fresh());
        $this->assertSame('2026-11-19', $renewed->due_on->toDateString(), 'an overdue renewal counts from today');

        Carbon::setTestNow('2026-10-19 10:00:00');
        LibrarySettings::update(['allow_renew_overdue' => false]);
        $this->student->memberships()->update(['ends_on' => today()->subDay()]);
        $this->fails(fn () => app(RenewLoanItem::class)->staff($item->fresh()), 'membership');

        $this->student->memberships()->update(['ends_on' => today()->addMonth()]);
        app(ReturnBooks::class)([['item' => $item->id]]);
        $this->fails(fn () => app(RenewLoanItem::class)->staff($item->fresh()), 'not out on loan');
    }

    public function test_a_member_can_renew_only_their_own_books(): void
    {
        $this->student->update(['email' => 'priya@x.test']);
        $login = app(CreateStudentLogin::class)($this->student)->fresh();
        $otherStudent = $this->member('9000000002');
        [$a, $b] = $this->copies(2);
        $this->issue([$a->barcode]);
        $this->issue([$b->barcode], $otherStudent);
        $mine = LoanItem::where('student_id', $this->student->id)->first();
        $theirs = LoanItem::where('student_id', $otherStudent->id)->first();

        $this->actingAs($login);
        Tenancy::set($this->library);
        $this->assertSame(1, app(RenewLoanItem::class)->self($mine)->renewals);

        try {
            app(RenewLoanItem::class)->self($theirs);
            $this->fail("renewed someone else's book");
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->actingAs($this->owner);
        LibrarySettings::update(['student_can_renew' => false]);
        $this->actingAs($login);
        $this->fails(fn () => app(RenewLoanItem::class)->self($mine->fresh()), 'ask the library');
    }

    // ===================== fines =====================

    protected function fine(string $amount = '100.00', string $type = 'overdue'): Fine
    {
        return Fine::create(['student_id' => $this->student->id, 'type' => $type, 'amount' => $amount]);
    }

    public function test_collecting_a_fine_gives_a_receipt_and_cannot_be_repeated(): void
    {
        $f = $this->fine('80.00');
        $paid = app(SettleFine::class)->collect($f, 'upi', 'UTR1');

        $this->assertSame('paid', $paid->status);
        $this->assertSame('FN-000001', $paid->receipt_no);
        $this->assertSame('80.00', (string) $paid->paid_amount);
        $this->assertSame('0.00', $paid->outstanding());

        $this->fails(fn () => app(SettleFine::class)->collect($f->fresh(), 'cash'), 'already settled');
        $this->fails(fn () => app(SettleFine::class)->collect($this->fine(), 'bitcoin'), 'method');
        $this->assertSame('FN-000002', app(SettleFine::class)->collect($this->fine(), 'cash')->receipt_no);
    }

    public function test_waiving_needs_authority_a_reason_and_can_be_partial(): void
    {
        $f = $this->fine('100.00');

        $this->fails(fn () => app(SettleFine::class)->waive($f, ' '), 'reason');
        $this->fails(fn () => app(SettleFine::class)->waive($f, 'x', '150'), 'at most');
        $this->fails(fn () => app(SettleFine::class)->waive($f, 'x', '0'));

        $part = app(SettleFine::class)->waive($f, 'Hardship', '30');
        $this->assertSame('pending', $part->status);
        $this->assertSame('70.00', $part->outstanding());
        $this->assertSame('70.00', (string) app(SettleFine::class)->collect($part, 'cash')->paid_amount, 'only what is left is collected');

        $full = app(SettleFine::class)->waive($this->fine('40.00'), 'Library error');
        $this->assertSame('waived', $full->status);
        $this->assertNotNull(\App\Models\AuditLog::where('action', 'fine.waived')->first());

        $other = $this->fine('10.00');
        $this->actAs(LibraryRoles::MANAGER);                 // managers collect but cannot waive
        $this->expectException(HttpException::class);
        app(SettleFine::class)->waive($other, 'x');
    }

    // ===================== reversals =====================

    public function test_a_mistaken_issue_can_be_reversed_by_a_manager(): void
    {
        [$a] = $this->copies(1);
        $this->issue([$a->barcode]);
        $item = LoanItem::first();

        $this->fails(fn () => app(ReverseCirculation::class)->issue($item, ' '), 'reason');
        app(ReverseCirculation::class)->issue($item, 'Wrong member');

        $this->assertSame('reversed', $item->fresh()->status);
        $this->assertSame('available', $a->fresh()->status);
        $this->assertSame(0, LoanItem::open()->count());
        $this->assertNotNull($this->issue([$a->barcode]), 'the copy can be issued again');
        $this->fails(fn () => app(ReverseCirculation::class)->issue($item->fresh(), 'again'), 'still out on loan');
    }

    public function test_a_mistaken_return_can_be_reversed_and_removes_its_unpaid_fines(): void
    {
        [$a] = $this->copies(1);
        $this->issue([$a->barcode]);
        Carbon::setTestNow('2026-11-09 10:00:00');
        app(ReturnBooks::class)([['barcode' => $a->barcode]]);
        $item = LoanItem::first();
        $this->assertSame(1, Fine::count());

        app(ReverseCirculation::class)->return($item, 'Returned the wrong book');

        $this->assertSame('issued', $item->fresh()->status);
        $this->assertSame('issued', $a->fresh()->status);
        $this->assertSame(0, Fine::count());
        $this->assertNull($item->fresh()->returned_at);
    }

    public function test_a_return_cannot_be_reversed_after_its_fine_was_paid_or_the_copy_moved_on(): void
    {
        [$a, $b] = $this->copies(2);
        $this->issue([$a->barcode]);
        Carbon::setTestNow('2026-11-09 10:00:00');
        app(ReturnBooks::class)([['barcode' => $a->barcode]]);
        $item = LoanItem::first();

        app(SettleFine::class)->collect(Fine::first(), 'cash');
        $this->fails(fn () => app(ReverseCirculation::class)->return($item, 'x'), 'already collected');

        // a copy that was issued again cannot be rolled back
        $this->issue([$b->barcode]);
        app(ReturnBooks::class)([['barcode' => $b->barcode]]);
        $second = LoanItem::where('book_copy_id', $b->id)->first();
        $this->issue([$b->barcode], $this->member('9000000002'));
        $this->fails(fn () => app(ReverseCirculation::class)->return($second, 'x'), 'handled');
    }

    public function test_reversals_need_manager_authority(): void
    {
        [$a] = $this->copies(1);
        $this->issue([$a->barcode]);
        $item = LoanItem::first();

        $this->actAs(LibraryRoles::RECEPTIONIST);
        $this->expectException(HttpException::class);
        app(ReverseCirculation::class)->issue($item, 'x');
    }

    // ===================== isolation =====================

    public function test_loans_and_fines_are_private_to_their_library(): void
    {
        [$a] = $this->copies(1);
        $this->issue([$a->barcode]);
        $this->fine();

        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        Tenancy::set($other);

        $this->assertSame(0, LoanItem::count());
        $this->assertSame(0, Fine::count());
        $this->assertSame(0, BookCopy::count());
    }
}
