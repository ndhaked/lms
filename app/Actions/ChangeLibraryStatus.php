<?php

namespace App\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Library;
use Illuminate\Support\Facades\DB;

class ChangeLibraryStatus
{
    /** From => allowed targets. Nothing is ever deleted; data is retained in every state. */
    public const TRANSITIONS = [
        Library::PENDING => [Library::ACTIVE, Library::INACTIVE],
        Library::ACTIVE => [Library::SUSPENDED, Library::INACTIVE],
        Library::SUSPENDED => [Library::ACTIVE, Library::INACTIVE],
        Library::INACTIVE => [Library::ACTIVE],
    ];

    public function __invoke(Library $library, string $to, ?string $reason = null): Library
    {
        return DB::transaction(function () use ($library, $to, $reason) {
            $library = Library::query()->whereKey($library->getKey())->lockForUpdate()->firstOrFail();
            $from = $library->status;

            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new BusinessRuleException("A {$from} library cannot be changed to {$to}.");
            }

            // First activation does not need a reason; every other manual change does.
            if ($from !== Library::PENDING && blank($reason)) {
                throw new BusinessRuleException('A reason is required for this status change.');
            }

            $library->status = $to;
            if ($to === Library::ACTIVE && ! $library->activated_at) {
                $library->activated_at = now();
            }
            $library->save();

            AuditLog::record('library.status_changed', $library, [
                'from' => $from,
                'to' => $to,
                'reason' => $reason,
            ], $library->id);

            return $library;
        });
    }
}
