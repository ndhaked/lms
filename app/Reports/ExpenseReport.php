<?php

namespace App\Reports;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ExpenseReport extends Report
{
    public function key(): string { return 'expenses'; }
    public function title(): string { return 'Expenses'; }
    public function description(): string { return 'Operating expenses by date and category.'; }
    public function permission(): string { return 'reports.financial'; }
    public function statusLabel(): string { return 'Category'; }
    public function statusOptions(): ?array { return ExpenseCategory::orderBy('name')->pluck('name', 'id')->all(); }

    public function headers(): array { return ['Date', 'Category', 'Description', 'Vendor', 'Method', 'Amount', 'Recorded by']; }

    public function query(array $f): ?Builder
    {
        [$from, $until] = $this->range($f);

        return Expense::query()->with(['category', 'recorder'])->whereBetween('expense_date', [$from, $until])
            ->when(! empty($f['status']), fn ($q) => $q->where('expense_category_id', $f['status']))
            ->orderByDesc('expense_date')->orderByDesc('id');
    }

    public function row(Model $m): array
    {
        return [$this->date($m->expense_date), $m->category->name, $m->description, $m->vendor, Payment::METHODS[$m->method] ?? $m->method,
            $this->money($m->amount), $m->recorder?->name];
    }

    public function summary(array $f): array
    {
        return ['Total spent' => '₹'.$this->money($this->query($f)->sum('amount'))];
    }
}
