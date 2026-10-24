<?php

namespace Database\Seeders;

use App\Actions\AssignMembership;
use App\Actions\Billing\ManageLibraryModule;
use App\Actions\Books\AddCopies;
use App\Actions\Books\SaveBook;
use App\Actions\Circulation\IssueBooks;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\Library;
use App\Models\LoanItem;
use App\Models\MembershipPlan;
use App\Models\Student;
use App\Models\User;
use App\Support\Entitlements;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Test data for the Books module: switches Books and E-books on for the demo libraries, then adds a few books,
 * an e-book, and loans (one of them already overdue) so every screen and the dashboard have something to show.
 */
class DemoBooksSeeder extends Seeder
{
    /** [library slug, its owner's email] */
    protected const LIBRARIES = [
        ['demo-library', 'owner@example.com'],
        ['no-access-library', 'noaccess@example.com'],
    ];

    public function run(): void
    {
        $admin = User::where('email', 'admin@example.com')->first();
        if (! $admin) {
            return;
        }

        foreach (self::LIBRARIES as [$slug, $ownerEmail]) {
            $library = Library::where('slug', $slug)->first();
            $owner = User::where('email', $ownerEmail)->first();
            if (! $library || ! $owner) {
                continue;
            }

            auth()->login($admin);
            foreach (['books', 'ebooks'] as $module) {
                if (! Entitlements::moduleSource($module, $library->id)) {
                    app(ManageLibraryModule::class)->grant($library, $module, 'complimentary', 'Demo data');
                }
            }

            auth()->login($owner);
            Tenancy::run($library, fn () => $this->seed());
        }

        auth()->logout();
    }

    protected function seed(): void
    {
        if (! Book::exists()) {
            $fiction = BookCategory::create(['name' => 'Fiction']);
            $novels = BookCategory::create(['name' => 'Novels', 'parent_id' => $fiction->id]);
            $science = BookCategory::create(['name' => 'Science']);
            $self = BookCategory::create(['name' => 'Self-help']);
            $exam = BookCategory::create(['name' => 'Competitive exams']);

            $books = [
                ['The Alchemist', '9780062315007', 'Paulo Coelho', 'HarperOne', $novels->id, 3, 'A', '2'],
                ['Atomic Habits', '9780735211292', 'James Clear', 'Avery', $self->id, 2, 'B', '1'],
                ['A Brief History of Time', '9780553380163', 'Stephen Hawking', 'Bantam', $science->id, 2, 'C', '3'],
                ['Quantitative Aptitude', '9788121924733', 'R. S. Aggarwal', 'S. Chand', $exam->id, 4, 'D', '1'],
                ['Indian Polity', '9789353165499', 'M. Laxmikanth', 'McGraw Hill', $exam->id, 3, 'D', '2'],
                ['Wings of Fire', '9788173711466', 'A. P. J. Abdul Kalam', 'Universities Press', $self->id, 2, 'B', '2'],
            ];
            foreach ($books as [$title, $isbn, $author, $publisher, $cat, $copies, $shelf, $rack]) {
                $book = app(SaveBook::class)(['type' => 'physical', 'title' => $title, 'isbn' => $isbn, 'author' => $author, 'publisher' => $publisher, 'category_id' => $cat,
                    'language' => 'English', 'shelf' => $shelf, 'rack' => $rack, 'section' => 'General', 'purchase_price' => '350', 'supplier' => 'Local Books']);
                app(AddCopies::class)($book, $copies);
            }

            app(SaveBook::class)(['type' => 'ebook', 'title' => 'Free Python Notes (link)', 'author' => 'Open Library', 'category_id' => $science->id, 'language' => 'English',
                'external_url' => 'https://docs.python.org/3/tutorial/']);
        }

        if (LoanItem::exists()) {
            return;
        }

        // Two members with a running membership: one borrows normally, the other has a book that is already late.
        $plan = MembershipPlan::first() ?? MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 500]);
        $readers = Student::whereHas('memberships', fn ($q) => $q->current())->orderBy('id')->limit(2)->get();
        foreach ([['Test Reader One', '9000022201'], ['Test Reader Two', '9000022202']] as [$name, $phone]) {
            if ($readers->count() >= 2) {
                break;
            }
            $s = Student::firstOrCreate(['phone' => $phone], ['name' => $name, 'email' => str_replace(' ', '', strtolower($name)).'@example.com']);
            app(AssignMembership::class)($s, $plan, today());
            $readers->push($s);
        }

        $copyOf = fn (string $title) => BookCopy::where('status', 'available')->whereHas('book', fn ($q) => $q->where('title', $title))->first();

        if ($c = $copyOf('The Alchemist')) {
            app(IssueBooks::class)($readers[0], [$c->barcode]);
        }
        if (($c = $copyOf('Atomic Habits')) && isset($readers[1])) {
            $loan = app(IssueBooks::class)($readers[1], [$c->barcode]);
            LoanItem::where('loan_id', $loan->id)->update(['issued_at' => now()->subDays(20), 'due_on' => today()->subDays(6)]);   // 6 days overdue
        }
    }
}
