<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Expense receipts live on the private disk and are only served to people allowed to view expenses. */
class ExpenseAttachmentController extends Controller
{
    public function show(Request $request, string $library, string $expense)
    {
        abort_unless($request->user()->can('expenses.view'), 403);

        // Tenant-scoped lookup: another library's expense id is a 404.
        $expense = Expense::findOrFail($expense);

        abort_unless($expense->attachment_path && Storage::disk('local')->exists($expense->attachment_path), 404);

        return Storage::disk('local')->response($expense->attachment_path);
    }
}
