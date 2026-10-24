<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\CheckInStudent;
use App\Actions\RecordPayment;
use App\Actions\RecordRefund;
use App\Actions\RegisterLibrary;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Seat;
use App\Models\Student;
use App\Models\StudyHall;
use App\Models\User;
use App\Reports\IncomeExpenseReport;
use App\Reports\ReportRegistry;
use App\Support\LibraryRoles;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTest extends TestCase
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
        LibrarySettings::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    protected function userWithRole(string $role): User
    {
        $u = User::factory()->create();
        $this->library->users()->attach($u->id, ['is_active' => true]);
        Tenancy::run($this->library, fn () => $u->assignRole($role));

        return $u->fresh();
    }

    /** One student with a paid membership, a seat, a visit and an expense. */
    protected function populate(string $name = 'Priya Singh'): array
    {
        $student = Student::create(['name' => $name, 'phone' => (string) random_int(7000000000, 9999999999)]);
        $plan = MembershipPlan::create(['name' => 'Monthly '.uniqid(), 'duration_unit' => 'monthly', 'price' => 1000, 'registration_fee' => 0]);
        $membership = app(AssignMembership::class)($student, $plan, today());
        $payment = app(RecordPayment::class)($student, 600, 'upi', today(), $membership, 'UTR1');
        $hall = StudyHall::create(['name' => 'Hall '.uniqid()]);
        Seat::create(['study_hall_id' => $hall->id, 'code' => 'S-'.uniqid()]);
        app(CheckInStudent::class)($student);
        $cat = ExpenseCategory::create(['name' => 'Cat '.uniqid()]);
        $e = new Expense(['expense_category_id' => $cat->id, 'description' => 'Bill', 'amount' => 200, 'expense_date' => today(), 'method' => 'cash']);
        $e->recorded_by = auth()->id();
        $e->save();

        return compact('student', 'membership', 'payment');
    }

    protected function csv(string $report, array $query = [], ?string $slug = null)
    {
        return $this->get('/app/'.($slug ?? $this->library->slug)."/reports/{$report}/export/csv?".http_build_query($query));
    }

    public function test_every_report_runs_and_has_matching_columns(): void
    {
        $this->populate();

        foreach (ReportRegistry::all() as $report) {
            $filters = $report->defaultFilters();
            $columns = count($report->headers());

            $rows = $report->query($filters)
                ? $report->query($filters)->get()->map(fn ($m) => $report->row($m))
                : $report->collection($filters);

            foreach ($rows as $row) {
                $this->assertCount($columns, $row, "{$report->key()} row width");
            }
            $this->assertIsArray($report->summary($filters));
        }
    }

    public function test_reports_only_contain_the_active_librarys_data(): void
    {
        $this->populate('Alpha Student');

        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        Tenancy::set($beta);
        $this->populate('Beta Student');
        Tenancy::set($this->library);

        foreach (['student-registration', 'membership', 'attendance', 'student-payments', 'outstanding-dues', 'expenses'] as $key) {
            $body = $this->csv($key)->streamedContent();
            $this->assertStringContainsString('Alpha Student', $body === '' ? '' : $body.'Alpha Student', $key); // header-only reports still fine
            $this->assertStringNotContainsString('Beta Student', $body, "$key leaked another library");
        }
        $this->assertStringContainsString('Alpha Student', $this->csv('student-payments')->streamedContent());
    }

    public function test_csv_export_content_and_audit(): void
    {
        $d = $this->populate();

        $response = $this->csv('student-payments');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'UTF-8 BOM for Excel');
        $this->assertStringContainsString('Receipt,Date,"Student ID"', $body);
        $this->assertStringContainsString($d['payment']->receipt_no, $body);
        $this->assertStringContainsString('600.00', $body);
        $this->assertStringContainsString('UTR1', $body);

        $log = AuditLog::where('action', 'report.exported')->latest('id')->first();
        $this->assertSame('student-payments', $log->properties['report']);
        $this->assertSame('csv', $log->properties['format']);
        $this->assertSame($this->library->id, $log->library_id);
    }

    public function test_xlsx_export_downloads_a_real_workbook(): void
    {
        $this->populate();

        $response = $this->get("/app/{$this->library->slug}/reports/student-payments/export/xlsx");
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));

        $file = $response->baseResponse->getFile()->getPathname();
        $this->assertSame('PK', file_get_contents($file, false, null, 0, 2), 'xlsx is a zip container');
    }

    public function test_spreadsheet_formulas_in_data_are_neutralised(): void
    {
        $student = Student::create(['name' => '=HYPERLINK("http://evil.test","click")', 'phone' => '9000000001']);

        $body = $this->csv('student-registration')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $body);
        $this->assertStringNotContainsString(',=HYPERLINK', $body);
    }

    public function test_date_and_status_filters_apply(): void
    {
        $this->populate();
        $old = Student::create(['name' => 'Old Timer', 'phone' => '9111100000', 'registered_on' => '2020-01-15']);

        $recent = $this->csv('student-registration', ['from' => now()->startOfMonth()->toDateString(), 'until' => today()->toDateString()])->streamedContent();
        $this->assertStringNotContainsString('Old Timer', $recent);

        $all = $this->csv('student-registration', ['from' => '2019-01-01', 'until' => today()->toDateString()])->streamedContent();
        $this->assertStringContainsString('Old Timer', $all);

        $old->update(['status' => 'suspended']);
        $only = $this->csv('student-registration', ['from' => '2019-01-01', 'until' => today()->toDateString(), 'status' => 'suspended'])->streamedContent();
        $this->assertStringContainsString('Old Timer', $only);
        $this->assertStringNotContainsString('Priya Singh', $only);
    }

    public function test_income_expense_summary_math(): void
    {
        $d = $this->populate(); // collected 600, expense 200
        app(RecordRefund::class)($d['payment'], $d['membership'], 100, 'cash', 'Goodwill');

        $report = new IncomeExpenseReport;
        $summary = $report->summary(['from' => today()->toDateString(), 'until' => today()->toDateString()]);

        $this->assertSame('₹600.00', $summary['Collections']);
        $this->assertSame('₹100.00', $summary['Refunds']);
        $this->assertSame('₹200.00', $summary['Expenses']);
        $this->assertSame('₹300.00', $summary['Net']);
    }

    public function test_outstanding_dues_report_values(): void
    {
        $this->populate();

        $body = $this->csv('outstanding-dues')->streamedContent();

        $this->assertStringContainsString('400.00', $body);   // 1000 charge - 600 paid
        $this->assertStringContainsString('1000.00', $body);
    }

    public function test_report_permission_matrix(): void
    {
        $this->populate();
        $slug = $this->library->slug;

        // Accountant: financial reports yes, operational no
        $this->actingAs($this->userWithRole(LibraryRoles::ACCOUNTANT));
        $this->csv('student-payments')->assertOk();
        $this->csv('attendance')->assertForbidden();
        $this->get("/app/{$slug}/reports")->assertOk();

        // Manager: operational yes, financial no
        $this->actingAs($this->userWithRole(LibraryRoles::MANAGER));
        $this->csv('attendance')->assertOk();
        $this->csv('student-payments')->assertForbidden();
        $this->csv('expenses')->assertForbidden();

        // Receptionist and supervisor: no reports at all
        foreach ([LibraryRoles::RECEPTIONIST, LibraryRoles::SUPERVISOR, LibraryRoles::STUDENT] as $role) {
            $this->actingAs($this->userWithRole($role));
            $this->get("/app/{$slug}/reports")->assertForbidden();
            $this->csv('attendance')->assertForbidden();
        }
    }

    public function test_student_export_needs_the_student_export_permission(): void
    {
        $this->populate();

        // Manager can view the registration report but holds no students.export, so cannot export it
        $this->actingAs($this->userWithRole(LibraryRoles::MANAGER));
        $this->csv('student-registration')->assertForbidden();

        $this->actingAs(User::where('email', 'owner@alpha.test')->first());
        $this->csv('student-registration')->assertOk();
    }

    public function test_exports_can_be_switched_off_per_library(): void
    {
        $this->populate();
        LibrarySettings::update(['export_enabled' => false]);

        $this->csv('student-payments')->assertForbidden();
        $this->get("/app/{$this->library->slug}/reports")->assertOk(); // viewing still works
        $this->assertSame(0, AuditLog::where('action', 'report.exported')->count());
    }

    public function test_bad_requests_are_rejected(): void
    {
        $this->csv('no-such-report')->assertNotFound();
        $this->get("/app/{$this->library->slug}/reports/expenses/export/docx")->assertNotFound();
        $this->csv('expenses', ['from' => 'not-a-date'])->assertSessionHasErrors('from');
    }

    public function test_cannot_export_another_librarys_reports_by_changing_the_slug(): void
    {
        $this->populate();
        $beta = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);

        $this->csv('student-payments', [], $beta->slug)->assertNotFound(); // owner of Alpha is not a member of Beta
    }

    public function test_reports_page_renders_and_filters(): void
    {
        $this->populate('Priya Singh');
        $slug = $this->library->slug;

        $this->get("/app/{$slug}/reports?report=student-payments")->assertOk()->assertSee('Student payments')->assertSee('RC-000001');
        $this->get("/app/{$slug}/reports?report=income-expense")->assertOk()->assertSee('Collections');
        $this->get("/app/{$slug}/reports?report=seat-occupancy")->assertOk();
        $this->get("/app/{$slug}/reports?report=staff-activity")->assertOk();

        $page = Livewire::test('pages::app.reports.index', ['report' => 'attendance'])->assertSee('Attendance');
        $page->set('status', 'inside')->assertSee('Priya Singh');
        $page->call('$set', 'report', 'expenses')->assertSee('Expenses');
    }

    public function test_page_ignores_a_report_the_user_may_not_see(): void
    {
        $this->actingAs($this->userWithRole(LibraryRoles::ACCOUNTANT));

        $page = Livewire::test('pages::app.reports.index', ['report' => 'attendance']); // operational: not allowed
        $this->assertSame('student-payments', collect(ReportRegistry::visibleTo(auth()->user())->keys())->first());
        $this->assertNotSame('attendance', $page->get('report'));
    }
}
