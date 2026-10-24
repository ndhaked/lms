<?php

namespace App\Reports;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Student fee income against operating expenses. SaaS subscription charges are a different ledger
 * and never appear here.
 */
class IncomeExpenseReport extends Report
{
    public function key(): string { return 'income-expense'; }
    public function title(): string { return 'Income and expense summary'; }
    public function description(): string { return 'Collections, refunds and expenses side by side (daily for short ranges, monthly otherwise).'; }
    public function permission(): string { return 'reports.financial'; }

    public function headers(): array { return ['Period', 'Collections', 'Refunds', 'Expenses', 'Net']; }

    public function collection(array $f): Collection
    {
        [$from, $until] = $this->range($f);
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($until)->endOfDay();
        $daily = $start->diffInDays($end) <= 31;
        $key = fn ($d) => Carbon::parse($d)->format($daily ? 'Y-m-d' : 'Y-m');

        $in = Payment::where('status', Payment::COMPLETED)->whereBetween('paid_on', [$start->toDateString(), $end->toDateString()])->get(['paid_on', 'amount'])->groupBy(fn ($p) => $key($p->paid_on))->map->sum('amount');
        $out = Refund::whereBetween('refunded_on', [$start->toDateString(), $end->toDateString()])->get(['refunded_on', 'amount'])->groupBy(fn ($r) => $key($r->refunded_on))->map->sum('amount');
        $exp = Expense::whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])->get(['expense_date', 'amount'])->groupBy(fn ($e) => $key($e->expense_date))->map->sum('amount');

        $rows = collect();
        for ($cursor = $start->copy(); $cursor->lte($end); $daily ? $cursor->addDay() : $cursor->addMonthNoOverflow()->startOfMonth()) {
            $k = $key($cursor);
            $net = (float) ($in[$k] ?? 0) - (float) ($out[$k] ?? 0) - (float) ($exp[$k] ?? 0);
            $rows->push([$cursor->format($daily ? 'd M Y' : 'M Y'), $this->money($in[$k] ?? 0), $this->money($out[$k] ?? 0), $this->money($exp[$k] ?? 0), $this->money($net)]);
        }

        return $rows;
    }

    public function summary(array $f): array
    {
        $rows = $this->collection($f);
        $sum = fn (int $i) => $this->money($rows->sum(fn ($r) => (float) $r[$i]));

        return ['Collections' => '₹'.$sum(1), 'Refunds' => '₹'.$sum(2), 'Expenses' => '₹'.$sum(3), 'Net' => '₹'.$sum(4)];
    }
}
