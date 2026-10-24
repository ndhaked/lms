<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\Billing\ApproveSubscriptionPayment;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\ManageLibraryModule;
use App\Actions\Billing\SubmitSubscriptionPayment;
use App\Actions\Books\AddCopies;
use App\Actions\Books\ChangeCopyStatus;
use App\Actions\Books\SaveBook;
use App\Actions\Books\StockTake;
use App\Actions\Circulation\IssueBooks;
use App\Actions\Circulation\ReturnBooks;
use App\Actions\Circulation\SettleFine;
use App\Actions\CreateStudentLogin;
use App\Actions\RecordPayment;
use App\Actions\RegisterLibrary;
use App\Exceptions\BusinessRuleException;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\Fine;
use App\Models\InventoryAudit;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Student;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\CirculationRules;
use App\Support\Entitlements;
use App\Support\IsbnLookup;
use App\Support\LibraryRoles;
use App\Support\LibrarySettings;
use App\Support\Qr;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BookExtrasTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;
    protected User $owner;
    protected User $root;
    protected Student $student;
    protected string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
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

    protected function book(string $title, array $over = [], int $copies = 1): Book
    {
        $b = app(SaveBook::class)(array_merge(['type' => 'physical', 'title' => $title], $over));
        if ($copies > 0) {
            app(AddCopies::class)($b, $copies);
        }

        return $b;
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

    // ===================== custom member types =====================

    public function test_member_types_default_to_the_three_built_in_ones(): void
    {
        $this->assertSame(['student' => 'Student', 'staff' => 'Staff', 'faculty' => 'Faculty'], CirculationRules::memberTypes());
    }

    public function test_a_library_can_add_member_types_with_their_own_rules(): void
    {
        $page = Livewire::test('pages::app.settings.index')->call('setTab', 'books');
        $page->set('newMemberType', 'Alumni')->call('addMemberType')->assertHasNoErrors()->assertSet('newMemberType', '');
        $page->set('newMemberType', 'alumni')->call('addMemberType')->assertHasErrors('newMemberType');        // same name
        $page->assertSet('loanRules.alumni.loan_days', '14');

        $page->set('loanRules.alumni.loan_days', '7')->set('loanRules.alumni.max_items', '1')->call('saveBooks')->assertHasNoErrors();

        $this->assertArrayHasKey('alumni', CirculationRules::memberTypes());
        $this->assertSame(7, CirculationRules::rule('alumni')['loan_days']);
        $this->assertSame(1, CirculationRules::rule('alumni')['max_items']);
        $this->assertSame(14, CirculationRules::rule('student')['loan_days'], 'other types are unchanged');
        $this->assertSame(14, CirculationRules::rule('gone')['loan_days'], 'an unknown type uses the student rule');
    }

    public function test_a_custom_type_is_chosen_on_the_student_form_and_drives_lending(): void
    {
        LibrarySettings::update(['member_types' => ['student' => 'Student', 'alumni' => 'Alumni'], 'loan_rules' => ['alumni' => ['loan_days' => 5, 'max_items' => 1]]]);

        Livewire::test('pages::app.students.form')->set('name', 'Old Boy')->set('phone', '9000000055')->set('registered_on', today()->toDateString())->set('member_type', 'alumni')->call('save')->assertHasNoErrors();
        Livewire::test('pages::app.students.form')->set('name', 'X')->set('phone', '9000000056')->set('registered_on', today()->toDateString())->set('member_type', 'staff')->call('save')->assertHasErrors('member_type');   // not one of this library's types

        $alumnus = Student::where('phone', '9000000055')->first();
        app(AssignMembership::class)($alumnus, MembershipPlan::first(), today());
        $a = $this->book('One')->copies()->first();
        $b = $this->book('Two')->copies()->first();

        $loan = app(IssueBooks::class)($alumnus, [$a->barcode]);
        $this->assertSame('2026-10-24', $loan->items->first()->due_on->toDateString(), '5 days');
        $this->fails(fn () => app(IssueBooks::class)($alumnus, [$b->barcode]), '1 book');
    }

    public function test_member_types_in_use_cannot_be_removed_and_student_always_stays(): void
    {
        LibrarySettings::update(['member_types' => ['student' => 'Student', 'alumni' => 'Alumni']]);
        Student::create(['name' => 'Old Boy', 'phone' => '9000000055', 'member_type' => 'alumni']);

        $page = Livewire::test('pages::app.settings.index')->call('setTab', 'books');
        $page->call('removeMemberType', 'alumni')->assertDispatched('notify');
        $page->assertSet('memberTypesList', ['student' => 'Student', 'alumni' => 'Alumni']);
        $page->call('removeMemberType', 'student')->assertDispatched('notify');
        $this->assertArrayHasKey('student', $page->get('memberTypesList'));

        Student::where('phone', '9000000055')->update(['member_type' => 'student']);
        $page->call('removeMemberType', 'alumni')->assertSet('memberTypesList', ['student' => 'Student']);
        $page->call('saveBooks')->assertHasNoErrors();
        $this->assertSame(['student' => 'Student'], CirculationRules::memberTypes());
    }

    public function test_member_type_names_are_validated(): void
    {
        $page = Livewire::test('pages::app.settings.index')->call('setTab', 'books');
        $page->set('newMemberType', '')->call('addMemberType')->assertHasErrors('newMemberType');
        $page->set('newMemberType', str_repeat('x', 31))->call('addMemberType')->assertHasErrors('newMemberType');
        $page->set('newMemberType', 'Visiting Professor')->call('addMemberType')->assertHasNoErrors();
        $this->assertArrayHasKey('visitingpr', $page->get('memberTypesList'), 'the key is a short slug');
        $page->set('memberTypesList.visitingpr', '')->call('saveBooks')->assertHasErrors();
    }

    // ===================== ISBN lookup =====================

    protected function fakeOpenLibrary(): void
    {
        Http::fake([
            'openlibrary.org/*' => Http::response(['ISBN:9780062315007' => [
                'title' => 'The Alchemist', 'subtitle' => 'A Fable', 'authors' => [['name' => 'Paulo Coelho']], 'publishers' => [['name' => 'HarperOne']], 'publish_date' => 'April 25, 1993',
            ]]),
            'www.googleapis.com/*' => Http::response(['items' => []]),
        ]);
    }

    public function test_isbn_lookup_returns_clean_details_and_caches_them(): void
    {
        $this->fakeOpenLibrary();

        $d = IsbnLookup::find('978-0-06-231500-7');
        $this->assertSame('The Alchemist', $d['title']);
        $this->assertSame('Paulo Coelho', $d['author']);
        $this->assertSame('HarperOne', $d['publisher']);
        $this->assertSame(1993, $d['publication_year']);
        $this->assertSame('Open Library', $d['source']);

        IsbnLookup::find('9780062315007');
        Http::assertSentCount(1);                                   // the second call came from the cache
    }

    public function test_isbn_lookup_falls_back_to_google(): void
    {
        Http::fake([
            'openlibrary.org/*' => Http::response([], 200),
            'www.googleapis.com/*' => Http::response(['items' => [['volumeInfo' => ['title' => 'Atomic Habits', 'authors' => ['James Clear'], 'publisher' => 'Avery', 'publishedDate' => '2018-10-16', 'language' => 'en', 'description' => '<b>Tiny</b> changes']]]]),
        ]);
        $d = IsbnLookup::find('9780735211292');
        $this->assertSame('Atomic Habits', $d['title']);
        $this->assertSame('English', $d['language']);
        $this->assertSame('Tiny changes', $d['description'], 'markup is stripped');
        $this->assertSame('Google Books', $d['source']);
    }

    public function test_isbn_lookup_survives_server_errors_and_no_internet(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->assertNull(IsbnLookup::find('9780306406157'));
    }

    public function test_isbn_lookup_without_internet_is_not_an_error(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('offline')]);
        $this->assertNull(IsbnLookup::find('9780306406157'));
    }

    public function test_an_invalid_isbn_is_never_sent_anywhere(): void
    {
        Http::fake();
        $this->assertNull(IsbnLookup::find('1234'));
        Http::assertNothingSent();
    }

    public function test_the_book_form_fills_only_empty_fields_from_the_isbn(): void
    {
        $this->fakeOpenLibrary();

        $page = Livewire::test('pages::app.books.form')->set('author', 'My Own Author')->set('isbn', '9780062315007')->call('lookupIsbn');
        $page->assertSet('title', 'The Alchemist')->assertSet('author', 'My Own Author')->assertSet('publisher', 'HarperOne')->assertSet('publication_year', '1993');
        $this->assertStringContainsString('Open Library', $page->get('lookupNote'));
    }

    public function test_typing_a_valid_isbn_on_an_empty_new_book_fills_it_automatically(): void
    {
        $this->fakeOpenLibrary();

        Livewire::test('pages::app.books.form')->set('isbn', '9780062315007')->assertSet('title', 'The Alchemist');
        Livewire::test('pages::app.books.form')->set('title', 'Mine')->set('isbn', '9780062315007')->assertSet('title', 'Mine');   // never overwrites
    }

    public function test_isbn_lookup_can_be_switched_off_and_reports_nothing_found(): void
    {
        $this->fakeOpenLibrary();
        LibrarySettings::update(['isbn_lookup' => false]);
        $page = Livewire::test('pages::app.books.form')->set('isbn', '9780062315007')->call('lookupIsbn');
        $page->assertSet('title', '');
        Http::assertNothingSent();

        LibrarySettings::update(['isbn_lookup' => true]);
        Http::fake(['*' => Http::response([], 200)]);
        Cache::flush();
        $page = Livewire::test('pages::app.books.form')->set('isbn', '9780306406157')->call('lookupIsbn');
        $this->assertStringContainsString('Nothing found', $page->get('lookupNote'));
        Livewire::test('pages::app.books.form')->set('isbn', '123')->call('lookupIsbn')->assertSet('lookupNote', 'Enter a valid ISBN first.');
    }

    public function test_the_setting_is_saved_from_the_settings_page(): void
    {
        Livewire::test('pages::app.settings.index')->call('setTab', 'books')->set('isbn_lookup', false)->call('saveBooks')->assertHasNoErrors();
        $this->assertFalse(LibrarySettings::get('isbn_lookup'));
    }

    // ===================== QR codes =====================

    public function test_qr_codes_are_svg_and_differ_by_content(): void
    {
        $a = Qr::svg('ACC-000001');
        $b = Qr::svg('ACC-000002');
        $this->assertStringStartsWith('<svg', $a);
        $this->assertStringNotContainsString('<?xml', $a);
        $this->assertNotSame($a, $b);
        $this->assertSame($a, Qr::svg('ACC-000001'));
    }

    public function test_labels_can_show_a_barcode_a_qr_code_or_both(): void
    {
        $b = $this->book('Labelled', [], 2);

        $both = Livewire::test('pages::app.books.labels')->set('mode', 'book')->set('book', (string) $b->id);
        $html = $both->set('code', 'barcode')->html();
        $this->assertSame(2, substr_count($html, '<svg') >= 2 ? 2 : 0);          // one barcode per label
        $qr = Livewire::test('pages::app.books.labels')->set('mode', 'book')->set('book', (string) $b->id)->set('code', 'qr')->html();
        $this->assertGreaterThan(substr_count($html, '<svg'), substr_count($qr, '<rect') + substr_count($qr, '<path'), 'the QR carries its own shapes');

        Livewire::test('pages::app.books.labels')->set('mode', 'book')->set('book', (string) $b->id)->set('code', 'both')->assertSee('ACC-000001')->assertSeeHtml('<svg');
    }

    // ===================== PDF =====================

    protected function assertPdf($response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_payment_receipts_download_as_pdf_for_staff_and_the_member(): void
    {
        $m = $this->student->memberships()->first();
        $payment = app(RecordPayment::class)($this->student, '200', 'upi', today(), $m, 'UTR1');

        $this->assertPdf($this->get("/app/{$this->slug}/payments/{$payment->id}/receipt?format=pdf"));
        $this->get("/app/{$this->slug}/payments/{$payment->id}/receipt")->assertOk()->assertSee('Download PDF');

        $login = app(CreateStudentLogin::class)($this->student)->fresh();
        $other = Student::create(['name' => 'Rahul', 'phone' => '9000000002', 'email' => 'r@x.test']);
        $otherLogin = app(CreateStudentLogin::class)($other)->fresh();

        $this->actingAs($login);
        $this->assertPdf($this->get("/app/{$this->slug}/my/receipts/{$payment->id}?format=pdf"));
        $this->actingAs($otherLogin);
        $this->get("/app/{$this->slug}/my/receipts/{$payment->id}?format=pdf")->assertNotFound();
        $this->get("/app/{$this->slug}/payments/{$payment->id}/receipt?format=pdf")->assertForbidden();
    }

    public function test_circulation_receipts_download_as_pdf(): void
    {
        $copy = $this->book('PDF Book')->copies()->first();
        $loan = app(IssueBooks::class)($this->student, [$copy->barcode]);
        Carbon::setTestNow('2026-11-09 10:00:00');
        $r = app(ReturnBooks::class)([['barcode' => $copy->barcode]]);
        $fine = Fine::first();
        app(SettleFine::class)->collect($fine, 'cash');

        $this->assertPdf($this->get("/app/{$this->slug}/circulation/receipts/issue/{$loan->id}?format=pdf"));
        $this->assertPdf($this->get("/app/{$this->slug}/circulation/receipts/return/{$r['receipt_no']}?format=pdf"));
        $this->assertPdf($this->get("/app/{$this->slug}/circulation/receipts/fine/{$fine->id}?format=pdf"));
    }

    public function test_subscription_invoices_download_as_pdf(): void
    {
        Storage::fake('local');
        $sub = \App\Models\Subscription::currentFor($this->library->id);
        $plan = SubscriptionPlan::first();
        $pay = app(SubmitSubscriptionPayment::class)($this->library, $plan, 'monthly', 'UTR-S', 1000, 'upi', today(), null, [UploadedFile::fake()->image('p.png')]);
        $this->actingAs($this->root);
        app(ApproveSubscriptionPayment::class)($pay);
        $invoice = \App\Models\SubscriptionInvoice::first();

        $this->assertPdf($this->get("/admin/subscription-invoices/{$invoice->id}?format=pdf"));
        $this->actingAs($this->owner);
        $this->assertPdf($this->get("/app/{$this->slug}/subscription/invoices/{$invoice->id}?format=pdf"));
    }

    public function test_reports_and_the_catalogue_export_as_pdf(): void
    {
        $this->book('Catalogue Book', ['isbn' => '9780062315007'], 2);

        $r = $this->get("/app/{$this->slug}/books/export/pdf");
        $this->assertPdf($r);
        $this->assertPdf($this->get("/app/{$this->slug}/reports/student-registration/export/pdf"));
        $this->get("/app/{$this->slug}/reports/student-registration/export/docx")->assertNotFound();

        $acc = $this->actAs(LibraryRoles::SUPERVISOR);
        $this->get("/app/{$this->slug}/books/export/pdf")->assertForbidden();
        $this->get("/app/{$this->slug}/reports/student-registration/export/pdf")->assertForbidden();
    }

    public function test_a_pdf_stops_at_a_sensible_size(): void
    {
        $report = new \App\Reports\BookCatalogueReport;
        for ($i = 0; $i < 3; $i++) {
            $this->book("Many {$i}", [], 0);
        }

        // lower the cap by exporting a report with more rows than the limit allows
        $rows = collect(range(1, \App\Reports\ReportExporter::PDF_MAX_ROWS + 1))->map(fn ($i) => [$i]);
        $fake = new class($rows) extends \App\Reports\Report {
            public function __construct(protected $rows) {}
            public function key(): string { return 'fake'; }
            public function title(): string { return 'Fake'; }
            public function description(): string { return ''; }
            public function headers(): array { return ['N']; }
            public function collection(array $f): \Illuminate\Support\Collection { return $this->rows; }
        };

        $this->expectException(HttpException::class);
        \App\Reports\ReportExporter::pdf($fake, [], 'fake.pdf');
    }

    // ===================== stock-taking =====================

    protected function shelf(): array
    {
        $a = $this->book('Atomic Habits', ['shelf' => 'A', 'category_id' => BookCategory::create(['name' => 'Self-help'])->id], 3);
        $b = $this->book('Wings of Fire', ['shelf' => 'B'], 2);

        return [$a, $b];
    }

    public function test_starting_a_stock_take_lists_what_should_be_on_the_shelf(): void
    {
        [$a, $b] = $this->shelf();
        $out = $a->copies()->first();
        app(IssueBooks::class)($this->student, [$out->barcode]);                                           // out on loan: not expected
        $lost = $b->copies()->first();
        $lost->forceFill(['status' => 'lost'])->save();                                                    // lost: not expected

        $audit = app(StockTake::class)->start('October count');

        $this->assertSame(3, $audit->expected_count);                                                      // 2 of A + 1 of B
        $this->assertSame('open', $audit->status);
        $this->assertSame(3, $audit->items()->where('expected', true)->count());
        $this->assertNotNull(\App\Models\AuditLog::where('action', 'stocktake.started')->first());
    }

    public function test_a_stock_take_can_cover_one_category_or_one_shelf(): void
    {
        [$a, $b] = $this->shelf();

        $byShelf = app(StockTake::class)->start('Shelf B', 'shelf', 'B');
        $this->assertSame(2, $byShelf->expected_count);
        app(StockTake::class)->cancel($byShelf);

        $byCat = app(StockTake::class)->start('Self-help', 'category', (string) $a->category_id);
        $this->assertSame(3, $byCat->expected_count);
        $this->assertSame('Category: Self-help', $byCat->scopeLabel());
        app(StockTake::class)->cancel($byCat);

        $this->fails(fn () => app(StockTake::class)->start('Empty', 'shelf', 'Z'), 'no copies');
        $this->fails(fn () => app(StockTake::class)->start('Bad', 'category', '99999'), 'category');
        $this->fails(fn () => app(StockTake::class)->start('Bad', 'shelf', ''), 'shelf');
        $this->fails(fn () => app(StockTake::class)->start(' ', 'all'), 'name');
        $this->fails(fn () => app(StockTake::class)->start('Bad', 'everything'), 'what to count');
        $this->assertSame(0, InventoryAudit::where('status', 'open')->count(), 'a failed start leaves nothing open');
    }

    public function test_only_one_stock_take_can_be_open_at_a_time(): void
    {
        $this->shelf();
        $first = app(StockTake::class)->start('First');

        $this->fails(fn () => app(StockTake::class)->start('Second'), 'already open');
        app(StockTake::class)->cancel($first);
        $this->assertSame('open', app(StockTake::class)->start('Second')->status);
    }

    public function test_scanning_counts_copies_and_flags_surprises(): void
    {
        [$a, $b] = $this->shelf();
        $copies = $a->copies()->orderBy('id')->get();
        $issued = $copies[0];
        $lostCopy = $b->copies()->first();
        app(IssueBooks::class)($this->student, [$issued->barcode]);
        $lostCopy->forceFill(['status' => 'lost'])->save();
        $audit = app(StockTake::class)->start('Count');
        $st = app(StockTake::class);

        $this->assertSame('ok', $st->scan($audit, $copies[1]->barcode)['result']);
        $this->assertSame('already', $st->scan($audit, $copies[1]->accession_no)['result']);              // by accession number too
        $this->assertSame('unknown', $st->scan($audit, 'NOPE-9')['result']);
        $this->assertSame('unexpected', $st->scan($audit, $issued->barcode)['result']);                  // the register says it is on loan
        $r = $st->scan($audit, $lostCopy->barcode);
        $this->assertSame('unexpected', $r['result']);
        $this->assertStringContainsString('found', $r['message']);

        $audit->refresh();
        $this->assertSame(1, $audit->found_count);
        $this->assertSame(2, $audit->unexpected_count);
        $this->assertSame(['NOPE-9'], $audit->unknown_codes);
        $this->fails(fn () => $st->scan($audit, ' '), 'Scan a barcode');
    }

    public function test_a_copy_outside_the_chosen_scope_is_not_a_discrepancy(): void
    {
        [$a, $b] = $this->shelf();
        $audit = app(StockTake::class)->start('Shelf A only', 'shelf', 'A');

        $r = app(StockTake::class)->scan($audit, $b->copies()->first()->barcode);
        $this->assertSame('outside', $r['result']);
        $this->assertSame(0, $audit->fresh()->unexpected_count);
        $this->assertSame(0, StockTake::unexpected($audit)->count());
    }

    public function test_finishing_records_the_result_without_touching_the_register_by_default(): void
    {
        [$a] = $this->shelf();
        $audit = app(StockTake::class)->start('Count');
        $first = $a->copies()->orderBy('id')->first();
        app(StockTake::class)->scan($audit, $first->barcode);

        $out = app(StockTake::class)->complete($audit);

        $this->assertSame(4, $out['missing']);                  // 5 copies on the shelf, only 1 counted
        $this->assertSame(0, $out['marked_lost']);
        $audit->refresh();
        $this->assertSame('completed', $audit->status);
        $this->assertSame(4, $audit->missing_count);
        $this->assertSame(5, BookCopy::where('status', 'available')->count(), 'the register is unchanged');
        $this->fails(fn () => app(StockTake::class)->complete($audit), 'already closed');
        $this->fails(fn () => app(StockTake::class)->scan($audit, $first->barcode), 'closed');
    }

    public function test_finishing_can_mark_missing_copies_lost_and_found_ones_available(): void
    {
        [$a, $b] = $this->shelf();
        $lostEarlier = $b->copies()->first();
        $lostEarlier->forceFill(['status' => 'lost'])->save();
        $audit = app(StockTake::class)->start('Count');
        $copies = $a->copies()->orderBy('id')->get();
        $st = app(StockTake::class);
        $st->scan($audit, $copies[0]->barcode);
        $st->scan($audit, $lostEarlier->barcode);                                                          // turns up although marked lost

        // one missing copy gets handled by someone else while counting
        $copies[1]->forceFill(['status' => 'damaged'])->save();

        $out = $st->complete($audit, true, true, 'Annual count');

        // 4 expected (A x3, B x1; B's other copy was already lost), 1 counted => 3 missing: A[1] was damaged meanwhile (left alone), A[2] and B's copy become Lost
        $this->assertSame(['missing' => 3, 'marked_lost' => 2, 'found_again' => 1, 'skipped' => 1], $out);
        $this->assertSame('lost', $copies[2]->fresh()->status);
        $this->assertSame('damaged', $copies[1]->fresh()->status, 'a copy handled since is left alone');
        $this->assertSame('available', $lostEarlier->fresh()->status);
        $this->assertContains('status_changed', $copies[2]->fresh()->events()->pluck('type')->all());
        $this->assertStringContainsString('Missing in stock-take', $copies[2]->fresh()->events()->first()->note);
        $this->assertNotNull(\App\Models\AuditLog::where('action', 'stocktake.applied')->first());
    }

    public function test_applying_results_needs_the_copy_permission(): void
    {
        $this->shelf();
        $audit = app(StockTake::class)->start('Count');

        // a custom role that may count but not change copies
        $role = Tenancy::run($this->library, fn () => \Spatie\Permission\Models\Role::create(['name' => 'Counter', 'guard_name' => 'web', 'library_id' => $this->library->id])->givePermissionTo(['books.audit', 'books.view']));
        $u = User::factory()->create();
        $this->library->users()->attach($u->id, ['is_active' => true]);
        Tenancy::run($this->library, fn () => $u->assignRole('Counter'));
        $this->actingAs($u->fresh());
        Tenancy::set($this->library);

        app(StockTake::class)->scan($audit, BookCopy::first()->barcode);
        $this->expectException(HttpException::class);
        app(StockTake::class)->complete($audit, true);
    }

    public function test_cancelling_changes_nothing(): void
    {
        $this->shelf();
        $audit = app(StockTake::class)->start('Count');
        app(StockTake::class)->cancel($audit);

        $this->assertSame('cancelled', $audit->fresh()->status);
        $this->assertSame(0, BookCopy::where('status', 'lost')->count());
        $this->fails(fn () => app(StockTake::class)->cancel($audit), 'already closed');
    }

    public function test_the_stock_take_screens_and_csv(): void
    {
        [$a] = $this->shelf();
        $this->get("/app/{$this->slug}/books/audits")->assertOk()->assertSee('Start a stock-take');

        $page = Livewire::test('pages::app.books.audits')->call('openStart')->set('name', 'Screen count')->call('start')->assertHasNoErrors()->assertRedirect();
        $audit = InventoryAudit::first();
        $this->get("/app/{$this->slug}/books/audits/{$audit->id}")->assertOk()->assertSee('Screen count');

        $copies = $a->copies()->orderBy('id')->get();
        $show = Livewire::test('pages::app.books.audit', ['audit' => (string) $audit->id]);
        $show->set('scan', $copies[0]->barcode)->call('count')->assertSet('scan', '')->assertSee('counted');
        $show->set('scan', 'ZZZ')->call('count')->assertSee('not a book in this library');
        $show->set('view', 'unknown')->assertSee('ZZZ');
        $show->set('view', 'missing')->assertSee($copies[1]->accession_no);

        $show->call('close')->assertHasNoErrors();
        $this->assertSame('completed', $audit->fresh()->status);

        $csv = $this->get("/app/{$this->slug}/books/audits/{$audit->id}/export")->assertOk()->streamedContent();
        $this->assertStringContainsString('Missing', $csv);
        $this->assertStringContainsString($copies[1]->accession_no, $csv);
        $this->assertStringContainsString('Unknown code', $csv);
        $this->assertStringNotContainsString($copies[0]->accession_no, $csv, 'a counted copy is not a discrepancy');
    }

    public function test_stock_takes_need_the_permission_and_stay_inside_their_library(): void
    {
        $this->shelf();
        $audit = app(StockTake::class)->start('Count');

        $this->actAs(LibraryRoles::RECEPTIONIST);
        foreach (['books/audits', "books/audits/{$audit->id}", "books/audits/{$audit->id}/export"] as $p) {
            $this->get("/app/{$this->slug}/{$p}")->assertForbidden();
        }

        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        $this->actingAs($this->root);
        app(CreateSubscription::class)($other, SubscriptionPlan::first(), 'monthly', true);
        app(ManageLibraryModule::class)->grant($other, 'books', 'complimentary', 'x');
        $this->actingAs(User::where('email', 'b@beta.test')->first());
        $this->get("/app/{$other->slug}/books/audits/{$audit->id}")->assertNotFound();
        $this->get("/app/{$other->slug}/books/audits/{$audit->id}/export")->assertNotFound();
    }

    public function test_the_audit_permission_is_on_the_right_default_roles(): void
    {
        $map = LibraryRoles::map();
        $this->assertContains('books.audit', $map[LibraryRoles::OWNER]);
        $this->assertContains('books.audit', $map[LibraryRoles::MANAGER]);
        $this->assertNotContains('books.audit', $map[LibraryRoles::RECEPTIONIST]);
        $this->assertTrue($this->owner->can('books.audit'));
    }
}
