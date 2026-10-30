<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Seat;
use App\Models\Student;
use App\Models\StudentMembership;
use Illuminate\Support\Carbon;

/**
 * The numbers behind the library Dashboard tiles and the Insights page, worked out once per request for the current library.
 * Callers decide which figures a person may see; this class only counts.
 */
class LibraryInsights
{
    protected static ?array $cache = null;
    protected static ?int $cachedFor = null;

    public static function flush(): void
    {
        self::$cache = null;
        self::$cachedFor = null;
    }

    public static function all(): array
    {
        if (self::$cache !== null && self::$cachedFor === Tenancy::currentId()) {
            return self::$cache;
        }

        $today = today();
        $window = max(1, (int) LibrarySettings::get('renewal_window_days'));

        $live = StudentMembership::current()->distinct()->count('student_id');
        $total = Student::count();
        $liveIds = StudentMembership::current()->select('student_id');
        $expired = StudentMembership::where('status', 'active')->whereDate('ends_on', '<', $today)->whereNotIn('student_id', $liveIds)->distinct()->count('student_id');

        $endingBetween = fn (int $from, int $to) => StudentMembership::current()->whereBetween('ends_on', [$today->copy()->addDays($from)->toDateString(), $today->copy()->addDays($to)->toDateString()])->distinct()->count('student_id');
        $expiring = ['1-3' => $endingBetween(1, 3), '4-7' => $endingBetween(4, 7), '8-15' => $endingBetween(8, 15), 'window' => $endingBetween(0, $window)];

        $collected = fn (Carbon $from, Carbon $to) => (float) Payment::where('status', Payment::COMPLETED)->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->sum('amount')
            - (float) Refund::whereBetween('refunded_on', [$from->toDateString(), $to->toDateString()])->sum('amount');
        $money = [
            'today' => $collected($today, $today),
            'month' => $collected($today->copy()->startOfMonth(), $today->copy()->endOfMonth()),
            'last_month' => $collected($today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()),
            'due' => (float) StudentMembership::owing()->withBalance()->get()->sum('balance'),
            'expenses' => (float) Expense::whereBetween('expense_date', [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()])->sum('amount'),
        ];

        $checkedIn = Attendance::whereDate('checked_in_at', $today)->distinct()->count('student_id');
        $seatsTotal = Seat::count();
        $seatsUsed = Seat::where('status', 'occupied')->count();

        $week = collect(range(6, 0))->map(function ($d) {
            $day = today()->subDays($d);

            return ['label' => $day->format('D'), 'value' => Attendance::whereDate('checked_in_at', $day)->distinct()->count('student_id')];
        })->all();

        $pct = fn (float|int $part, float|int $whole) => $whole > 0 ? (int) round($part / $whole * 100) : 0;
        $change = $money['last_month'] > 0 ? (int) round(($money['month'] - $money['last_month']) / $money['last_month'] * 100) : ($money['month'] > 0 ? 100 : 0);

        self::$cachedFor = Tenancy::currentId();

        return self::$cache = [
            'window' => $window,
            'members' => ['live' => $live, 'total' => $total, 'expired' => $expired, 'expiring' => $expiring, 'live_pct' => $pct($live, $total), 'expired_pct' => $pct($expired, $total)],
            'money' => $money + ['change' => $change, 'due_risk' => $pct($money['due'], $money['due'] + $money['month']), 'today_pct' => $pct($money['today'], $money['month'])],
            'attendance' => ['today' => $checkedIn, 'inside' => Attendance::whereNull('checked_out_at')->count(), 'pct' => $pct($checkedIn, $live), 'week' => $week],
            'seats' => ['total' => $seatsTotal, 'used' => $seatsUsed, 'pct' => $pct($seatsUsed, $seatsTotal)],
        ];
    }

    /** One plain sentence about the member base. */
    public static function memberSentence(array $i): string
    {
        $m = $i['members'];
        if ($m['total'] === 0) {
            return 'No students yet. Register your first student to see member insights.';
        }
        if ($m['expired_pct'] >= 30) {
            return "{$m['expired_pct']}% of students have an expired membership. Follow up on renewals.";
        }
        if ($m['expiring']['window'] > 0) {
            return "{$m['expiring']['window']} membership(s) end within {$i['window']} days. Start renewal follow-ups.";
        }

        return "Member base looks stable: {$m['live_pct']}% live, {$m['expired_pct']}% expired.";
    }

    /** One plain sentence about collections. */
    public static function moneySentence(array $i): string
    {
        $m = $i['money'];
        if ($m['month'] <= 0 && $m['last_month'] <= 0) {
            return 'No collections recorded in the last two months.';
        }
        if ($m['last_month'] <= 0) {
            return 'Nothing was collected last month; this month stands at '.self::short($m['month']).". Today is {$m['today_pct']}% of this month.";
        }
        $trend = $m['change'] > 0 ? 'Collection is improving' : ($m['change'] < 0 ? 'Collection is down' : 'Collection is steady');
        $vs = abs($m['change']).'% '.($m['change'] >= 0 ? 'up' : 'down').' vs last month';

        return "{$trend}: this month is {$vs}. Today is {$m['today_pct']}% of this month.";
    }

    /** ₹ with Indian-style short forms for big numbers, e.g. 1500 -> ₹1.5K. */
    public static function short(float|int $v): string
    {
        $v = (float) $v;
        if ($v >= 10000000) {
            return '₹'.rtrim(rtrim(number_format($v / 10000000, 1), '0'), '.').'Cr';
        }
        if ($v >= 100000) {
            return '₹'.rtrim(rtrim(number_format($v / 100000, 1), '0'), '.').'L';
        }
        if ($v >= 1000) {
            return '₹'.rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'K';
        }

        return '₹'.number_format($v);
    }
}
