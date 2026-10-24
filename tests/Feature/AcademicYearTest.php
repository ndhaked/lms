<?php

namespace Tests\Feature;

use App\Actions\AssignMembership;
use App\Actions\RecordPayment;
use App\Actions\RegisterLibrary;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Student;
use App\Models\User;
use App\Support\AcademicYear;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AcademicYearTest extends TestCase
{
    use RefreshDatabase;

    protected Library $library;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 10:00:00');

        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin->fresh());

        $this->library = app(RegisterLibrary::class)(['name' => 'Alpha', 'status' => 'active'], ['name' => 'Owner', 'email' => 'o@alpha.test']);
        $this->owner = User::where('email', 'o@alpha.test')->first();
        $this->actingAs($this->owner);
        Tenancy::set($this->library);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        LibrarySettings::flush();
        Entitlements::flush();
        Tenancy::flush();
        parent::tearDown();
    }

    public function test_year_boundaries_follow_the_start_month(): void
    {
        $this->assertSame(2026, AcademicYear::current());                       // Oct 2026, April start
        [$from, $to] = AcademicYear::range(2026);
        $this->assertSame('2026-04-01', $from->toDateString());
        $this->assertSame('2027-03-31', $to->toDateString());
        $this->assertSame('2026 - 27', AcademicYear::label(2026));

        $this->assertSame(2025, AcademicYear::current(Carbon::parse('2026-03-31')));
        $this->assertSame(2026, AcademicYear::current(Carbon::parse('2026-04-01')));

        LibrarySettings::update(['academic_year_start_month' => 1]);
        $this->assertSame('2026-01-01', AcademicYear::range(2026)[0]->toDateString());
        $this->assertSame('2026-12-31', AcademicYear::range(2026)[1]->toDateString());
        $this->assertSame('2026', AcademicYear::label(2026));

        LibrarySettings::update(['academic_year_start_month' => 9]);
        $this->assertSame(2026, AcademicYear::current());
        $this->assertSame(2025, AcademicYear::current(Carbon::parse('2026-08-31')));
    }

    public function test_options_cover_the_library_history_and_unknown_years_fall_back(): void
    {
        $this->assertSame([2026, 2025], array_keys(AcademicYear::options()));

        Student::create(['name' => 'Old', 'phone' => '9000000001', 'registered_on' => '2023-06-10']);
        $this->assertSame([2026, 2025, 2024, 2023], array_keys(AcademicYear::options()));

        $this->assertSame(2024, AcademicYear::resolve('2024'));
        $this->assertSame(2026, AcademicYear::resolve('1999'));
        $this->assertSame(2026, AcademicYear::resolve('abc'));
        $this->assertSame(2026, AcademicYear::resolve(null));
    }

    public function test_dashboard_figures_follow_the_selected_year(): void
    {
        // one student in the old year, two in the current one
        $old = Student::create(['name' => 'Old Student', 'phone' => '9000000010', 'registered_on' => '2025-06-10']);
        Student::create(['name' => 'New One', 'phone' => '9000000011', 'registered_on' => '2026-06-10']);
        Student::create(['name' => 'New Two', 'phone' => '9000000012', 'registered_on' => '2026-08-10']);

        $plan = MembershipPlan::create(['name' => 'Monthly', 'duration_unit' => 'monthly', 'price' => 1000]);
        $m = app(AssignMembership::class)($old, $plan, Carbon::parse('2025-07-01'));
        app(RecordPayment::class)($old, '400', 'cash', Carbon::parse('2025-07-02'), $m);

        $page = Livewire::test('pages::app.dashboard');
        $this->assertTrue($page->get('period')['current']);
        $cards = collect($page->get('stats'))->keyBy(0);
        $this->assertSame('3', $cards['Total students'][1]);                        // live card

        $page->set('year', '2025');
        $this->assertFalse($page->get('period')['current']);
        $this->assertSame('2025 - 26', $page->get('period')['label']);

        $cards = collect($page->get('stats'))->keyBy(0);
        $this->assertSame('1', $cards['New students'][1]);
        $this->assertSame('1', $cards['Memberships started'][1]);
        $this->assertSame('₹400.00', $cards['Collected in 2025 - 26'][1]);
        $this->assertSame('₹600.00', $cards['Dues still open'][1]);
        $this->assertArrayNotHasKey('Seats occupied', $cards->all(), 'live-only cards are hidden for a past year');

        $this->assertSame(['Old Student'], $page->get('newStudents')->pluck('name')->all());
        $this->assertSame('Apr', $page->get('trend')[0]['label']);
        $this->assertSame(1, $page->get('trend')[0 + 2]['joined'] ?? 0, 'June 2025 is the 3rd month of 2025 - 26');
        $this->assertSame(1, $page->get('recentPayments')->count());

        $page->assertSee('Showing')->assertSee('Back to current year');

        // back to the current year: the past payment no longer shows
        $page->call('$set', 'year', '');
        $this->assertTrue($page->get('period')['current']);
        $this->assertSame(0, $page->get('recentPayments')->count());
        $this->assertSame(['New Two', 'New One'], $page->get('newStudents')->pluck('name')->all());
    }

    public function test_an_unknown_year_in_the_url_shows_the_current_year(): void
    {
        $this->get("/app/{$this->library->slug}?year=1850")->assertOk()->assertSee('2026 - 27');
        $this->get("/app/{$this->library->slug}?year=2025")->assertOk()->assertSee('Showing');
    }

    public function test_the_start_month_is_a_library_setting(): void
    {
        Livewire::test('pages::app.settings.index')->set('academic_year_start_month', '13')->call('saveMemberships')->assertHasErrors('academic_year_start_month');
        Livewire::test('pages::app.settings.index')->set('academic_year_start_month', '1')->call('saveMemberships')->assertHasNoErrors();

        $this->assertSame(1, LibrarySettings::get('academic_year_start_month'));
        $this->assertSame('2026', AcademicYear::label(AcademicYear::current()));
    }

    public function test_each_library_has_its_own_year(): void
    {
        LibrarySettings::update(['academic_year_start_month' => 1]);

        $other = app(RegisterLibrary::class)(['name' => 'Beta', 'status' => 'active'], ['name' => 'B', 'email' => 'b@beta.test']);
        Tenancy::set($other);
        LibrarySettings::flush();

        $this->assertSame(4, LibrarySettings::get('academic_year_start_month'));
        $this->assertSame('2026 - 27', AcademicYear::label(AcademicYear::current()));
    }
}
