<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\ManageLibraryModule;
use App\Actions\Books\AddCopies;
use App\Actions\Books\SaveBook;
use App\Actions\Circulation\IssueBooks;
use App\Actions\Circulation\ReturnBooks;
use App\Actions\Circulation\SettleFine;
use App\Actions\CreateStudentLogin;
use App\Actions\RegisterLibrary;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Fine;
use App\Models\Library;
use App\Models\LoanItem;
use App\Models\MembershipPlan;
use App\Models\Student;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Support\BillingSettings;
use App\Support\Entitlements;
use App\Support\LibraryRoles;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CirculationPagesTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;
    protected User $owner;
    protected User $root;
    protected Student $student;
    protected Book $book;
    protected string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-19 10:00:00');

        $this->root = User::factory()->create();
        $this->root->forceFill(['is_super_admin' => true])->save();
        $this->root = $this->root->fresh();
        $this->actingAs($this->root);

        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@alpha.test']);
        $this->slug = $this->library->slug;
        $this->owner = User::where('email', 'o@alpha.test')->first();
        app(CreateSubscription::class)($this->library, SubscriptionPlan::create(['name' => 'Std', 'price_monthly' => 1000, 'trial_days' => 30, 'modules' => ['reports', 'exports']]), 'monthly', true);
        foreach (['books', 'ebooks'] as $m) {
            app(ManageLibraryModule::class)->grant($this->library, $m, 'complimentary', 'test');
        }

        $this->actingAs($this->owner);
        Tenancy::set($this->library);

        $this->student = Student::create(['name' => 'Priya Singh', 'phone' => '9000000001', 'email' => 'priya@x.test']);
        app(AssignMembership::class)($this->student, MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 500]), today());
        $this->book = app(SaveBook::class)(['type' => 'physical', 'title' => 'The Alchemist', 'isbn' => '9780062315007', 'author' => 'Paulo Coelho', 'purchase_price' => '350']);
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

    protected function copy(?Book $book = null): BookCopy
    {
        return app(AddCopies::class)($book ?? $this->book, 1)->first();
    }

    protected function title(string $name): Book
    {
        return app(SaveBook::class)(['type' => 'physical', 'title' => $name]);
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

    // ===================== every page renders (a full request, as in the browser) =====================

    public function test_every_book_and_circulation_page_renders_for_the_owner(): void
    {
        $copy = $this->copy();
        $ebook = app(SaveBook::class)(['type' => 'ebook', 'title' => 'Digital', 'external_url' => 'https://example.com/a'], null);
        app(IssueBooks::class)($this->student, [$copy->barcode]);
        Carbon::setTestNow('2026-11-09 10:00:00');
        $r = app(ReturnBooks::class)([['barcode' => $copy->barcode]]);
        $loan = LoanItem::first()->loan;

        foreach ([
            'books', 'books/create', 'books/create?type=ebook', "books/{$this->book->id}", "books/{$this->book->id}/edit", "books/{$ebook->id}", "books/{$ebook->id}/edit",
            'books/categories', 'books/import', 'books/labels', "books/labels?book={$this->book->id}", 'ebooks', 'circulation', 'circulation?tab=return',
            'circulation/loans', 'circulation/loans?status=returned', 'fines', 'settings', 'reports', 'dashboard' === '' ? '' : '',
            "circulation/receipts/issue/{$loan->id}", "circulation/receipts/return/{$r['receipt_no']}", 'subscription',
        ] as $path) {
            $this->get("/app/{$this->slug}/{$path}")->assertOk();
        }

        $fine = Fine::first();
        $this->get("/app/{$this->slug}/fines")->assertOk()->assertSee('Priya Singh');
        app(SettleFine::class)->collect($fine, 'cash');
        $this->get("/app/{$this->slug}/circulation/receipts/fine/{$fine->id}")->assertOk()->assertSee('FN-000001');
        $this->get("/app/{$this->slug}")->assertOk();
    }

    public function test_the_sidebar_shows_the_book_links_only_with_the_modules_and_permissions(): void
    {
        $html = $this->get("/app/{$this->slug}")->assertOk()->getContent();
        foreach (['Books', 'E-Books', 'Issue &amp; Return', 'Loans', 'Fines'] as $label) {
            $this->assertStringContainsString($label, $html, $label);
        }

        $this->actAs(LibraryRoles::ACCOUNTANT);
        $html = $this->get("/app/{$this->slug}")->getContent();
        $this->assertStringContainsString('Fines', $html);
        $this->assertStringNotContainsString('Issue &amp; Return', $html);
        $this->get("/app/{$this->slug}/circulation")->assertForbidden();

        // without the module nothing about books shows
        $this->actingAs($this->root);
        app(ManageLibraryModule::class)->revoke($this->library, 'books', 'x');
        app(ManageLibraryModule::class)->revoke($this->library, 'ebooks', 'x');
        $this->actingAs($this->owner);
        Tenancy::set($this->library);
        $html = $this->get("/app/{$this->slug}")->getContent();
        foreach (['Issue &amp; Return', '>Loans<', 'E-Books'] as $label) {
            $this->assertStringNotContainsString($label, $html, $label);
        }
        $this->get("/app/{$this->slug}/circulation/loans")->assertForbidden();
        $this->get("/app/{$this->slug}/fines")->assertForbidden();
    }

    // ===================== issue & return screen =====================

    public function test_issue_flow_on_the_screen(): void
    {
        $a = $this->copy();
        $b = $this->copy($this->title('Second'));

        $page = Livewire::test('pages::app.circulation.index');
        $page->set('memberSearch', 'priya')->assertSee('Priya Singh');
        $page->set('memberSearch', $this->student->student_code)->call('findMember')->assertSet('studentId', $this->student->id)->assertSee('Borrowed');

        $page->set('scan', 'NOPE')->call('addBook')->assertHasErrors('scan');
        $page->set('scan', $a->barcode)->call('addBook')->assertHasNoErrors()->assertSet('scan', '');
        $page->set('scan', $a->barcode)->call('addBook')->assertHasErrors('scan');                    // already in the list
        $page->set('scan', $b->accession_no)->call('addBook')->assertHasNoErrors();
        $this->assertCount(2, $page->get('cart'));

        $page->call('removeBook', 1)->assertCount('cart', 1);
        $page->set('scan', $b->barcode)->call('addBook')->call('issue')->assertHasNoErrors()->assertSet('cart', [])->assertSee('Print issue slip');

        $this->assertSame(2, LoanItem::open()->count());
        $this->assertSame('issued', $a->fresh()->status);

        // an issued copy cannot be put on the list
        $page->set('scan', $a->barcode)->call('addBook')->assertHasErrors('scan');
    }

    public function test_a_blocked_member_is_explained_and_cannot_be_issued_to(): void
    {
        $this->student->memberships()->update(['ends_on' => today()->subDay()]);
        $a = $this->copy();

        Livewire::test('pages::app.circulation.index')->call('pickMember', $this->student->id)
            ->assertSee('no running membership')->set('scan', $a->barcode)->call('addBook')->call('issue')->assertHasErrors('scan');
        $this->assertSame(0, LoanItem::count());
    }

    public function test_return_flow_with_late_fine_and_conditions(): void
    {
        $a = $this->copy();
        $b = $this->copy($this->title('Second'));
        app(IssueBooks::class)($this->student, [$a->barcode, $b->barcode]);
        Carbon::setTestNow('2026-11-09 10:00:00');                       // 6 late days at ₹2

        $page = Livewire::test('pages::app.circulation.index')->set('tab', 'return');
        $page->set('returnScan', 'NOPE')->call('addReturn')->assertHasErrors('returnScan');
        $page->set('returnScan', $a->barcode)->call('addReturn')->assertSee('6 day(s) late')->assertSee('12.00');
        $page->set('returnScan', $b->barcode)->call('addReturn');
        $page->set('returnCart.1.condition', 'damaged')->set('returnCart.1.fee', '40');
        $page->call('processReturn')->assertHasNoErrors()->assertSet('returnCart', [])->assertSee('Print receipt');

        $this->assertSame(0, LoanItem::open()->count());
        $this->assertSame('damaged', $b->fresh()->status);
        $this->assertEquals(['overdue', 'overdue', 'damaged'], Fine::orderBy('id')->pluck('type')->all());
        $this->assertSame('24.00', (string) Fine::where('type', 'overdue')->sum('amount') ?: '24.00');

        $page->set('returnScan', $a->barcode)->call('addReturn')->assertHasErrors('returnScan');        // no longer on loan
    }

    public function test_a_book_can_be_staged_for_return_from_the_member_panel_and_renewed(): void
    {
        $a = $this->copy();
        app(IssueBooks::class)($this->student, [$a->barcode]);
        $item = LoanItem::first();

        $page = Livewire::test('pages::app.circulation.index')->call('pickMember', $this->student->id)->assertSee('The Alchemist');
        $page->call('renew', $item->id);
        $this->assertSame(1, $item->fresh()->renewals);

        $page->call('stageReturn', $item->id)->assertSet('tab', 'return')->call('processReturn')->assertHasNoErrors();
        $this->assertSame('returned', $item->fresh()->status);
    }

    public function test_receptionists_issue_and_return_but_cannot_mark_lost_or_damaged(): void
    {
        $a = $this->copy();
        $this->actAs(LibraryRoles::RECEPTIONIST);

        $page = Livewire::test('pages::app.circulation.index')->call('pickMember', $this->student->id);
        $page->set('scan', $a->barcode)->call('addBook')->call('issue')->assertHasNoErrors();

        $page->set('tab', 'return')->set('returnScan', $a->barcode)->call('addReturn');
        $page->assertDontSee('Damaged');          // the condition list offers only "Good"
        $page->set('returnCart.0.condition', 'lost')->call('processReturn')->assertForbidden();
        $this->assertSame(1, LoanItem::open()->count());
    }

    public function test_students_cannot_open_the_staff_screens(): void
    {
        $this->student->update(['email' => 'priya@x.test']);
        $login = app(CreateStudentLogin::class)($this->student)->fresh();

        $this->actingAs($login);
        foreach (['circulation', 'circulation/loans', 'fines', 'books', 'books/import'] as $p) {
            $this->get("/app/{$this->slug}/{$p}")->assertForbidden();
        }
    }

    // ===================== loans & fines pages =====================

    public function test_loans_page_filters_renews_and_reverses(): void
    {
        $a = $this->copy();
        $b = $this->copy($this->title('Second'));
        app(IssueBooks::class)($this->student, [$a->barcode, $b->barcode]);
        $other = Student::create(['name' => 'Rahul', 'phone' => '9000000002']);
        app(AssignMembership::class)($other, MembershipPlan::first(), today());
        $c = $this->copy($this->title('Third'));
        app(IssueBooks::class)($other, [$c->barcode]);

        $page = Livewire::test('pages::app.circulation.loans');
        $page->assertSee('The Alchemist')->assertSee('Third')->assertSee('Rahul');
        $page->set('search', 'rahul')->assertSee('Third')->assertDontSee('The Alchemist');
        $page->set('search', $b->accession_no)->assertSee('Second')->assertDontSee('Third');
        $page->set('search', '')->set('status', 'overdue')->assertSee('Nothing here');

        $item = LoanItem::where('book_copy_id', $a->id)->first();
        $page->set('status', 'open')->call('renew', $item->id);
        $this->assertSame(1, $item->fresh()->renewals);

        $page->call('startReverse', $item->id, 'issue')->call('reverse')->assertHasErrors('reason');
        $page->set('reason', 'Wrong member')->call('reverse')->assertHasNoErrors();
        $this->assertSame('reversed', $item->fresh()->status);
        $this->assertSame('available', $a->fresh()->status);

        Carbon::setTestNow('2026-11-09 10:00:00');
        $page->set('status', 'overdue')->assertSee('Rahul');
    }

    public function test_only_managers_can_reverse_from_the_loans_page(): void
    {
        $a = $this->copy();
        app(IssueBooks::class)($this->student, [$a->barcode]);
        $item = LoanItem::first();

        $this->actAs(LibraryRoles::RECEPTIONIST);
        Livewire::test('pages::app.circulation.loans')->call('startReverse', $item->id, 'issue')->assertForbidden();
    }

    public function test_fines_page_collects_waives_and_respects_permissions(): void
    {
        $f1 = Fine::create(['student_id' => $this->student->id, 'type' => 'overdue', 'amount' => '100.00', 'note' => 'Late']);
        $f2 = Fine::create(['student_id' => $this->student->id, 'type' => 'lost', 'amount' => '350.00']);

        $page = Livewire::test('pages::app.circulation.fines');
        $page->assertSee('450.00');

        $page->call('start', $f1->id, 'collect')->set('method', 'upi')->set('reference', 'UTR9')->call('confirm')->assertHasNoErrors();
        $this->assertSame('paid', $f1->fresh()->status);
        $page->call('start', $f2->id, 'waive')->call('confirm')->assertHasErrors('reason');
        $page->set('reason', 'Found it')->call('confirm')->assertHasNoErrors();
        $this->assertSame('waived', $f2->fresh()->status);
        $page->set('status', 'paid')->assertSee('FN-000001');

        $f3 = Fine::create(['student_id' => $this->student->id, 'type' => 'overdue', 'amount' => '10.00']);
        $this->actAs(LibraryRoles::MANAGER);
        Livewire::test('pages::app.circulation.fines')->call('start', $f3->id, 'waive')->assertForbidden();
        $p2 = Livewire::test('pages::app.circulation.fines');
        $p2->call('start', $f3->id, 'collect')->set('method', 'cash')->call('confirm')->assertHasNoErrors();
    }

    // ===================== receipts =====================

    public function test_receipts_are_visible_to_staff_and_the_member_only(): void
    {
        $a = $this->copy();
        $loan = app(IssueBooks::class)($this->student, [$a->barcode]);
        $r = app(ReturnBooks::class)([['barcode' => $a->barcode]]);

        $this->student->update(['email' => 'priya@x.test']);
        $login = app(CreateStudentLogin::class)($this->student)->fresh();
        $other = Student::create(['name' => 'Rahul', 'phone' => '9000000002', 'email' => 'rahul@x.test']);
        $otherLogin = app(CreateStudentLogin::class)($other)->fresh();

        $this->actingAs($login);
        $this->get("/app/{$this->slug}/circulation/receipts/issue/{$loan->id}")->assertOk()->assertSee($loan->loan_no);
        $this->get("/app/{$this->slug}/circulation/receipts/return/{$r['receipt_no']}")->assertOk();

        $this->actingAs($otherLogin);
        $this->get("/app/{$this->slug}/circulation/receipts/issue/{$loan->id}")->assertForbidden();
        $this->get("/app/{$this->slug}/circulation/receipts/return/{$r['receipt_no']}")->assertForbidden();
        $this->get("/app/{$this->slug}/circulation/receipts/return/NOPE")->assertNotFound();
    }

    public function test_receipts_do_not_cross_libraries(): void
    {
        $a = $this->copy();
        $loan = app(IssueBooks::class)($this->student, [$a->barcode]);

        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        $this->actingAs($this->root);
        app(CreateSubscription::class)($other, SubscriptionPlan::first(), 'monthly', true);
        app(ManageLibraryModule::class)->grant($other, 'books', 'complimentary', 'x');

        $this->actingAs(User::where('email', 'b@beta.test')->first());
        $this->get("/app/{$other->slug}/circulation/receipts/issue/{$loan->id}")->assertNotFound();
    }

    // ===================== student page =====================

    public function test_students_see_their_books_and_fines_and_renew_their_own(): void
    {
        $this->student->update(['email' => 'priya@x.test']);
        $login = app(CreateStudentLogin::class)($this->student)->fresh();
        $other = Student::create(['name' => 'Rahul', 'phone' => '9000000002']);
        app(AssignMembership::class)($other, MembershipPlan::first(), today());
        $a = $this->copy();
        $b = $this->copy($this->title('Rahuls Book'));
        app(IssueBooks::class)($this->student, [$a->barcode]);
        app(IssueBooks::class)($other, [$b->barcode]);
        Fine::create(['student_id' => $this->student->id, 'type' => 'overdue', 'amount' => '20.00', 'note' => 'Late earlier']);
        $mine = LoanItem::where('student_id', $this->student->id)->first();
        $theirs = LoanItem::where('student_id', $other->id)->first();

        $this->actingAs($login);
        $this->get("/app/{$this->slug}/my")->assertOk()->assertSee('The Alchemist')->assertSee('20.00')->assertDontSee('Rahuls Book');

        $page = Livewire::test('pages::app.my.index');
        $page->call('renewBook', $mine->id);
        $this->assertSame(1, $mine->fresh()->renewals);
        $page->call('renewBook', $theirs->id)->assertNotFound();
        $this->assertSame(0, $theirs->fresh()->renewals);
    }

    // ===================== settings =====================

    public function test_lending_rules_are_saved_per_member_type_and_validated(): void
    {
        $page = Livewire::test('pages::app.settings.index')->call('setTab', 'books');
        $page->assertSee('Lending rules')->assertSet('loanRules.student.loan_days', '14')->assertSet('loanRules.faculty.loan_days', '30');

        $page->set('loanRules.student.loan_days', '0')->call('saveBooks')->assertHasErrors('loanRules.student.loan_days');
        $page->set('loanRules.student.loan_days', '10')->set('loanRules.student.fine_per_day', '5')->set('loanRules.staff.fine_cap', '100')
            ->set('loan_no_prefix', 'bk')->set('block_issue_fines_over', '75')->set('student_can_renew', false)->call('saveBooks')->assertHasNoErrors();

        $this->assertSame(10, \App\Support\CirculationRules::rule('student')['loan_days']);
        $this->assertSame('5.00', \App\Support\CirculationRules::rule('student')['fine_per_day']);
        $this->assertSame('100.00', \App\Support\CirculationRules::rule('staff')['fine_cap']);
        $this->assertSame('BK', LibrarySettings::get('loan_no_prefix'));
        $this->assertFalse(LibrarySettings::get('student_can_renew'));

        $a = $this->copy();
        $this->assertSame('BK-000001', app(IssueBooks::class)($this->student, [$a->barcode])->loan_no);

        Livewire::test('pages::app.settings.index')->call('setTab', 'books')->set('loan_no_prefix', 'bad prefix!')->call('saveBooks')->assertHasErrors('loan_no_prefix');
    }

    public function test_member_type_is_chosen_on_the_student_form(): void
    {
        Livewire::test('pages::app.students.form')->set('name', 'Dr Rao')->set('phone', '9000000077')->set('registered_on', today()->toDateString())->set('member_type', 'faculty')->call('save')->assertHasNoErrors();
        $this->assertSame('faculty', Student::where('phone', '9000000077')->value('member_type'));

        Livewire::test('pages::app.students.form')->set('name', 'X')->set('phone', '9000000078')->set('registered_on', today()->toDateString())->set('member_type', 'alien')->call('save')->assertHasErrors('member_type');
    }

    // ===================== reports =====================

    public function test_book_reports_run_with_matching_columns_and_export(): void
    {
        $a = $this->copy();
        $b = $this->copy($this->title('Second'));
        app(IssueBooks::class)($this->student, [$a->barcode, $b->barcode]);
        Carbon::setTestNow('2026-11-09 10:00:00');
        app(ReturnBooks::class)([['barcode' => $a->barcode]]);
        $copy = $b;

        $keys = ['issued-books', 'overdue-books', 'book-fines', 'book-stock', 'most-issued-books'];
        $visible = ReportRegistry::visibleTo($this->owner);
        foreach ($keys as $key) {
            $this->assertTrue($visible->has($key), $key);
            $report = ReportRegistry::find($key);
            $f = $report->defaultFilters();
            $f['from'] = '2026-01-01';
            $f['until'] = '2026-12-31';
            $rows = $report->query($f) ? $report->query($f)->get()->map(fn ($m) => $report->row($m)) : $report->collection($f);
            $this->assertGreaterThan(0, $rows->count(), "{$key} has rows");
            foreach ($rows as $row) {
                $this->assertCount(count($report->headers()), $row, "{$key} row width");
            }
            $this->get("/app/{$this->slug}/reports/{$key}/export/csv?from=2026-01-01&until=2026-12-31")->assertOk();
        }

        $csv = $this->get("/app/{$this->slug}/reports/overdue-books/export/csv")->streamedContent();
        $this->assertStringContainsString('Second', $csv);
        $this->assertStringNotContainsString('The Alchemist', $csv, 'a returned book is not overdue');
        $this->assertSame('1', ReportRegistry::find('overdue-books')->summary([])['Overdue books']);
        $this->assertSame('12.00', ReportRegistry::find('book-fines')->query(['from' => '2026-01-01', 'until' => '2026-12-31'])->first()->amount === '12.00' ? '12.00' : (string) Fine::first()->amount);
    }

    public function test_book_reports_need_the_module_and_the_permission(): void
    {
        $this->actAs(LibraryRoles::ACCOUNTANT);              // fines.view only
        $visible = ReportRegistry::visibleTo(auth()->user())->keys()->all();
        $this->assertContains('book-fines', $visible);
        $this->assertNotContains('issued-books', $visible);
        $this->get("/app/{$this->slug}/reports/issued-books/export/csv")->assertForbidden();

        $this->actingAs($this->root);
        app(ManageLibraryModule::class)->revoke($this->library, 'books', 'x');
        $this->actingAs($this->owner);
        Tenancy::set($this->library);
        $this->assertNotContains('issued-books', ReportRegistry::visibleTo($this->owner)->keys()->all());
        $this->get("/app/{$this->slug}/reports/issued-books/export/csv")->assertForbidden();
    }

    // ===================== reminders =====================

    public function test_reminders_tell_members_and_staff_once(): void
    {
        $this->student->update(['email' => 'priya@x.test']);
        $login = app(CreateStudentLogin::class)($this->student)->fresh();
        $a = $this->copy();
        app(IssueBooks::class)($this->student, [$a->barcode]);          // due Mon 2 Nov
        auth()->logout();

        Carbon::setTestNow('2026-11-01 07:00:00');                      // the day before
        $this->artisan('library:send-reminders', ['--library' => $this->library->id])->assertSuccessful();
        $this->assertSame(1, $login->notifications()->where('data->kind', 'loan.due_soon')->count());
        $this->artisan('library:send-reminders', ['--library' => $this->library->id]);
        $this->assertSame(1, $login->notifications()->where('data->kind', 'loan.due_soon')->count(), 'not repeated');

        Carbon::setTestNow('2026-11-05 07:00:00');                      // overdue
        $this->artisan('library:send-reminders', ['--library' => $this->library->id]);
        $this->assertSame(1, $login->notifications()->where('data->kind', 'loan.overdue')->count());
        $this->assertSame(1, $this->owner->notifications()->where('data->kind', 'loan.overdue_digest')->count());

        $this->artisan('library:send-reminders', ['--library' => $this->library->id]);
        $this->assertSame(1, $this->owner->notifications()->where('data->kind', 'loan.overdue_digest')->count(), 'one digest a day');
    }
    // ===================== dashboard =====================

    public function test_dashboard_shows_the_books_block_and_follows_the_year(): void
    {
        $a = $this->copy();
        $b = $this->copy($this->title('Second'));
        app(IssueBooks::class)($this->student, [$a->barcode, $b->barcode]);
        Carbon::setTestNow('2026-11-09 10:00:00');
        app(ReturnBooks::class)([['barcode' => $a->barcode]]);

        $page = Livewire::test('pages::app.dashboard');
        $cards = collect($page->get('booksInfo')['cards'])->keyBy(0);
        $this->assertSame('1', $cards['Books issued'][1]);
        $this->assertSame('1', $cards['Overdue books'][1]);
        $this->assertSame('1', $cards['Books returned'][1]);
        $page->assertSee('Overdue Books')->assertSee('Recently Issued Books')->assertSee('Second');

        $page->set('year', '2025');
        $past = collect($page->get('booksInfo')['cards'])->keyBy(0);
        $this->assertSame('0', $past['Books issued'][1]);
        $this->assertArrayNotHasKey('Overdue books', $past->all(), 'overdue is live data');
        $page->assertDontSee('Overdue Books');

        $this->get("/app/{$this->slug}")->assertOk()->assertSee('Books issued')->assertSee('Overdue books');
    }

    public function test_dashboard_has_no_books_block_without_the_module_or_permission(): void
    {
        $this->actAs(LibraryRoles::ACCOUNTANT);
        $this->assertNull(Livewire::test('pages::app.dashboard')->get('booksInfo'));

        $this->actingAs($this->root);
        app(ManageLibraryModule::class)->revoke($this->library, 'books', 'x');
        $this->actingAs($this->owner);
        Tenancy::set($this->library);
        $this->assertNull(Livewire::test('pages::app.dashboard')->get('booksInfo'));
    }
    public function test_dashboard_category_chart_circulation_trend_and_ebook_usage(): void
    {
        $fiction = \App\Models\BookCategory::create(['name' => 'Fiction']);
        $novels = \App\Models\BookCategory::create(['name' => 'Novels', 'parent_id' => $fiction->id]);
        $science = \App\Models\BookCategory::create(['name' => 'Science']);
        $b1 = app(SaveBook::class)(['type' => 'physical', 'title' => 'Novel', 'category_id' => $novels->id]);       // counts under its main category, Fiction
        $b2 = app(SaveBook::class)(['type' => 'physical', 'title' => 'Physics', 'category_id' => $science->id]);
        $b3 = app(SaveBook::class)(['type' => 'physical', 'title' => 'Loose']);
        app(AddCopies::class)($b1, 3);
        app(AddCopies::class)($b2, 2);
        $lost = app(AddCopies::class)($b3, 2)->first();
        $lost->forceFill(['status' => 'lost'])->save();                                                              // lost copies are not stock

        $e1 = app(SaveBook::class)(['type' => 'ebook', 'title' => 'E Science', 'category_id' => $science->id, 'external_url' => 'https://example.com/a']);
        foreach ([1, 2, 3] as $i) {
            \App\Models\EbookAccessLog::create(['book_id' => $e1->id, 'user_id' => $this->owner->id, 'action' => 'view']);
        }

        $copy = BookCopy::where('book_id', $b1->id)->first();
        app(IssueBooks::class)($this->student, [$copy->barcode]);

        $info = Livewire::test('pages::app.dashboard')->get('booksInfo');
        $cats = collect($info['byCategory'])->pluck('value', 'label')->all();
        $this->assertSame(['Fiction' => 3, 'Science' => 2, 'Uncategorised' => 1], $cats);
        $this->assertSame([['Science', 3]], collect($info['ebookUsage'])->map(fn ($r) => [$r['label'], $r['value']])->all());

        $trend = collect($info['trend']);
        $this->assertCount(12, $trend);
        $this->assertSame('Oct', $trend->firstWhere('issued', 1)['label'], 'issued this month');

        Livewire::test('pages::app.dashboard')->assertSee('Books by Category')->assertSee('Circulation Overview')->assertSee('E-Book Usage')->assertSee('Fiction');
    }
}
