<?php

namespace App\Reports\Platform;

use App\Models\Subscription;
use App\Reports\Report;
use Illuminate\Support\Collection;

/** Each library's current subscription with its effective (date-aware) status. */
class SubscriptionStatusReport extends Report
{
    public function key(): string { return 'subscription-status'; }
    public function title(): string { return 'Subscription status'; }
    public function description(): string { return 'The current subscription of every library, taking grace periods into account.'; }
    public function permission(): string { return 'reports.view'; }
    public function usesRange(): bool { return false; }

    public function statusOptions(): ?array
    {
        return ['trial' => 'Trial', 'pending' => 'Pending', 'active' => 'Active', 'grace' => 'Grace period', 'expired' => 'Expired', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'];
    }

    public function headers(): array { return ['Code', 'Library', 'Plan', 'Cycle', 'Price', 'Status', 'Started', 'Expires', 'Grace ends']; }

    public function collection(array $f): Collection
    {
        return Subscription::with('library')->whereIn('id', Subscription::selectRaw('max(id)')->groupBy('library_id'))->get()
            ->filter(fn ($s) => empty($f['status']) || $s->effectiveStatus() === $f['status'])
            ->sortBy(fn ($s) => $s->library->name)
            ->map(fn ($s) => [$s->library->code, $s->library->name, $s->plan_name, ucfirst($s->billing_cycle), $this->money($s->price), ucfirst($s->effectiveStatus()),
                $s->starts_on ? $this->date($s->starts_on) : '', $s->expires_on ? $this->date($s->expires_on) : '', $s->graceEndsOn() ? $this->date($s->graceEndsOn()) : ''])
            ->values();
    }

    public function summary(array $f): array
    {
        $by = Subscription::whereIn('id', Subscription::selectRaw('max(id)')->groupBy('library_id'))->get()->groupBy(fn ($s) => $s->effectiveStatus())->map->count();

        return collect($this->statusOptions())->mapWithKeys(fn ($l, $k) => [$l => (string) ($by[$k] ?? 0)])->all();
    }
}
