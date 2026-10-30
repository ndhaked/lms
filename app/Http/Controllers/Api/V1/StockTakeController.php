<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Books\StockTake;
use App\Http\Resources\Api\V1\Present;
use App\Models\InventoryAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Stock-taking (shelf audit) with the phone camera: start a count, scan barcodes one by one, then complete or cancel. */
class StockTakeController extends Controller
{
    public function index(): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('books.audit');

        return $this->ok(InventoryAudit::latest('id')->limit(30)->get()->map(fn ($a) => $this->audit($a))->all());
    }

    public function show(int $audit): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('books.audit');

        return $this->ok($this->audit(InventoryAudit::findOrFail($audit)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('books.audit');
        $this->writable();
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'scope_type' => ['nullable', 'in:all,category,location'], 'scope_value' => ['nullable', 'string', 'max:120']]);
        $a = app(StockTake::class)->start($d['name'], $d['scope_type'] ?? 'all', $d['scope_value'] ?? null);

        return $this->message('Stock-take started.', $this->audit($a), 201);
    }

    /** One scanned code. result: ok | already | unexpected | outside | unknown, with a message to show. */
    public function scan(Request $request, int $audit): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('books.audit');
        $this->writable();
        $d = $request->validate(['code' => ['required', 'string', 'max:60']]);
        $r = app(StockTake::class)->scan(InventoryAudit::findOrFail($audit), $d['code']);

        return $this->ok(['result' => $r['result'], 'message' => $r['message'],
            'copy' => $r['copy'] ? ['id' => $r['copy']->id, 'accession_no' => $r['copy']->accession_no, 'barcode' => $r['copy']->barcode] : null,
            'audit' => $this->audit(InventoryAudit::findOrFail($audit))]);
    }

    public function complete(Request $request, int $audit): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('books.audit');
        $this->writable();
        $d = $request->validate(['mark_missing_lost' => ['nullable', 'boolean'], 'mark_found_available' => ['nullable', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);
        app(StockTake::class)->complete(InventoryAudit::findOrFail($audit), (bool) ($d['mark_missing_lost'] ?? false), (bool) ($d['mark_found_available'] ?? false), $d['note'] ?? null);

        return $this->message('Stock-take completed.', $this->audit(InventoryAudit::findOrFail($audit)));
    }

    public function cancel(int $audit): JsonResponse
    {
        $this->requireModule('books');
        $this->authorizePermission('books.audit');
        $this->writable();
        $a = app(StockTake::class)->cancel(InventoryAudit::findOrFail($audit));

        return $this->message('Stock-take cancelled.', $this->audit($a));
    }

    protected function audit(InventoryAudit $a): array
    {
        return [
            'id' => $a->id, 'name' => $a->name, 'status' => $a->status, 'scope_type' => $a->scope_type, 'scope_value' => $a->scope_value,
            'expected' => (int) $a->expected_count, 'found' => (int) $a->found_count, 'missing' => (int) $a->missing_count, 'unexpected' => (int) $a->unexpected_count,
            'started_at' => Present::t($a->started_at), 'closed_at' => Present::t($a->closed_at), 'note' => $a->note,
        ];
    }
}
