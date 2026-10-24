<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\Books\AddCopies;
use App\Actions\Books\ChangeCopyStatus;
use App\Actions\Books\ImportBooks;
use App\Actions\Books\ManageBookLifecycle;
use App\Actions\Books\SaveBook;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\ManageLibraryModule;
use App\Actions\CreateStudentLogin;
use App\Actions\RegisterLibrary;
use App\Exceptions\BusinessRuleException;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\EbookAccessLog;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Student;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Code128;
use App\Support\Entitlements;
use App\Support\Isbn;
use App\Support\LibraryRoles;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BooksTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;
    protected User $owner;
    protected User $root;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->root = User::factory()->create();
        $this->root->forceFill(['is_super_admin' => true])->save();
        $this->root = $this->root->fresh();
        $this->actingAs($this->root);

        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@alpha.test']);
        $this->owner = User::where('email', 'o@alpha.test')->first();

        // a plan WITHOUT the book modules; they are switched on below as free grants
        $plan = SubscriptionPlan::create(['name' => 'Std', 'price_monthly' => 1000, 'trial_days' => 30]);
        app(CreateSubscription::class)($this->library, $plan, 'monthly', true);
        $this->enable($this->library, 'books');
        $this->enable($this->library, 'ebooks');

        $this->actingAs($this->owner);
        Tenancy::set($this->library);
    }

    protected function tearDown(): void
    {
        \App\Support\BillingSettings::flush();
        LibrarySettings::flush();
        Entitlements::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function enable(Library $library, string $module): void
    {
        $previous = auth()->user();
        $this->actingAs($this->root);
        app(ManageLibraryModule::class)->grant($library, $module, 'complimentary', 'test');
        $this->actingAs($previous ?? $this->owner);
    }

    protected function book(array $over = []): Book
    {
        return app(SaveBook::class)(array_merge(['type' => 'physical', 'title' => 'The Alchemist', 'author' => 'Paulo Coelho', 'isbn' => '9780062315007'], $over));
    }

    protected function ebook(array $over = []): Book
    {
        return app(SaveBook::class)(array_merge(['type' => 'ebook', 'title' => 'Python Basics', 'author' => 'A. Author'], $over),
            null, null, $over['__file'] ?? UploadedFile::fake()->create('python.pdf', 100, 'application/pdf'));
    }

    protected function student(string $phone = '9000000001', bool $membership = true): array
    {
        $s = Student::create(['name' => 'Priya', 'phone' => $phone, 'email' => "p{$phone}@x.test"]);
        if ($membership) {
            app(AssignMembership::class)($s, MembershipPlan::first() ?? MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 500]), today());
        }
        $login = app(CreateStudentLogin::class)($s)->fresh();

        return [$s, $login];
    }

    // ===================== helpers =====================

    public function test_code128_patterns_are_well_formed(): void
    {
        foreach (Code128::PATTERNS as $i => $p) {
            $this->assertSame(11, array_sum(str_split($p)), "pattern {$i} must be 11 modules wide");
        }
        $this->assertCount(106, Code128::PATTERNS);
        $this->assertSame(13, array_sum(str_split(Code128::STOP)));

        // symbol count: start + data + checksum, each 11 modules, then the 13-module stop
        $w = Code128::widths('ACC-000123');
        $this->assertSame(11 * (1 + 10 + 1) + 13, array_sum(str_split($w)));
        $this->assertStringContainsString('<svg', Code128::svg('ACC-000123'));
        $this->assertTrue(Code128::supports('ACC-000123'));
        $this->assertFalse(Code128::supports("bad\u{20AC}"));
    }

    public function test_isbn_validation(): void
    {
        $this->assertTrue(Isbn::valid('978-0-306-40615-7'));
        $this->assertTrue(Isbn::valid('0306406152'));
        $this->assertTrue(Isbn::valid('080442957X'));
        $this->assertFalse(Isbn::valid('9780306406158'));   // wrong check digit
        $this->assertFalse(Isbn::valid('12345'));
        $this->assertFalse(Isbn::valid(''));
        $this->assertSame('9780306406157', Isbn::normalize(' 978-0 306-40615-7 '));
        $this->assertNull(Isbn::normalize('  '));
    }

    // ===================== modules & permissions =====================

    public function test_book_pages_need_the_module_and_the_permission(): void
    {
        $this->get("/app/{$this->library->slug}/books")->assertOk();

        // a library whose plan lacks both modules
        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        $this->actingAs($this->root);
        app(CreateSubscription::class)($other, SubscriptionPlan::first(), 'monthly', true);
        $betaOwner = User::where('email', 'b@beta.test')->first();

        $this->actingAs($betaOwner);
        foreach (['books', 'books/create', 'books/import', 'ebooks'] as $path) {
            $this->get("/app/{$other->slug}/{$path}")->assertForbidden();
        }
        $this->assertStringNotContainsString('>Books<', $this->get("/app/{$other->slug}/subscription")->getContent());

        // a role without book permissions
        $this->actingAs($this->owner);
        Tenancy::set($this->library);
        $acc = User::factory()->create();
        $this->library->users()->attach($acc->id, ['is_active' => true]);
        Tenancy::run($this->library, fn () => $acc->assignRole(LibraryRoles::SUPERVISOR));
        $this->actingAs($acc->fresh());
        $this->get("/app/{$this->library->slug}/books")->assertOk();                 // supervisors can look
        $this->get("/app/{$this->library->slug}/books/create")->assertForbidden();   // but not add
        $this->get("/app/{$this->library->slug}/books/import")->assertForbidden();
    }

    public function test_only_one_of_the_modules_gives_only_that_type(): void
    {
        $this->actingAs($this->root);
        app(ManageLibraryModule::class)->revoke($this->library, 'ebooks', 'test');
        $this->actingAs($this->owner);
        Tenancy::set($this->library);

        $this->book();
        $this->get("/app/{$this->library->slug}/ebooks")->assertForbidden();
        try {
            $this->ebook();
            $this->fail('e-book created without the module');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('not enabled', $e->getMessage());
        }
        $this->get("/app/{$this->library->slug}/books")->assertOk()->assertDontSee('Add e-book');
    }

    // ===================== catalogue =====================

    public function test_a_book_is_saved_with_clean_data_and_audited(): void
    {
        $cat = BookCategory::create(['name' => 'Fiction']);
        $b = $this->book(['isbn' => '978-0-06-231500-7', 'category_id' => $cat->id, 'tags' => 'classic, journey, CLASSIC', 'publication_year' => 1993, 'shelf' => 'A']);

        $this->assertSame('9780062315007', $b->isbn);
        $this->assertSame(['classic', 'journey'], $b->tags);
        $this->assertSame('physical', $b->type);
        $this->assertSame($this->library->id, $b->library_id);
        $this->assertNotNull(\App\Models\AuditLog::where('action', 'book.created')->first());
    }

    public function test_validation_and_duplicate_protection(): void
    {
        $b = $this->book();

        $bad = [
            fn () => $this->book(['title' => ' ']),
            fn () => $this->book(['isbn' => '9780062315008', 'title' => 'X']),                 // bad check digit
            fn () => $this->book(['isbn' => '978-0-06-231500-7', 'title' => 'Same ISBN']),     // duplicate
            fn () => $this->book(['isbn' => '', 'title' => 'Y', 'publication_year' => 1500 - 600]),
            fn () => $this->book(['isbn' => '', 'title' => 'Z', 'category_id' => 99999]),
            fn () => $this->book(['isbn' => '', 'title' => 'P', 'purchase_price' => -5]),
        ];
        foreach ($bad as $i => $try) {
            try {
                $try();
                $this->fail("case {$i} accepted");
            } catch (BusinessRuleException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1, Book::count());

        // editing itself is not a duplicate; the type can never change
        app(SaveBook::class)(['title' => 'The Alchemist (2nd ed.)', 'isbn' => '9780062315007'], $b);
        $this->assertSame('The Alchemist (2nd ed.)', $b->fresh()->title);
        $this->expectException(BusinessRuleException::class);
        app(SaveBook::class)(['type' => 'ebook', 'title' => 'x'], $b);
    }

    public function test_the_same_isbn_can_exist_as_a_book_and_an_ebook(): void
    {
        $this->book();
        $e = $this->ebook(['isbn' => '9780062315007', 'title' => 'The Alchemist (digital)']);
        $this->assertSame(2, Book::count());
        $this->assertTrue($e->isEbook());
    }

    public function test_ebook_needs_a_file_or_link_and_only_allowed_files(): void
    {
        foreach ([
            fn () => app(SaveBook::class)(['type' => 'ebook', 'title' => 'Empty']),
            fn () => app(SaveBook::class)(['type' => 'ebook', 'title' => 'Bad link', 'external_url' => 'javascript:alert(1)']),
            fn () => app(SaveBook::class)(['type' => 'ebook', 'title' => 'Exe'], null, null, UploadedFile::fake()->create('virus.exe', 10)),
            fn () => app(SaveBook::class)(['type' => 'ebook', 'title' => 'Huge'], null, null, UploadedFile::fake()->create('big.pdf', 60000, 'application/pdf')),
            fn () => $this->book(['isbn' => '', 'title' => 'Cover'], null) && app(SaveBook::class)(['type' => 'physical', 'title' => 'Bad cover'], null, UploadedFile::fake()->create('c.gif', 10)),
        ] as $i => $try) {
            try {
                $try();
                $this->fail("case {$i} accepted");
            } catch (BusinessRuleException) {
                $this->assertTrue(true);
            }
        }

        $link = app(SaveBook::class)(['type' => 'ebook', 'title' => 'Linked', 'external_url' => 'https://example.com/a.pdf']);
        $this->assertFalse($link->hasFile());
        $file = $this->ebook();
        $this->assertTrue(Storage::disk('local')->exists($file->ebook_path));
        $this->assertSame('application/pdf', $file->ebook_mime);
    }

    public function test_replacing_files_removes_the_old_ones(): void
    {
        $b = $this->book(['isbn' => '']);
        $b = app(SaveBook::class)(['title' => $b->title], $b, UploadedFile::fake()->image('a.png'));
        $old = $b->cover_path;
        $b = app(SaveBook::class)(['title' => $b->title], $b, UploadedFile::fake()->image('b.png'));
        $this->assertNotSame($old, $b->cover_path);
        Storage::disk('local')->assertMissing($old);
        $b = app(SaveBook::class)(['title' => $b->title], $b, null, null, true);
        $this->assertNull($b->cover_path);
    }

    // ===================== copies =====================

    public function test_copies_get_sequential_accession_numbers_and_matching_barcodes(): void
    {
        $b = $this->book();
        $copies = app(AddCopies::class)($b, 3);

        $this->assertSame(['ACC-000001', 'ACC-000002', 'ACC-000003'], $copies->pluck('accession_no')->all());
        $this->assertSame($copies->pluck('accession_no')->all(), $copies->pluck('barcode')->all());
        $this->assertSame('available', $copies->first()->status);

        $second = $this->book(['isbn' => '', 'title' => 'Another']);
        $this->assertSame('ACC-000004', app(AddCopies::class)($second, 1)->first()->accession_no, 'numbers continue across titles');
        $this->assertSame(4, \App\Models\BookCopyEvent::where('type', 'acquired')->count());
    }

    public function test_numbering_follows_the_library_settings_and_skips_hand_typed_numbers(): void
    {
        LibrarySettings::update(['accession_prefix' => 'lib', 'accession_padding' => 4]);
        $b = $this->book();
        app(AddCopies::class)($b, 1, [], 'LIB-0002');           // typed by hand, collides with the next generated number

        $made = app(AddCopies::class)($b, 3);
        $this->assertSame(['LIB-0001', 'LIB-0003', 'LIB-0004'], $made->pluck('accession_no')->all());
    }

    public function test_copy_rules(): void
    {
        $b = $this->book();
        $e = $this->ebook(['title' => 'Digital']);
        app(AddCopies::class)($b, 1, [], 'OLD-7');

        foreach ([
            fn () => app(AddCopies::class)($b, 0),
            fn () => app(AddCopies::class)($b, 501),
            fn () => app(AddCopies::class)($b, 2, [], 'FIXED-1'),         // a fixed number only for one copy
            fn () => app(AddCopies::class)($b, 1, [], 'OLD-7'),           // already used
            fn () => app(AddCopies::class)($b, 1, [], 'has space'),
            fn () => app(AddCopies::class)($e, 1),                        // e-books have no copies
            fn () => app(AddCopies::class)($b, 1, ['purchase_price' => -1]),
        ] as $i => $try) {
            try {
                $try();
                $this->fail("case {$i} accepted");
            } catch (BusinessRuleException) {
                $this->assertTrue(true);
            }
        }

        app(ManageBookLifecycle::class)->archive($b);
        $this->expectException(BusinessRuleException::class);
        app(AddCopies::class)($b, 1);
    }

    public function test_copy_status_transitions_and_history(): void
    {
        $b = $this->book();
        $copy = app(AddCopies::class)($b, 1)->first();
        $change = app(ChangeCopyStatus::class);

        $this->expectBusinessError(fn () => $change($copy, 'damaged'));                // reason needed
        $change($copy, 'damaged', 'Torn cover');
        $this->assertSame('damaged', $copy->fresh()->status);
        $change($copy, 'available', 'Repaired');
        $change($copy, 'lost', 'Not on the shelf');
        $change($copy, 'available', 'Found');
        $this->expectBusinessError(fn () => $change($copy, 'reserved'));               // reserved is set by the system
        $change($copy, 'withdrawn', 'Water damage');

        $copy->refresh();
        $this->assertSame('withdrawn', $copy->status);
        $this->assertSame(today()->toDateString(), $copy->withdrawn_on->toDateString());
        $this->expectBusinessError(fn () => $change($copy, 'available', 'oops'));      // disposal is final
        $this->assertSame(['withdrawn', 'status_changed', 'status_changed', 'status_changed', 'status_changed', 'acquired'], $copy->events->pluck('type')->all());
    }

    public function test_an_issued_copy_cannot_be_changed_by_hand(): void
    {
        $copy = app(AddCopies::class)($this->book(), 1)->first();
        $copy->forceFill(['status' => 'issued'])->save();

        $this->expectBusinessError(fn () => app(ChangeCopyStatus::class)($copy, 'lost', 'x'));
        $this->expectBusinessError(fn () => app(ManageBookLifecycle::class)->archive($copy->book));
    }

    protected function expectBusinessError(callable $fn): void
    {
        try {
            $fn();
            $this->fail('expected a business rule error');
        } catch (BusinessRuleException) {
            $this->assertTrue(true);
        }
    }

    // ===================== archive / delete =====================

    public function test_unused_titles_can_be_deleted_but_used_ones_only_archived(): void
    {
        $fresh = $this->book(['isbn' => '', 'title' => 'Fresh']);
        app(AddCopies::class)($fresh, 2);
        $this->assertTrue(ManageBookLifecycle::canDelete($fresh));
        app(ManageBookLifecycle::class)->delete($fresh);
        $this->assertSame(0, Book::count());
        $this->assertSame(0, BookCopy::count());

        $used = $this->book();
        $copy = app(AddCopies::class)($used, 1)->first();
        app(ChangeCopyStatus::class)($copy, 'damaged', 'torn');
        $this->assertFalse(ManageBookLifecycle::canDelete($used));
        $this->expectBusinessError(fn () => app(ManageBookLifecycle::class)->delete($used));

        app(ManageBookLifecycle::class)->archive($used);
        $this->assertTrue($used->fresh()->isArchived());
        app(ManageBookLifecycle::class)->restore($used);
        $this->assertFalse($used->fresh()->isArchived());
    }

    public function test_deleting_removes_files(): void
    {
        $e = $this->ebook();
        $path = $e->ebook_path;
        Storage::disk('local')->assertExists($path);
        app(ManageBookLifecycle::class)->delete($e);
        Storage::disk('local')->assertMissing($path);

        $opened = $this->ebook(['title' => 'Opened']);
        EbookAccessLog::create(['book_id' => $opened->id, 'user_id' => $this->owner->id, 'action' => 'view']);
        $this->assertFalse(ManageBookLifecycle::canDelete($opened));
    }

    // ===================== import / export =====================

    protected function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        $fh = fopen($path, 'w');
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);

        return $path;
    }

    public function test_import_checks_every_row_then_imports_the_good_ones(): void
    {
        $this->book(['isbn' => '9780306406157', 'title' => 'Existing']);
        $path = $this->csv([
            ['Title', 'ISBN', 'Author', 'Category', 'Subcategory', 'Copies', 'Price', 'Tags'],
            ['Brand New', '9780062315007', 'Paulo', 'Fiction', 'Novels', '3', '350', 'a, b'],
            ['Existing', '978-0-306-40615-7', '', '', '', '2', '', ''],
            ['', '', '', '', '', '', '', ''],
            ['Bad Isbn', '123', '', '', '', '1', '', ''],
            ['Bad Copies', '', '', '', '', 'x', '', ''],
            ['Fixed Number', '', '', 'Fiction', '', '1', '', ''],
        ]);

        $importer = app(ImportBooks::class);
        $rows = $importer->read($path, 'csv');
        $this->assertCount(5, $rows, 'the blank row is ignored');

        $preview = collect($importer->preview($rows))->keyBy(fn ($p) => $p['row']['title'] ?: '(blank)');
        $this->assertSame('new', $preview['Brand New']['status']);
        $this->assertSame('duplicate', $preview['Existing']['status']);
        $this->assertSame('error', $preview['Bad Isbn']['status']);
        $this->assertSame('error', $preview['Bad Copies']['status']);
        $this->assertSame(0, Book::count() - 1, 'preview writes nothing');

        $out = $importer->import($rows, 'skip');
        $this->assertSame(['created' => 2, 'copies' => 4, 'skipped' => 1], array_intersect_key($out, array_flip(['created', 'copies', 'skipped'])));
        $this->assertCount(2, $out['errors']);
        $this->assertSame([2 + 2, 2 + 3], array_column($out['errors'], 'line'), 'line numbers point at the sheet rows');

        $new = Book::where('title', 'Brand New')->first();
        $this->assertSame('Novels', $new->category->name);
        $this->assertSame('Fiction', $new->category->parent->name);
        $this->assertSame(3, $new->copies()->count());
        $this->assertSame(1, BookCategory::where('name', 'Fiction')->count(), 'categories are reused');

        // running the same sheet again creates nothing new
        $again = $importer->import($rows, 'skip');
        $this->assertSame(0, $again['created']);
        $this->assertSame(0, Book::where('title', 'Brand New')->count() - 1);
    }

    public function test_import_can_add_copies_to_existing_titles_and_supports_ebook_links(): void
    {
        $b = $this->book();
        app(AddCopies::class)($b, 1);
        $path = $this->csv([
            ['type', 'title', 'isbn', 'copies', 'link'],
            ['Book', 'The Alchemist', '9780062315007', '2', ''],
            ['E-book', 'Online Guide', '', '', 'https://example.com/g.pdf'],
            ['E-book', 'No link', '', '', ''],
        ]);

        $rows = app(ImportBooks::class)->read($path, 'csv');
        $out = app(ImportBooks::class)->import($rows, 'add_copies');

        $this->assertSame(3, $b->copies()->count());
        $this->assertSame(1, $out['created']);
        $this->assertSame('ebook', Book::where('title', 'Online Guide')->value('type'));
        $this->assertCount(1, $out['errors']);
    }

    public function test_import_input_limits(): void
    {
        $importer = app(ImportBooks::class);
        $this->expectBusinessError(fn () => $importer->read($this->csv([['author'], ['x']]), 'csv'));        // no title column
        $this->expectBusinessError(fn () => $importer->read($this->csv([['title'], ['x']]), 'txt'));

        $big = [['title']];
        foreach (range(1, ImportBooks::MAX_ROWS + 1) as $i) {
            $big[] = ["Book {$i}"];
        }
        $this->expectBusinessError(fn () => $importer->read($this->csv($big), 'csv'));
    }

    public function test_import_page_flow_and_permissions(): void
    {
        $file = UploadedFile::fake()->createWithContent('books.csv', "title,isbn,copies\nPage Book,9780306406157,2\n");

        Livewire::test('pages::app.books.import')->set('file', $file)
            ->assertSet('counts.new', 1)->assertHasNoErrors()
            ->call('runImport')->assertSet('result.created', 1);

        $this->assertSame(2, Book::where('title', 'Page Book')->first()->copies()->count());

        Livewire::test('pages::app.books.import')->set('file', UploadedFile::fake()->createWithContent('b.csv', "author\nx\n"))->assertHasErrors('file');
    }

    public function test_catalogue_export_lists_titles_and_is_permissioned(): void
    {
        $this->enable($this->library, 'exports');                          // exports are their own plan module
        $b = $this->book(['title' => '=cmd|calc']);                       // formula injection attempt
        app(AddCopies::class)($b, 2);

        $csv = $this->get("/app/{$this->library->slug}/books/export/csv")->assertOk()->streamedContent();
        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringContainsString('9780062315007', $csv);
        $this->get("/app/{$this->library->slug}/books/export/xlsx")->assertOk();
        $this->get("/app/{$this->library->slug}/books/export/docx")->assertNotFound();

        $acc = User::factory()->create();
        $this->library->users()->attach($acc->id, ['is_active' => true]);
        Tenancy::run($this->library, fn () => $acc->assignRole(LibraryRoles::SUPERVISOR));
        $this->actingAs($acc->fresh())->get("/app/{$this->library->slug}/books/export/csv")->assertForbidden();
    }

    // ===================== search & pages =====================

    public function test_search_finds_by_title_author_isbn_and_accession_number(): void
    {
        $a = $this->book();
        $b = $this->book(['isbn' => '9780306406157', 'title' => 'Physics Made Easy', 'author' => 'R. Feynman']);
        $copy = app(AddCopies::class)($b, 1)->first();

        $find = fn ($t) => Book::search($t)->pluck('title')->all();
        $this->assertSame(['The Alchemist'], $find('coelho'));
        $this->assertSame(['Physics Made Easy'], $find('978-0-306-40615-7'));
        $this->assertSame(['Physics Made Easy'], $find($copy->accession_no));
        $this->assertSame(['Physics Made Easy'], $find('physics'));
        $this->assertSame([], $find('100%'));   // % is a literal, not a wildcard
    }

    public function test_catalogue_page_filters_and_shows_stock(): void
    {
        $b = $this->book();
        app(AddCopies::class)($b, 2);
        $this->book(['isbn' => '', 'title' => 'Empty Shelf']);
        $this->ebook(['title' => 'Digital One']);

        $page = Livewire::test('pages::app.books.index');
        $page->assertSee('The Alchemist')->assertSee('Digital One')->assertSee('Empty Shelf');
        $page->set('type', 'ebook')->assertSee('Digital One')->assertDontSee('The Alchemist');
        $page->set('type', '')->set('stock', 'none')->assertSee('Empty Shelf')->assertDontSee('The Alchemist');
        $page->set('stock', '')->set('search', 'alchemist')->assertSee('The Alchemist')->assertDontSee('Empty Shelf');
    }

    public function test_titles_are_private_to_their_library(): void
    {
        $mine = $this->book();
        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        $this->actingAs($this->root);
        app(CreateSubscription::class)($other, SubscriptionPlan::first(), 'monthly', true);
        $this->enable($other, 'books');
        $betaOwner = User::where('email', 'b@beta.test')->first();

        $this->actingAs($betaOwner);
        $this->get("/app/{$other->slug}/books/{$mine->id}")->assertNotFound();
        $this->get("/app/{$other->slug}/books/{$mine->id}/edit")->assertNotFound();
        $this->get("/app/{$other->slug}/books/{$mine->id}/cover")->assertNotFound();
        $this->get("/app/{$other->slug}/books")->assertOk()->assertDontSee('The Alchemist');
    }

    public function test_show_page_adds_copies_and_changes_status(): void
    {
        $b = $this->book();

        $page = Livewire::test('pages::app.books.show', ['book' => (string) $b->id]);
        $page->call('openAdd')->set('count', '3')->call('saveAdd')->assertHasNoErrors();
        $this->assertSame(3, $b->copies()->count());

        $copy = $b->copies()->first();
        $page->call('openStatus', $copy->id)->set('statusTo', 'damaged')->call('saveStatus')->assertHasErrors('statusNote');
        $page->call('openStatus', $copy->id)->set('statusTo', 'damaged')->set('statusNote', 'Torn')->call('saveStatus')->assertHasNoErrors();
        $this->assertSame('damaged', $copy->fresh()->status);

        $page->call('openHistory', $copy->id)->assertSee('Torn');
        $page->assertSee($copy->accession_no);
    }

    public function test_form_creates_a_book_with_copies_and_an_ebook_with_a_file(): void
    {
        Livewire::test('pages::app.books.form')
            ->set('title', 'Form Book')->set('isbn', '9780306406157')->set('initial_copies', '4')->call('save')->assertHasNoErrors()->assertRedirect();
        $b = Book::where('title', 'Form Book')->first();
        $this->assertSame(4, $b->copies()->count());

        Livewire::test('pages::app.books.form')->set('title', 'Dup')->set('isbn', '9780306406157')->call('save')->assertHasErrors('form');

        $component = Livewire::withQueryParams(['type' => 'ebook'])->test('pages::app.books.form')
            ->set('title', 'Form Ebook')->set('ebookFile', UploadedFile::fake()->create('x.pdf', 50, 'application/pdf'))->call('save')->assertHasNoErrors();
        $this->assertTrue(Book::where('title', 'Form Ebook')->first()->hasFile());
    }

    public function test_labels_page_prints_barcodes(): void
    {
        $b = $this->book();
        app(AddCopies::class)($b, 3);

        Livewire::test('pages::app.books.labels')->set('mode', 'book')->set('book', (string) $b->id)
            ->assertSee('ACC-000001')->assertSee('ACC-000003')->assertSeeHtml('<svg');
        Livewire::test('pages::app.books.labels')->set('mode', 'range')->set('from', 'ACC-000002')->set('until', 'ACC-000002')
            ->assertSee('ACC-000002')->assertDontSee('ACC-000001');
    }

    public function test_categories_page(): void
    {
        $page = Livewire::test('pages::app.books.categories');
        $page->call('create')->set('name', 'Science')->call('save')->assertHasNoErrors();
        $sci = BookCategory::where('name', 'Science')->first();
        $page->call('create', $sci->id)->set('name', 'Physics')->call('save')->assertHasNoErrors();
        $page->call('create')->set('name', 'science')->call('save')->assertHasErrors('name');          // duplicate (any case)

        $physics = BookCategory::where('name', 'Physics')->first();
        $this->assertSame($sci->id, $physics->parent_id);
        $page->call('create', $physics->id)->set('name', 'Deep')->call('save')->assertHasErrors('parentId');   // one level only

        $this->book(['category_id' => $physics->id]);
        $page->call('delete', $physics->id);
        $this->assertNotNull(BookCategory::find($physics->id), 'a category with books is kept');
        $page->call('delete', $sci->id);
        $this->assertNotNull(BookCategory::find($sci->id), 'a category with subcategories is kept');
    }

    // ===================== e-book reading =====================

    public function test_staff_can_open_ebooks_and_every_opening_is_logged(): void
    {
        $e = $this->ebook();

        $r = $this->get("/app/{$this->library->slug}/ebooks/{$e->id}/view")->assertOk();
        $this->assertStringContainsString('inline', $r->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->get("/app/{$this->library->slug}/ebooks/{$e->id}/download")->assertOk();

        $this->assertSame(['view', 'download'], EbookAccessLog::orderBy('id')->pluck('action')->all());
    }

    public function test_students_need_a_running_membership_to_read(): void
    {
        $e = $this->ebook();
        [$student, $login] = $this->student();

        $this->actingAs($login);
        $this->get("/app/{$this->library->slug}/ebooks/{$e->id}/view")->assertOk();
        $this->get("/app/{$this->library->slug}/ebooks")->assertOk()->assertSee('Python Basics');
        $this->assertSame($student->id, EbookAccessLog::first()->student_id);

        // students never reach the staff catalogue
        $this->get("/app/{$this->library->slug}/books")->assertForbidden();

        $this->actingAs($this->owner);
        Tenancy::set($this->library);
        [, $noMembership] = $this->student('9000000002', false);
        $this->actingAs($noMembership);
        $this->get("/app/{$this->library->slug}/ebooks/{$e->id}/view")->assertForbidden();
        $this->get("/app/{$this->library->slug}/ebooks")->assertOk()->assertSee('running membership');

        // unless the library lets everyone read
        $this->actingAs($this->owner);
        Tenancy::set($this->library);
        LibrarySettings::update(['ebook_require_membership' => false]);
        $this->actingAs($noMembership);
        $this->get("/app/{$this->library->slug}/ebooks/{$e->id}/view")->assertOk();
    }

    public function test_download_licence_and_archive_rules(): void
    {
        $locked = $this->ebook(['title' => 'Online Only', 'allow_download' => false]);
        $expired = $this->ebook(['title' => 'Old Licence', 'licence_expires_on' => today()->subDay()->toDateString()]);
        $archived = $this->ebook(['title' => 'Gone']);
        app(ManageBookLifecycle::class)->archive($archived);
        [, $login] = $this->student();

        $this->actingAs($login);
        $this->get("/app/{$this->library->slug}/ebooks/{$locked->id}/view")->assertOk();                 // a PDF can be read online
        $this->get("/app/{$this->library->slug}/ebooks/{$locked->id}/download")->assertForbidden();
        $this->get("/app/{$this->library->slug}/ebooks/{$expired->id}/view")->assertForbidden();
        $this->get("/app/{$this->library->slug}/ebooks/{$archived->id}/view")->assertNotFound();
        $this->get("/app/{$this->library->slug}/ebooks")->assertOk()->assertSee('Online Only')->assertDontSee('Old Licence')->assertDontSee('Gone');

        // managers can still open everything
        $this->actingAs($this->owner);
        $this->get("/app/{$this->library->slug}/ebooks/{$locked->id}/download")->assertOk();
        $this->get("/app/{$this->library->slug}/ebooks/{$expired->id}/view")->assertOk();
    }

    public function test_epub_cannot_be_shown_inline_and_a_link_only_title_redirects(): void
    {
        $epub = app(SaveBook::class)(['type' => 'ebook', 'title' => 'Epub Book', 'allow_download' => false], null, null, UploadedFile::fake()->create('b.epub', 50));
        $link = app(SaveBook::class)(['type' => 'ebook', 'title' => 'Linked', 'external_url' => 'https://example.com/x']);
        [, $login] = $this->student();

        $this->actingAs($login);
        $this->get("/app/{$this->library->slug}/ebooks/{$epub->id}/view")->assertForbidden();
        $this->get("/app/{$this->library->slug}/ebooks/{$link->id}/view")->assertRedirect('https://example.com/x');
        $this->assertSame(1, EbookAccessLog::where('book_id', $link->id)->count());
    }

    public function test_other_libraries_cannot_open_the_file_or_cover(): void
    {
        $e = $this->ebook();
        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        $this->actingAs($this->root);
        app(CreateSubscription::class)($other, SubscriptionPlan::first(), 'monthly', true);
        $this->enable($other, 'ebooks');

        $this->actingAs(User::where('email', 'b@beta.test')->first());
        $this->get("/app/{$other->slug}/ebooks/{$e->id}/view")->assertNotFound();

        // and a user of this library cannot reach the other library's URL at all
        $this->actingAs($this->owner);
        $this->get("/app/{$other->slug}/ebooks")->assertNotFound();
    }

    public function test_reading_still_works_when_the_library_is_read_only(): void
    {
        $e = $this->ebook();
        \App\Support\BillingSettings::set(['expiry_policy' => 'read_only']);
        $sub = \App\Models\Subscription::currentFor($this->library->id);
        $sub->forceFill(['status' => 'active', 'trial_ends_on' => today()->subDays(60), 'expires_on' => today()->subDays(60)])->save();
        Entitlements::flush();
        Tenancy::set($this->library);

        $this->assertTrue(Entitlements::readOnly());
        $this->get("/app/{$this->library->slug}/ebooks/{$e->id}/view")->assertOk();
        $this->assertSame(1, EbookAccessLog::count());

        $this->expectException(\App\Exceptions\ReadOnlyLibraryException::class);
        $this->book(['isbn' => '', 'title' => 'Blocked']);
    }

    public function test_book_permissions_reach_the_default_roles(): void
    {
        $map = LibraryRoles::map();
        foreach (LibraryRoles::bookPermissions() as $p) {
            $this->assertContains($p, $map[LibraryRoles::OWNER], "owner has {$p}");
        }
        $this->assertContains('ebooks.read', $map[LibraryRoles::STUDENT]);
        $this->assertNotContains('books.view', $map[LibraryRoles::STUDENT]);
        $this->assertNotContains('fines.waive', $map[LibraryRoles::MANAGER], 'waiving fines stays with the owner');
        $this->assertTrue($this->owner->can('books.manage'));
        $this->assertTrue($this->owner->can('circulation.issue'));
    }
}
