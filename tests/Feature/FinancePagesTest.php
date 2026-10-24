<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\CheckInStudent;
use App\Actions\RecordPayment;
use App\Actions\RegisterLibrary;
use App\Models\Attendance;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Models\User;
use App\Support\LibraryRoles;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FinancePagesTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;

    protected function setUp(): void
    {
        parent::setUp();

        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'owner@alpha.test']);
        $this->actingAs(User::where('email', 'owner@alpha.test')->first());
        Tenancy::set($this->library);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function enrolled(float $price = 1000): array
    {
        $student = Student::create(['name' => 'Priya Singh', 'phone' => (string) random_int(7000000000, 9999999999)]);
        $plan = MembershipPlan::create(['name' => 'Monthly '.uniqid(), 'duration_unit' => 'monthly', 'price' => $price]);

        return [$student, app(AssignMembership::class)($student, $plan, today())];
    }

    protected function userWithRole(string $role): User
    {
        $u = User::factory()->create();
        $this->library->users()->attach($u->id, ['is_active' => true]);
        Tenancy::run($this->library, fn () => $u->assignRole($role));

        return $u->fresh();
    }

    public function test_every_finance_page_renders_with_data(): void
    {
        [$s, $m] = $this->enrolled(1000);
        $p = app(RecordPayment::class)($s, 400, 'upi', today(), $m);
        app(CheckInStudent::class)($s);
        $cat = ExpenseCategory::create(['name' => 'Electricity']);
        $e = new Expense(['expense_category_id' => $cat->id, 'description' => 'October bill', 'amount' => 2500, 'expense_date' => today(), 'method' => 'upi']);
        $e->recorded_by = auth()->id();
        $e->save();
        $slug = $this->library->slug;

        $this->get("/app/{$slug}/payments")->assertOk()->assertSee($p->receipt_no)->assertSee('Priya Singh');
        $this->get("/app/{$slug}/dues")->assertOk()->assertSee('Priya Singh')->assertSee('600.00');
        $this->get("/app/{$slug}/attendance")->assertOk()->assertSee('Priya Singh');
        $this->get("/app/{$slug}/expenses")->assertOk()->assertSee('October bill');
        $this->get("/app/{$slug}/payments/{$p->id}/receipt")->assertOk()->assertSee($p->receipt_no)->assertSee('Priya Singh');
    }

    public function test_record_payment_through_the_page_and_double_submit_is_recorded_once(): void
    {
        [$s, $m] = $this->enrolled(1000);

        $page = Livewire::test('pages::app.payments.index')
            ->call('openPay', $s->id, (string) $m->id)
            ->assertSet('amount', '1000.00')
            ->set('amount', '300')->set('payMethod', 'cash');

        $key = $page->get('idempotencyKey');
        $this->assertNotEmpty($key);

        $page->call('savePay')->assertHasNoErrors();
        $this->assertSame(1, Payment::count());

        // the same form submitted again (double click / retry) does not charge twice
        $page->set('showPay', true)->call('savePay')->assertHasNoErrors();
        $this->assertSame(1, Payment::count());
        $this->assertSame('700.00', $m->fresh()->outstanding());
    }

    public function test_payment_form_validates_and_refuses_overpayment(): void
    {
        [$s, $m] = $this->enrolled(500);

        Livewire::test('pages::app.payments.index')->call('openPay', null)->call('savePay')->assertHasErrors(['studentId', 'amount']);

        Livewire::test('pages::app.payments.index')
            ->call('openPay', $s->id, (string) $m->id)->set('amount', '900')->call('savePay')->assertHasErrors('amount');
        $this->assertSame(0, Payment::count());

        Livewire::test('pages::app.payments.index')
            ->call('openPay', $s->id, (string) $m->id)->set('paidOn', today()->addDay()->toDateString())->call('savePay')->assertHasErrors('paidOn');
    }

    public function test_pay_link_from_the_dues_page_opens_the_prefilled_form(): void
    {
        [$s, $m] = $this->enrolled(750);

        $this->get("/app/{$this->library->slug}/payments?pay={$m->id}")->assertOk()->assertSee('Record payment');

        Livewire::withQueryParams(['pay' => (string) $m->id])->test('pages::app.payments.index')
            ->assertSet('showPay', true)->assertSet('studentId', $s->id)->assertSet('amount', '750.00');
    }

    public function test_refund_and_reversal_through_the_page(): void
    {
        [$s, $m] = $this->enrolled(1000);
        $p = app(RecordPayment::class)($s, 1000, 'cash', today(), $m);

        Livewire::test('pages::app.payments.index')
            ->call('startRefund', $p->id)
            ->set('refundAmount', '2000')->set('refundReason', 'x')->call('saveRefund')->assertHasErrors('refundAmount');

        Livewire::test('pages::app.payments.index')
            ->call('startRefund', $p->id)->set('refundAmount', '250')->set('refundReason', 'Left early')->call('saveRefund')->assertHasNoErrors();
        $this->assertSame(1, Refund::count());
        $this->assertSame('750.00', $m->fresh()->paidAmount());

        // a refunded payment can no longer be reversed
        Livewire::test('pages::app.payments.index')
            ->call('startReverse', $p->id)->set('reverseReason', 'oops')->call('saveReverse')->assertHasErrors('reverseReason');

        [$s2, $m2] = $this->enrolled(500);
        $p2 = app(RecordPayment::class)($s2, 500, 'cash', today(), $m2);
        Livewire::test('pages::app.payments.index')->call('startReverse', $p2->id)->call('saveReverse')->assertHasErrors('reverseReason');
        Livewire::test('pages::app.payments.index')->call('startReverse', $p2->id)->set('reverseReason', 'Wrong student')->call('saveReverse')->assertHasNoErrors();
        $this->assertTrue($p2->fresh()->isReversed());
        $this->assertSame('500.00', $m2->fresh()->outstanding());
    }

    public function test_finance_actions_are_forbidden_without_permission(): void
    {
        [$s, $m] = $this->enrolled(1000);
        $p = app(RecordPayment::class)($s, 500, 'cash', today(), $m);

        $this->actingAs($this->userWithRole(LibraryRoles::RECEPTIONIST)); // can record + print, cannot refund
        Livewire::test('pages::app.payments.index')->call('startRefund', $p->id)->assertForbidden();
        Livewire::test('pages::app.payments.index')->call('startReverse', $p->id)->assertForbidden();
        Livewire::test('pages::app.payments.index')->call('openPay', $s->id)->assertOk();

        $this->actingAs($this->userWithRole(LibraryRoles::SUPERVISOR)); // no finance permissions at all
        $slug = $this->library->slug;
        foreach (['payments', 'dues', 'expenses'] as $page) {
            $this->get("/app/{$slug}/{$page}")->assertForbidden();
        }
        $this->get("/app/{$slug}/payments/{$p->id}/receipt")->assertForbidden();

        $this->actingAs($this->userWithRole(LibraryRoles::ACCOUNTANT)); // finance yes, attendance no
        $this->get("/app/{$slug}/payments")->assertOk();
        $this->get("/app/{$slug}/attendance")->assertForbidden();
    }

    public function test_receipts_do_not_leak_across_libraries(): void
    {
        [$s, $m] = $this->enrolled(1000);
        $p = app(RecordPayment::class)($s, 100, 'cash', today(), $m);

        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        $this->actingAs(User::where('email', 'b@beta.test')->first());

        $this->get("/app/{$beta->slug}/payments/{$p->id}/receipt")->assertNotFound();
        $this->get("/app/{$this->library->slug}/payments/{$p->id}/receipt")->assertNotFound(); // not their library either
    }

    public function test_reversed_receipt_is_marked(): void
    {
        [$s, $m] = $this->enrolled(1000);
        $p = app(RecordPayment::class)($s, 100, 'cash', today(), $m);
        app(\App\Actions\ReversePayment::class)($p, 'Duplicate entry');

        $this->get("/app/{$this->library->slug}/payments/{$p->id}/receipt")->assertOk()->assertSee('Reversed')->assertSee('Duplicate entry');
    }

    public function test_attendance_page_flow(): void
    {
        [$s] = $this->enrolled();
        $bare = Student::create(['name' => 'No Plan', 'phone' => '9000011122']);

        $page = Livewire::test('pages::app.attendance.index')
            ->set('checkInSearch', 'Priya')->assertSee('Priya Singh')
            ->call('checkIn', $s->id);
        $this->assertSame(1, Attendance::whereNull('checked_out_at')->count());

        // duplicate and ineligible check-ins surface as notifications, not duplicates
        $page->call('checkIn', $s->id)->assertDispatched('notify');
        Livewire::test('pages::app.attendance.index')->call('checkIn', $bare->id)->assertDispatched('notify');
        $this->assertSame(1, Attendance::count());

        $visit = Attendance::first();
        $page->call('checkOut', $visit->id);
        $this->assertNotNull($visit->fresh()->checked_out_at);
    }

    public function test_attendance_correction_through_the_page(): void
    {
        $s = Student::create(['name' => 'Late Larry', 'phone' => '9000099999']);
        app(AssignMembership::class)($s, MembershipPlan::create(['name' => 'M', 'duration_unit' => 'monthly', 'price' => 100]), today()->subDays(10));
        Carbon::setTestNow(now()->subDays(2)->setTime(9, 0));
        $visit = app(CheckInStudent::class)($s);
        Carbon::setTestNow();

        $this->get("/app/{$this->library->slug}/attendance")->assertOk()->assertSee('Missed check-out');

        Livewire::test('pages::app.attendance.index')
            ->call('startCorrect', $visit->id)
            ->set('cOut', $visit->checked_in_at->copy()->addHours(5)->format('Y-m-d\TH:i'))
            ->set('cReason', '')->call('saveCorrect')->assertHasErrors('cReason');

        Livewire::test('pages::app.attendance.index')
            ->call('startCorrect', $visit->id)
            ->set('cOut', $visit->checked_in_at->copy()->addHours(5)->format('Y-m-d\TH:i'))
            ->set('cReason', 'Forgot to tap out')->call('saveCorrect')->assertHasNoErrors();

        $this->assertNotNull($visit->fresh()->checked_out_at);
        $this->assertSame(1, $visit->corrections()->count());

        // receptionists cannot correct
        $this->actingAs($this->userWithRole(LibraryRoles::RECEPTIONIST));
        Livewire::test('pages::app.attendance.index')->call('startCorrect', $visit->id)->assertForbidden();
    }

    public function test_expenses_create_validate_and_isolate_categories(): void
    {
        Storage::fake('local');

        Livewire::test('pages::app.expenses.index')->call('create')->call('save')->assertHasErrors(['categoryId', 'description', 'amount']);

        $page = Livewire::test('pages::app.expenses.index')->call('create')
            ->set('newCategory', 'Stationery')->call('addCategory')->assertHasNoErrors();
        $cat = ExpenseCategory::where('name', 'Stationery')->first();
        $this->assertNotNull($cat);

        $page->set('description', 'Printer paper')->set('amount', '480.50')->set('expenseDate', today()->toDateString())
            ->set('vendor', 'Paper World')
            ->set('attachment', UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf'))
            ->call('save')->assertHasNoErrors();

        $e = Expense::first();
        $this->assertSame('480.50', $e->amount);
        $this->assertSame($this->library->id, $e->library_id);
        $this->assertNotNull($e->recorded_by);
        Storage::disk('local')->assertExists($e->attachment_path);
        $this->assertStringStartsWith('expenses/'.$this->library->id, $e->attachment_path);

        // a duplicate category name and a bad file type are refused
        Livewire::test('pages::app.expenses.index')->call('create')->set('newCategory', 'Stationery')->call('addCategory')->assertHasErrors('newCategory');
        Livewire::test('pages::app.expenses.index')->call('create')->set('categoryId', (string) $cat->id)->set('description', 'x')->set('amount', '5')
            ->set('attachment', UploadedFile::fake()->create('malware.exe', 10))->call('save')->assertHasErrors('attachment');

        // a category from another library cannot be used
        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        Tenancy::set($beta);
        $foreign = ExpenseCategory::create(['name' => 'Foreign']);
        Tenancy::set($this->library);

        Livewire::test('pages::app.expenses.index')->call('create')
            ->set('categoryId', (string) $foreign->id)->set('description', 'x')->set('amount', '5')->set('expenseDate', today()->toDateString())
            ->call('save')->assertHasErrors('categoryId');
        $this->assertSame(1, Expense::count());
    }

    public function test_expense_attachments_are_private_and_tenant_scoped(): void
    {
        Storage::fake('local');
        $cat = ExpenseCategory::create(['name' => 'Misc']);
        Storage::disk('local')->put('expenses/'.$this->library->id.'/bill.pdf', 'PDFDATA');
        $e = new Expense(['expense_category_id' => $cat->id, 'description' => 'Bill', 'amount' => 10, 'expense_date' => today(), 'method' => 'cash', 'attachment_path' => 'expenses/'.$this->library->id.'/bill.pdf']);
        $e->save();
        $slug = $this->library->slug;

        $this->get("/app/{$slug}/expenses/{$e->id}/attachment")->assertOk();

        $this->actingAs($this->userWithRole(LibraryRoles::RECEPTIONIST)); // no expenses.view
        $this->get("/app/{$slug}/expenses/{$e->id}/attachment")->assertForbidden();

        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        $this->actingAs(User::where('email', 'b@beta.test')->first());
        $this->get("/app/{$beta->slug}/expenses/{$e->id}/attachment")->assertNotFound();
    }

    public function test_expense_management_needs_the_manage_permission(): void
    {
        $this->actingAs($this->userWithRole(LibraryRoles::MANAGER)); // expenses.view? manager has neither view nor manage
        $this->get("/app/{$this->library->slug}/expenses")->assertForbidden();

        $this->actingAs($this->userWithRole(LibraryRoles::ACCOUNTANT));
        Livewire::test('pages::app.expenses.index')->call('create')->assertOk();
    }

    public function test_dues_page_lists_only_what_is_owed(): void
    {
        [$a, $ma] = $this->enrolled(1000);
        [$b, $mb] = $this->enrolled(500);
        app(RecordPayment::class)($b, 500, 'cash', today(), $mb);

        $component = Livewire::test('pages::app.dues.index');
        $ids = $component->get('rows')->pluck('id')->all();

        $this->assertSame([$ma->id], $ids);
        $this->assertSame('1,000.00', $component->get('total'));
    }
}
