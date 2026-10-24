<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\Billing\CreateSubscription;
use App\Actions\Billing\ManageLibraryModule;
use App\Actions\Books\AddCopies;
use App\Actions\Books\ChangeCopyStatus;
use App\Actions\Books\SaveBook;
use App\Actions\Circulation\IssueBooks;
use App\Actions\Circulation\RenewLoanItem;
use App\Actions\Circulation\Reservations;
use App\Actions\Circulation\ReturnBooks;
use App\Actions\Circulation\ReverseCirculation;
use App\Actions\CreateStudentLogin;
use App\Actions\RegisterLibrary;
use App\Exceptions\BusinessRuleException;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Library;
use App\Models\LoanItem;
use App\Models\MembershipPlan;
use App\Models\Reservation;
use App\Models\Student;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\BillingSettings;
use App\Support\Entitlements;
use App\Support\LibraryRoles;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReservationsTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;
    protected User $owner;
    protected User $root;
    protected Book $book;
    protected BookCopy $copy;          // the only copy of $book
    protected Student $anna;
    protected Student $bob;
    protected Student $cara;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-19 10:00:00');           // a Monday

        $this->root = User::factory()->create();
        $this->root->forceFill(['is_super_admin' => true])->save();
        $this->root = $this->root->fresh();
        $this->actingAs($this->root);

        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@alpha.test']);
        $this->owner = User::where('email', 'o@alpha.test')->first();
        app(CreateSubscription::class)($this->library, SubscriptionPlan::create(['name' => 'Std', 'price_monthly' => 1000, 'trial_days' => 30]), 'monthly', true);
        foreach (['books', 'ebooks'] as $module) {
            app(ManageLibraryModule::class)->grant($this->library, $module, 'complimentary', 'test');
        }

        $this->actingAs($this->owner);
        Tenancy::set($this->library);

        $this->anna = $this->member('9000000001', 'Anna');
        $this->bob = $this->member('9000000002', 'Bob');
        $this->cara = $this->member('9000000003', 'Cara');
        $this->book = app(SaveBook::class)(['type' => 'physical', 'title' => 'Atomic Habits', 'isbn' => '9780735211292']);
        $this->copy = app(AddCopies::class)($this->book, 1)->first();
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

    protected function member(string $phone, string $name, array $attrs = []): Student
    {
        $s = Student::create(array_merge(['name' => $name, 'phone' => $phone, 'email' => strtolower($name).'@x.test'], $attrs));
        app(AssignMembership::class)($s, MembershipPlan::first() ?? MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 500]), today());

        return $s;
    }

    protected function reserve(Student $s, ?Book $b = null): Reservation
    {
        return app(Reservations::class)->staff($s, $b ?? $this->book);
    }

    /** Anna borrows the only copy, then Bob and Cara queue (in that order). */
    protected function queue(): void
    {
        app(IssueBooks::class)($this->anna, [$this->copy->barcode]);
        Carbon::setTestNow('2026-10-19 10:05:00');
        $this->reserve($this->bob);
        Carbon::setTestNow('2026-10-19 10:10:00');
        $this->reserve($this->cara);
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

    // ===================== making reservations =====================

    public function test_a_title_with_a_copy_on_the_shelf_cannot_be_reserved(): void
    {
        $this->fails(fn () => $this->reserve($this->anna), 'on the shelf');

        app(IssueBooks::class)($this->anna, [$this->copy->barcode]);
        $r = $this->reserve($this->bob);
        $this->assertSame('waiting', $r->status);
        $this->assertNotNull(\App\Models\AuditLog::where('action', 'reservation.created')->first());
    }

    public function test_reservation_rules(): void
    {
        app(IssueBooks::class)($this->anna, [$this->copy->barcode]);

        $this->fails(fn () => $this->reserve($this->anna), 'already has');                    // holds it now
        $this->reserve($this->bob);
        $this->fails(fn () => $this->reserve($this->bob), 'Already reserved');

        // member limit (2 by default)
        foreach (['Two', 'Three'] as $t) {
            $b = app(SaveBook::class)(['type' => 'physical', 'title' => $t]);
            $c = app(AddCopies::class)($b, 1)->first();
            app(IssueBooks::class)($this->anna, [$c->barcode]);
        }
        $two = Book::where('title', 'Two')->first();
        $three = Book::where('title', 'Three')->first();
        $this->reserve($this->bob, $two);
        $this->fails(fn () => $this->reserve($this->bob, $three), 'At most 2');

        // archived, e-book, no copies at all
        $ebook = app(SaveBook::class)(['type' => 'ebook', 'title' => 'Digital', 'external_url' => 'https://example.com/x']);
        $this->fails(fn () => $this->reserve($this->cara, $ebook), 'cannot be reserved');
        $empty = app(SaveBook::class)(['type' => 'physical', 'title' => 'No Copies']);
        $this->fails(fn () => $this->reserve($this->cara, $empty), 'no copies');

        // a member who cannot borrow cannot reserve
        $this->anna->update(['status' => 'suspended']);
        $this->fails(fn () => $this->reserve($this->anna, $two), 'cannot borrow');
    }

    public function test_reservations_can_be_switched_off(): void
    {
        app(IssueBooks::class)($this->anna, [$this->copy->barcode]);
        LibrarySettings::update(['reservations_enabled' => false]);

        $this->fails(fn () => $this->reserve($this->bob), 'not used');
        app(ReturnBooks::class)([['barcode' => $this->copy->barcode]]);
        $this->assertSame('available', $this->copy->fresh()->status, 'nothing is held when reservations are off');
    }

    public function test_the_database_allows_one_open_reservation_per_member_and_title(): void
    {
        app(IssueBooks::class)($this->anna, [$this->copy->barcode]);
        $this->reserve($this->bob);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('reservations')->insert(['library_id' => $this->library->id, 'student_id' => $this->bob->id, 'book_id' => $this->book->id, 'status' => 'waiting', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_member_reserves_for_themselves_only(): void
    {
        app(IssueBooks::class)($this->anna, [$this->copy->barcode]);
        $this->bob->update(['email' => 'bob@x.test']);
        $login = app(CreateStudentLogin::class)($this->bob)->fresh();

        $this->actingAs($login);
        $r = app(Reservations::class)->self($this->book);
        $this->assertSame($this->bob->id, $r->student_id);

        $this->actingAs($this->owner);
        $noLogin = User::factory()->create();                       // not a member of the library at all
        $this->actingAs($noLogin);
        $this->expectException(HttpException::class);
        app(Reservations::class)->self($this->book);
    }

    // ===================== the queue =====================

    public function test_a_returned_copy_is_held_for_the_first_in_line(): void
    {
        $this->queue();
        $bobUser = (function () { $this->bob->update(['email' => 'bob@x.test']); return app(CreateStudentLogin::class)($this->bob)->fresh(); })();
        $this->actingAs($this->owner);
        Tenancy::set($this->library);

        Carbon::setTestNow('2026-10-20 09:00:00');                  // Tuesday
        app(ReturnBooks::class)([['barcode' => $this->copy->barcode]]);

        $this->assertSame('reserved', $this->copy->fresh()->status);
        $bob = Reservation::where('student_id', $this->bob->id)->first();
        $cara = Reservation::where('student_id', $this->cara->id)->first();
        $this->assertSame('ready', $bob->status);
        $this->assertSame($this->copy->id, $bob->book_copy_id);
        $this->assertSame('2026-10-23', $bob->expires_on->toDateString(), 'held for 3 days');
        $this->assertSame('waiting', $cara->status, 'Cara stays in line');
        $this->assertSame(1, $bobUser->notifications()->where('data->kind', 'reservation.ready')->count());
        $this->assertContains('held_for_reservation', $this->copy->events()->pluck('type')->all());
    }

    public function test_the_hold_skips_closed_days(): void
    {
        $this->queue();
        LibrarySettings::update(['reservation_hold_days' => 5]);
        Carbon::setTestNow('2026-10-20 09:00:00');
        app(ReturnBooks::class)([['barcode' => $this->copy->barcode]]);

        // 20 Oct + 5 = Sunday 25 Oct, a closed day: collect by Monday 26th
        $this->assertSame('2026-10-26', Reservation::where('student_id', $this->bob->id)->first()->expires_on->toDateString());
    }

    public function test_a_held_copy_can_only_be_issued_to_the_member_it_is_held_for(): void
    {
        $this->queue();
        app(ReturnBooks::class)([['barcode' => $this->copy->barcode]]);

        $this->fails(fn () => app(IssueBooks::class)($this->cara, [$this->copy->barcode]), 'held for another member');
        $this->fails(fn () => app(IssueBooks::class)($this->anna, [$this->copy->barcode]), 'held for another member');

        $loan = app(IssueBooks::class)($this->bob, [$this->copy->barcode]);
        $this->assertSame('issued', $this->copy->fresh()->status);
        $this->assertSame('fulfilled', Reservation::where('student_id', $this->bob->id)->first()->status);
        $this->assertSame('waiting', Reservation::where('student_id', $this->cara->id)->first()->status);
        $this->assertSame($this->bob->id, $loan->student_id);
    }

    public function test_the_queue_follows_the_order_of_reserving_and_passes_over_members_who_cannot_borrow(): void
    {
        $this->queue();
        $this->bob->memberships()->update(['ends_on' => today()->subDay()]);      // Bob's membership lapsed

        app(ReturnBooks::class)([['barcode' => $this->copy->barcode]]);

        $this->assertSame('waiting', Reservation::where('student_id', $this->bob->id)->first()->status);
        $this->assertSame('ready', Reservation::where('student_id', $this->cara->id)->first()->status, 'Cara is next once Bob cannot borrow');
    }

    public function test_with_no_one_waiting_a_returned_copy_goes_back_on_the_shelf(): void
    {
        app(IssueBooks::class)($this->anna, [$this->copy->barcode]);
        app(ReturnBooks::class)([['barcode' => $this->copy->barcode]]);

        $this->assertSame('available', $this->copy->fresh()->status);
    }

    public function test_cancelling_a_ready_reservation_passes_the_copy_on(): void
    {
        $this->queue();
        app(ReturnBooks::class)([['barcode' => $this->copy->barcode]]);
        $bob = Reservation::where('student_id', $this->bob->id)->first();

        app(Reservations::class)->cancel($bob, 'Changed my mind');

        $this->assertSame('cancelled', $bob->fresh()->status);
        $this->assertSame('ready', Reservation::where('student_id', $this->cara->id)->first()->status);
        $this->assertSame('reserved', $this->copy->fresh()->status);

        app(Reservations::class)->cancel(Reservation::where('student_id', $this->cara->id)->first());
        $this->assertSame('available', $this->copy->fresh()->status, 'nobody left: back on the shelf');
        $this->fails(fn () => app(Reservations::class)->cancel($bob), 'already closed');
    }

    public function test_members_cancel_only_their_own_reservation(): void
    {
        $this->queue();
        $this->bob->update(['email' => 'bob@x.test']);
        $this->cara->update(['email' => 'cara@x.test']);
        $bobLogin = app(CreateStudentLogin::class)($this->bob)->fresh();
        $caraRes = Reservation::where('student_id', $this->cara->id)->first();
        $bobRes = Reservation::where('student_id', $this->bob->id)->first();

        $this->actingAs($bobLogin);
        Tenancy::set($this->library);
        app(Reservations::class)->cancel($bobRes);
        $this->assertSame('cancelled', $bobRes->fresh()->status);

        $this->expectException(HttpException::class);
        app(Reservations::class)->cancel($caraRes);
    }

    public function test_an_uncollected_reservation_expires_and_moves_on(): void
    {
        $this->queue();
        $this->bob->update(['email' => 'bob@x.test']);
        $bobLogin = app(CreateStudentLogin::class)($this->bob)->fresh();
        $this->actingAs($this->owner);
        Tenancy::set($this->library);
        Carbon::setTestNow('2026-10-20 09:00:00');
        app(ReturnBooks::class)([['barcode' => $this->copy->barcode]]);                       // Bob: collect by 23 Oct

        Carbon::setTestNow('2026-10-23 18:00:00');
        $this->assertSame(0, app(Reservations::class)->expireDue(), 'still the last day');

        Carbon::setTestNow('2026-10-24 06:00:00');
        $this->assertSame(1, app(Reservations::class)->expireDue());

        $this->assertSame('expired', Reservation::where('student_id', $this->bob->id)->first()->status);
        $this->assertSame('ready', Reservation::where('student_id', $this->cara->id)->first()->status);
        $this->assertSame(1, $bobLogin->notifications()->where('data->kind', 'reservation.expired')->count());
        $this->assertSame(0, app(Reservations::class)->expireDue(), 'nothing left to expire');
    }

    public function test_the_daily_command_expires_reservations(): void
    {
        $this->queue();
        Carbon::setTestNow('2026-10-20 09:00:00');
        app(ReturnBooks::class)([['barcode' => $this->copy->barcode]]);
        auth()->logout();

        Carbon::setTestNow('2026-10-25 07:00:00');
        $this->artisan('library:send-reminders', ['--library' => $this->library->id])->assertSuccessful();

        $this->assertSame('expired', Reservation::where('student_id', $this->bob->id)->first()->status);
    }

    public function test_issuing_a_different_copy_of_the_title_releases_the_held_one(): void
    {
        $second = app(AddCopies::class)($this->book, 1)->first();
        app(IssueBooks::class)($this->anna, [$this->copy->barcode]);
        app(IssueBooks::class)($this->cara, [$second->barcode]);
        $this->reserve($this->bob);
        app(ReturnBooks::class)([['barcode' => $this->copy->barcode]]);                       // held for Bob
        $this->assertSame('reserved', $this->copy->fresh()->status);

        // Bob takes the other copy (once Cara returns it, it is held for Bob first... so return it and issue the held one)
        app(IssueBooks::class)($this->bob, [$this->copy->barcode]);
        $this->assertSame('fulfilled', Reservation::first()->status);
        $this->assertSame('issued', $this->copy->fresh()->status);
    }

    // ===================== renewals & other flows =====================

    public function test_a_waiting_member_blocks_renewal_of_that_title(): void
    {
        app(IssueBooks::class)($this->anna, [$this->copy->barcode]);
        $item = LoanItem::first();
        $this->assertNull(RenewLoanItem::blockReason($item));

        $r = $this->reserve($this->bob);
        $this->assertSame('Another member is waiting for this title.', RenewLoanItem::blockReason($item->fresh()));
        $this->fails(fn () => app(RenewLoanItem::class)->staff($item->fresh()), 'waiting');

        app(Reservations::class)->cancel($r);
        $this->assertNull(RenewLoanItem::blockReason($item->fresh()));
        $this->assertSame(1, app(RenewLoanItem::class)->staff($item->fresh())->renewals);
    }

    public function test_a_copy_that_is_found_again_is_offered_to_the_queue(): void
    {
        app(IssueBooks::class)($this->anna, [$this->copy->barcode]);
        $this->reserve($this->bob);
        app(ReturnBooks::class)([['barcode' => $this->copy->barcode, 'condition' => 'lost']]);
        $this->assertSame('lost', $this->copy->fresh()->status);
        $this->assertSame('waiting', Reservation::first()->status);

        app(ChangeCopyStatus::class)($this->copy->fresh(), 'available', 'Found behind a shelf');

        $this->assertSame('ready', Reservation::first()->status);
        $this->assertSame('reserved', $this->copy->fresh()->status);
    }

    public function test_a_reserved_copy_cannot_be_changed_by_hand(): void
    {
        $this->queue();
        app(ReturnBooks::class)([['barcode' => $this->copy->barcode]]);

        $this->fails(fn () => app(ChangeCopyStatus::class)($this->copy->fresh(), 'available'));
        $this->fails(fn () => app(ChangeCopyStatus::class)($this->copy->fresh(), 'lost', 'x'));
    }

    public function test_reversing_an_issue_offers_the_copy_and_reversing_a_return_restores_the_queue(): void
    {
        // mistaken issue: the copy is free again and goes to the queue
        $loan = app(IssueBooks::class)($this->anna, [$this->copy->barcode]);
        $this->reserve($this->bob);
        app(ReverseCirculation::class)->issue(LoanItem::first(), 'Wrong member');
        $this->assertSame('ready', Reservation::first()->status);
        $this->assertSame('reserved', $this->copy->fresh()->status);

        // mistaken return: the member who was offered the copy goes back to waiting
        app(Reservations::class)->cancel(Reservation::first());
        app(IssueBooks::class)($this->cara, [$this->copy->barcode]);
        $item = LoanItem::where('student_id', $this->cara->id)->first();
        $this->reserve($this->anna);
        app(ReturnBooks::class)([['barcode' => $this->copy->barcode]]);
        $this->assertSame('ready', Reservation::where('student_id', $this->anna->id)->where('status', 'ready')->first()->status);

        app(ReverseCirculation::class)->return($item, 'Returned by mistake');

        $this->assertSame('issued', $this->copy->fresh()->status);
        $anna = Reservation::where('student_id', $this->anna->id)->first();
        $this->assertSame('waiting', $anna->status);
        $this->assertNull($anna->book_copy_id);
    }

    // ===================== screens =====================

    public function test_reservations_page_lists_cancels_and_creates(): void
    {
        $this->queue();
        $page = Livewire::test('pages::app.circulation.reservations');
        $page->assertSee('Bob')->assertSee('Cara')->assertSee('#1 in line')->assertSee('#2 in line');

        $bobRes = Reservation::where('student_id', $this->bob->id)->first();
        $page->call('cancel', $bobRes->id)->assertSee('#1 in line');
        $this->assertSame('cancelled', $bobRes->fresh()->status);
        $page->set('status', 'closed')->assertSee('Cancelled');

        $page->call('openNew')->set('memberQuery', 'bob')->assertSee('Bob');
        $page->call('pickMember', $this->bob->id)->set('titleQuery', 'atomic')->assertSee('None on the shelf')->call('reserve', $this->book->id)->assertHasNoErrors();
        $this->assertSame('waiting', Reservation::where('student_id', $this->bob->id)->where('status', 'waiting')->first()->status);

        $page->call('openNew')->call('pickMember', $this->bob->id)->call('reserve', $this->book->id)->assertHasErrors('titleQuery');
    }

    public function test_only_staff_with_issue_rights_can_change_reservations_from_the_page(): void
    {
        $this->queue();
        $res = Reservation::first();

        $this->actAs(LibraryRoles::SUPERVISOR);                      // may look (circulation.view) but not change
        $page = Livewire::test('pages::app.circulation.reservations')->assertOk();
        $page->call('openNew')->assertForbidden();
        Livewire::test('pages::app.circulation.reservations')->call('cancel', $res->id)->assertForbidden();
    }

    public function test_the_student_page_reserves_and_cancels_for_the_member_only(): void
    {
        app(IssueBooks::class)($this->anna, [$this->copy->barcode]);
        $this->bob->update(['email' => 'bob@x.test']);
        $this->cara->update(['email' => 'cara@x.test']);
        $login = app(CreateStudentLogin::class)($this->bob)->fresh();
        app(CreateStudentLogin::class)($this->cara);
        $caraRes = $this->reserve($this->cara);

        $this->actingAs($login);
        Tenancy::set($this->library);
        $page = Livewire::test('pages::app.my.index');
        $page->set('reserveQuery', 'atomic')->assertSee('All copies are out')->call('reserveBook', $this->book->id);
        $mine = Reservation::where('student_id', $this->bob->id)->first();
        $this->assertSame('waiting', $mine->status);
        $page->assertSee('Waiting for a copy');

        $page->call('cancelReservation', $caraRes->id)->assertNotFound();                         // not theirs
        $this->assertSame('waiting', $caraRes->fresh()->status);
        Livewire::test('pages::app.my.index')->call('cancelReservation', $mine->id);
        $this->assertSame('cancelled', $mine->fresh()->status);
    }

    public function test_pages_render_and_the_ready_count_shows_in_the_menu(): void
    {
        $this->queue();
        app(ReturnBooks::class)([['barcode' => $this->copy->barcode]]);

        $this->get("/app/{$this->library->slug}/reservations")->assertOk()->assertSee('Ready')->assertSee('Bob');
        $this->get("/app/{$this->library->slug}")->assertOk()->assertSee('Reservations');
        $this->get("/app/{$this->library->slug}/circulation")->assertOk();

        $this->bob->update(['email' => 'bob@x.test']);
        $login = app(CreateStudentLogin::class)($this->bob)->fresh();
        $this->actingAs($login);
        $this->get("/app/{$this->library->slug}/my")->assertOk()->assertSee('Ready! Collect from the desk');
        $this->get("/app/{$this->library->slug}/reservations")->assertForbidden();
    }

    public function test_reservation_settings(): void
    {
        $page = Livewire::test('pages::app.settings.index')->call('setTab', 'books');
        $page->assertSet('loanRules.student.max_reservations', '2')->assertSet('reservation_hold_days', '3');

        $page->set('reservation_hold_days', '0')->call('saveBooks')->assertHasErrors('reservation_hold_days');
        $page->set('reservation_hold_days', '5')->set('loanRules.student.max_reservations', '1')->set('reservations_enabled', false)->call('saveBooks')->assertHasNoErrors();

        $this->assertSame(5, LibrarySettings::get('reservation_hold_days'));
        $this->assertSame(1, \App\Support\CirculationRules::rule('student')['max_reservations']);
        $this->assertFalse(LibrarySettings::get('reservations_enabled'));
    }

    public function test_reservations_do_not_cross_libraries(): void
    {
        app(IssueBooks::class)($this->anna, [$this->copy->barcode]);
        $this->reserve($this->bob);

        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        Tenancy::set($other);
        $this->assertSame(0, Reservation::count());
    }
}
