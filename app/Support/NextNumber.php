<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Sequential, per-library document numbers (receipts, refunds). Call inside a transaction. */
class NextNumber
{
    public static function for(string $name, string $prefix, int $width = 6): string
    {
        $libraryId = Tenancy::currentId() ?? throw new \LogicException('No active library.');

        DB::table('library_counters')->insertOrIgnore(['library_id' => $libraryId, 'name' => $name, 'value' => 0]);

        // The row lock serialises concurrent requests, so two payments can never share a number.
        $row = DB::table('library_counters')->where('library_id', $libraryId)->where('name', $name)->lockForUpdate()->first();
        $next = $row->value + 1;
        DB::table('library_counters')->where('id', $row->id)->update(['value' => $next]);

        return sprintf('%s-%0'.$width.'d', $prefix, $next);
    }
}
