<?php

namespace App\Support;

use App\Models\LibraryModule;
use App\Models\SubscriptionPlan;

/** What a library owes for one billing period: the plan plus any paid add-on modules it has been given. */
class Pricing
{
    /**
     * @return array{lines: array<int, array{label: string, amount: string, module: ?string}>, total: string}
     */
    public static function quote(int $libraryId, SubscriptionPlan $plan, string $cycle): array
    {
        $planPrice = $plan->priceFor($cycle) ?? '0.00';
        $lines = [['label' => $plan->name.' plan ('.strtolower(SubscriptionPlan::CYCLES[$cycle] ?? $cycle).')', 'amount' => number_format((float) $planPrice, 2, '.', ''), 'module' => null]];
        $total = $planPrice;

        $included = (array) $plan->modules;
        foreach (LibraryModule::where('library_id', $libraryId)->where('type', LibraryModule::ADDON)->orderBy('module')->get() as $grant) {
            // Nothing extra to pay for a module the chosen plan already includes.
            if (! $grant->isActive() || in_array($grant->module, $included, true)) {
                continue;
            }
            $amount = $grant->priceFor($cycle);
            $lines[] = ['label' => (SubscriptionPlan::MODULES[$grant->module] ?? $grant->module).' add-on', 'amount' => $amount, 'module' => $grant->module];
            $total = bcadd($total, $amount, 2);
        }

        return ['lines' => $lines, 'total' => number_format((float) $total, 2, '.', '')];
    }
}
