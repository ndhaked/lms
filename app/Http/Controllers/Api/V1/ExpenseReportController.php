<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\Present;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Reports\Report;
use App\Reports\ReportExporter;
use App\Reports\ReportRegistry;
use App\Support\Entitlements;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Expenses (Expenses module) and reports (Reports module): view on the phone, or download as CSV / Excel / PDF. */
class ExpenseReportController extends Controller
{
    // ---------- expenses ----------

    public function expenses(Request $request): JsonResponse
    {
        $this->requireModule('expenses');
        $this->authorizePermission('expenses.view');
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d']]);
        $q = trim((string) ($f['q'] ?? ''));
        $base = fn () => Expense::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('description', 'like', "%{$q}%")->orWhere('vendor', 'like', "%{$q}%")))
            ->when($f['category_id'] ?? null, fn ($w, $c) => $w->where('expense_category_id', $c))
            ->when($f['from'] ?? null, fn ($w, $d) => $w->whereDate('expense_date', '>=', $d))
            ->when($f['until'] ?? null, fn ($w, $d) => $w->whereDate('expense_date', '<=', $d));

        $page = $base()->with(['category:id,name', 'recorder:id,name'])->latest('expense_date')->latest('id')->paginate($this->perPage());

        return $this->paged($page, fn ($e) => $this->expense($e), [
            'total' => Present::money($base()->sum('amount')),
            'month_total' => Present::money(Expense::whereBetween('expense_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('amount')),
            'categories' => ExpenseCategory::orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
        ]);
    }

    public function storeExpense(Request $request): JsonResponse
    {
        return $this->saveExpense($request, null);
    }

    public function updateExpense(Request $request, int $expense): JsonResponse
    {
        return $this->saveExpense($request, $expense);
    }

    protected function saveExpense(Request $request, ?int $id): JsonResponse
    {
        $this->requireModule('expenses');
        $this->authorizePermission('expenses.manage');
        $this->writable();
        $data = $request->validate([
            'category_id' => ['required', Rule::exists('expense_categories', 'id')->where('library_id', Tenancy::currentId())],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'vendor' => ['nullable', 'string', 'max:255'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],   // private file
        ]);

        $e = $id ? Expense::findOrFail($id) : new Expense;
        $e->fill(['expense_category_id' => $data['category_id'], 'description' => $data['description'], 'amount' => $data['amount'],
            'expense_date' => $data['expense_date'], 'method' => $data['method'], 'vendor' => $data['vendor'] ?? null]);
        if ($request->hasFile('attachment')) {
            $e->attachment_path = $request->file('attachment')->store('expenses/'.Tenancy::currentId(), 'local');
        }
        if (! $e->exists) {
            $e->recorded_by = auth()->id();
        }
        $isNew = ! $e->exists;
        $changed = array_keys($e->getDirty());
        $e->save();
        AuditLog::record($isNew ? 'expense.recorded' : 'expense.updated', $e, $isNew ? ['amount' => (string) $e->amount, 'category' => $e->expense_category_id] : ['changed' => $changed]);

        return $this->message('Expense saved.', $this->expense($e->load(['category', 'recorder'])), $isNew ? 201 : 200);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->requireModule('expenses');
        $this->authorizePermission('expenses.manage');
        $this->writable();
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('expense_categories', 'name')->where('library_id', Tenancy::currentId())]]);
        $c = ExpenseCategory::create(['name' => $data['name']]);

        return $this->message('Category added.', ['id' => $c->id, 'name' => $c->name], 201);
    }

    public function attachment(int $expense)
    {
        $this->requireModule('expenses');
        $this->authorizePermission('expenses.view');
        $e = Expense::findOrFail($expense);
        abort_unless($e->attachment_path && Storage::disk('local')->exists($e->attachment_path), 404);

        return Storage::disk('local')->response($e->attachment_path, null, ['X-Content-Type-Options' => 'nosniff']);
    }

    protected function expense(Expense $e): array
    {
        return [
            'id' => $e->id, 'category' => $e->category ? ['id' => $e->category->id, 'name' => $e->category->name] : null,
            'description' => $e->description, 'amount' => Present::money($e->amount), 'expense_date' => Present::d($e->expense_date),
            'method' => $e->method, 'method_label' => Payment::METHODS[$e->method] ?? $e->method, 'vendor' => $e->vendor,
            'has_attachment' => (bool) $e->attachment_path, 'recorded_by' => $e->recorder?->name,
        ];
    }

    // ---------- reports ----------

    /** The reports this person may open (by permission and plan modules). */
    public function reports(): JsonResponse
    {
        $this->requireModule('reports');
        $user = auth()->user();
        $exports = Entitlements::hasModule('exports') && LibrarySettings::get('export_enabled');

        return $this->ok(ReportRegistry::visibleTo($user)->map(fn (Report $r) => [
            'key' => $r->key(), 'title' => $r->title(), 'description' => $r->description(),
            'uses_range' => $r->usesRange(), 'status_options' => $r->statusOptions(), 'status_label' => $r->statusLabel(),
            'defaults' => $r->defaultFilters(),
            'can_export' => $exports && $user->can($r->exportPermission()),
        ])->values()->all());
    }

    /** One report as data: headers, a page of rows and the summary figures. */
    public function report(Request $request, string $report): JsonResponse
    {
        $r = $this->definition($report);
        $filters = $this->filters($request, $r);
        $page = max(1, (int) $request->query('page', 1));
        $per = $this->perPage();

        if ($query = $r->query($filters)) {
            $p = $query->paginate($per, ['*'], 'page', $page);
            $rows = collect($p->items())->map(fn ($m) => $r->row($m))->all();
            $meta = ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()];
        } else {
            $all = $r->collection($filters)->values();
            $rows = $all->forPage($page, $per)->values()->all();
            $meta = ['page' => $page, 'per_page' => $per, 'total' => $all->count(), 'last_page' => max(1, (int) ceil($all->count() / $per))];
        }

        return response()->json(['data' => ['key' => $r->key(), 'title' => $r->title(), 'filters' => $filters, 'headers' => $r->headers(), 'rows' => $rows, 'summary' => $r->summary($filters)], 'meta' => $meta]);
    }

    /** Download: format csv | xlsx | pdf. Needs the export permission and the Data exports module, as on the web. */
    public function export(Request $request, string $report, string $format)
    {
        $r = $this->definition($report);
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 404);
        abort_unless(auth()->user()->can($r->exportPermission()), 403, 'You may view this report but not export it.');
        abort_unless(LibrarySettings::get('export_enabled'), 403, 'Exports are disabled for this library.');
        abort_unless(Entitlements::hasModule('exports'), 403, 'Data exports are not included in your plan.');

        $filters = $this->filters($request, $r);
        AuditLog::record('report.exported', null, ['report' => $r->key(), 'format' => $format, 'filters' => array_filter($filters), 'via' => 'app']);
        $name = sprintf('%s-%s-%s.%s', $r->key(), Tenancy::slug(), now()->format('Ymd-His'), $format);

        return match ($format) {
            'csv' => ReportExporter::csv($r, $filters, $name),
            'pdf' => ReportExporter::pdf($r, $filters, $name),
            default => ReportExporter::xlsx($r, $filters, $name),
        };
    }

    protected function definition(string $key): Report
    {
        $this->requireModule('reports');
        $r = ReportRegistry::find($key);
        abort_unless($r, 404);
        abort_unless($r->module() === null || Entitlements::hasModule($r->module()), 403, 'This report is not included in your plan.');
        abort_unless(auth()->user()->can($r->permission()), 403);

        return $r;
    }

    protected function filters(Request $request, Report $r): array
    {
        return array_replace($r->defaultFilters(), array_filter($request->validate([
            'from' => ['nullable', 'date'], 'until' => ['nullable', 'date'], 'status' => ['nullable', 'string', 'max:50'],
        ]), fn ($v) => $v !== null));
    }
}
