<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Library;
use App\Support\Entitlements;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Base for the mobile API (v1). Library endpoints run after `library` middleware has checked that the
 * signed-in user belongs to the library in the URL and has made it the active tenant, so every query
 * is scoped to it. Each endpoint still checks its own permission: the app hiding a button is not security.
 */
abstract class Controller extends \App\Http\Controllers\Controller
{
    protected function library(): Library
    {
        return Tenancy::library();
    }

    protected function can(string $permission): bool
    {
        return (bool) auth()->user()?->can($permission);
    }

    /** 403 unless the user holds the permission (any one of "a|b"). */
    protected function authorizePermission(string $permission): void
    {
        $ok = collect(explode('|', $permission))->contains(fn ($p) => $this->can($p));
        abort_unless($ok, 403, 'You do not have permission to do this.');
    }

    protected function requireModule(string $module): void
    {
        abort_unless(collect(explode('|', $module))->contains(fn ($m) => Entitlements::hasModule($m)), 403, 'This feature is not included in your plan.');
    }

    /** Writes are refused while the library is read-only (expired subscription with the read-only policy). */
    protected function writable(): void
    {
        Entitlements::assertWritable();
    }

    protected function ok(array $data = [], int $status = 200, array $extra = []): JsonResponse
    {
        return response()->json(['data' => $data] + $extra, $status);
    }

    protected function message(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message] + ($data ? ['data' => $data] : []), $status);
    }

    /** A page of results in a stable shape: data + meta (page, per_page, total, last_page). */
    protected function paged(LengthAwarePaginator $page, callable $map, array $extra = []): JsonResponse
    {
        return response()->json([
            'data' => collect($page->items())->map($map)->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ] + $extra);
    }

    /** Requested page size, 1..50 (default 20). */
    protected function perPage(): int
    {
        return max(1, min(50, (int) request('per_page', 20)));
    }

    protected function date($value): ?string
    {
        return $value?->toDateString();
    }

    protected function time($value): ?string
    {
        return $value?->toIso8601String();
    }

    protected function money($value): float
    {
        return round((float) $value, 2);
    }
}
