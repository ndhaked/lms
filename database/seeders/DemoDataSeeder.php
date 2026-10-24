<?php

namespace Database\Seeders;

use App\Actions\AllocateSeat;
use App\Actions\AssignMembership;
use App\Models\Library;
use App\Models\MembershipPlan;
use App\Models\Seat;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Models\StudyHall;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

/** Optional sample records for the demo library: `php artisan db:seed --class=DemoDataSeeder`. */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $library = Library::where('slug', 'demo-library')->first();
        if (! $library) {
            return;
        }

        auth()->setUser(User::where('email', 'owner@example.com')->first());

        if (! Tenancy::run($library, fn () => StudyHall::exists())) {
            $this->operations($library);
        }
        if (! Tenancy::run($library, fn () => \App\Models\Payment::exists())) {
            $this->finance($library);
        }
    }

    protected function finance(Library $library): void
    {
        Tenancy::run($library, function () {
            $methods = ['upi', 'cash', 'upi', 'bank_transfer', 'cash'];
            $students = Student::orderBy('id')->get();

            foreach ($students->take(5) as $i => $student) {
                $membership = StudentMembership::where('student_id', $student->id)->first();
                if (! $membership) {
                    continue;
                }
                // first three pay in full, the rest only part, so dues are visible
                $amount = $i < 3 ? (float) $membership->totalDue() : 500;
                app(\App\Actions\RecordPayment::class)($student, $amount, $methods[$i], today()->subDays($i), $membership, $i === 0 ? 'UTR100234' : null);
            }

            foreach ($students->take(3) as $student) {
                app(\App\Actions\CheckInStudent::class)($student);
            }

            $category = \App\Models\ExpenseCategory::create(['name' => 'Electricity']);
            \App\Models\ExpenseCategory::create(['name' => 'Cleaning']);
            $expense = new \App\Models\Expense(['expense_category_id' => $category->id, 'description' => 'October electricity bill', 'amount' => 4820, 'expense_date' => today()->subDays(3), 'method' => 'upi', 'vendor' => 'State Power Board']);
            $expense->recorded_by = auth()->id();
            $expense->save();
        });
    }

    protected function operations(Library $library): void
    {

        Tenancy::run($library, function () {
            $hallA = StudyHall::create(['name' => 'Silent Hall A', 'capacity' => 40]);
            $hallB = StudyHall::create(['name' => 'Group Study', 'capacity' => 20]);

            foreach (range(1, 16) as $n) {
                Seat::create(['study_hall_id' => $hallA->id, 'code' => 'A-'.str_pad($n, 2, '0', STR_PAD_LEFT)]);
            }
            foreach (range(1, 8) as $n) {
                Seat::create(['study_hall_id' => $hallB->id, 'code' => 'G-'.str_pad($n, 2, '0', STR_PAD_LEFT), 'type' => 'group']);
            }
            Seat::where('code', 'A-16')->update(['status' => Seat::MAINTENANCE]);

            $monthly = MembershipPlan::create(['name' => 'Monthly - Flexible', 'duration_unit' => 'monthly', 'price' => 1200, 'registration_fee' => 100]);
            MembershipPlan::create(['name' => 'Quarterly - Fixed Seat', 'duration_unit' => 'quarterly', 'price' => 3300, 'registration_fee' => 100, 'seat_type' => 'fixed']);

            $students = [
                ['Rahul Sharma', '9810000001'], ['Priya Singh', '9810000002'], ['Amit Verma', '9810000003'],
                ['Neha Gupta', '9810000004'], ['Sneha Reddy', '9810000005'], ['Karan Singh', '9810000006'],
            ];

            foreach ($students as $i => [$name, $phone]) {
                $student = Student::create(['name' => $name, 'phone' => $phone]);
                if ($i < 5) {
                    app(AssignMembership::class)($student, $monthly, today()->subDays($i * 6));
                }
                if ($i < 3) {
                    app(AllocateSeat::class)(Seat::where('code', 'A-'.str_pad($i + 1, 2, '0', STR_PAD_LEFT))->first(), $student);
                }
            }
        });
    }
}
