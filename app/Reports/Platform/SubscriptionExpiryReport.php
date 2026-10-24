<?php

namespace App\Reports\Platform;

use App\Models\Subscription;
use App\Reports\Report;
use Illuminate\Support\Collection;

class SubscriptionExpiryReport extends Report
{
    public function key(): string { return 'subscription-expiry'; }
    public function title(): string { return 'Subscription expiry'; }
    public function description(): string { return 'Subscriptions and trials ending in the period (default: next 30 days).'; }
    public function permission(): string { return 'reports.view'; }

    public function defaultFilters(): array
    {
        return ['from' => today()->toDateString(), 'until' => today()->addDays(30)->toDateString(), 'status' => ''];
    }

    public function headers(): array { return ['Code', 'Library', 'Owner', 'Owner email', 'Plan', 'Ends on', 'Days left', 'Status']; }

    public function collection(array $f): Collection
    {
        [$from, $until] = $this->range($f);

        return Subscription::with('library.owner')->whereIn('id', Subscription::selectRaw('max(id)')->groupBy('library_id'))->get()
            ->filter(fn ($s) => in_array($s->status, ['trial', 'active'], true) && $s->coveredUntil() && $s->coveredUntil()->between($from, $until))
            ->sortBy(fn ($s) => $s->coveredUntil())
            ->map(fn ($s) => [$s->library->code, $s->library->name, $s->library->owner?->name, $s->library->owner?->email, $s->plan_name,
                $this->date($s->coveredUntil()), $s->daysLeft(), ucfirst($s->effectiveStatus())])
            ->values();
    }
}
