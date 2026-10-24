<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\AuthorizesPlatform;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\LibraryModule;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\BillingSettings;
use App\Support\Entitlements;
use App\Support\Notifier;
use Carbon\CarbonInterface;

/**
 * Platform staff decide which optional modules a library may use, beyond what its plan includes:
 *  - a paid add-on: usable now, billed together with the subscription at its next payment;
 *  - a complimentary module: free, optionally until a date.
 * Every change needs a reason and is recorded in the audit log.
 */
class ManageLibraryModule
{
    use AuthorizesPlatform;

    /** The platform's standard add-on prices for a module (monthly, quarterly, annual). */
    public static function defaultPrices(string $module): array
    {
        $p = (array) (BillingSettings::get('module_prices')[$module] ?? []);

        return ['monthly' => $p['monthly'] ?? null, 'quarterly' => $p['quarterly'] ?? null, 'annual' => $p['annual'] ?? null];
    }

    public function grant(Library $library, string $module, string $type, string $reason, ?array $prices = null, ?CarbonInterface $expiresOn = null): LibraryModule
    {
        $this->authorizePlatform('subscriptions.manage');

        if (! array_key_exists($module, SubscriptionPlan::MODULES)) {
            throw new BusinessRuleException('Unknown module.');
        }
        if (! array_key_exists($type, LibraryModule::TYPES)) {
            throw new BusinessRuleException('Choose paid add-on or free.');
        }
        if (blank($reason)) {
            throw new BusinessRuleException('A reason is required.');
        }

        $subscription = Subscription::currentFor($library->id);
        if ($subscription && $subscription->hasModule($module)) {
            throw new BusinessRuleException((SubscriptionPlan::MODULES[$module]).' is already included in this library\'s plan.');
        }

        $data = ['type' => $type, 'status' => 'active', 'granted_by' => auth()->id(), 'reason' => mb_substr(trim($reason), 0, 500), 'revoked_at' => null,
            'price_monthly' => null, 'price_quarterly' => null, 'price_annual' => null, 'expires_on' => null];

        if ($type === LibraryModule::ADDON) {
            $prices = array_filter($prices ?? self::defaultPrices($module), fn ($v) => $v !== null && $v !== '');
            if (! isset($prices['monthly']) || ! is_numeric($prices['monthly']) || (float) $prices['monthly'] <= 0) {
                throw new BusinessRuleException('A paid add-on needs a monthly price greater than zero.');
            }
            foreach (['monthly', 'quarterly', 'annual'] as $cycle) {
                if (isset($prices[$cycle])) {
                    if (! is_numeric($prices[$cycle]) || (float) $prices[$cycle] < 0 || (float) $prices[$cycle] > 9999999) {
                        throw new BusinessRuleException("The {$cycle} price is not valid.");
                    }
                    $data["price_{$cycle}"] = number_format((float) $prices[$cycle], 2, '.', '');
                }
            }
        } elseif ($expiresOn) {
            if ($expiresOn->lt(today())) {
                throw new BusinessRuleException('The end date must be today or later.');
            }
            $data['expires_on'] = $expiresOn->copy()->startOfDay();
        }

        $row = LibraryModule::updateOrCreate(['library_id' => $library->id, 'module' => $module], $data);
        Entitlements::flush();

        AuditLog::record('library_module.granted', $library, ['module' => $module, 'type' => $type, 'prices' => array_intersect_key($data, array_flip(['price_monthly', 'price_quarterly', 'price_annual'])),
            'expires_on' => $data['expires_on']?->toDateString(), 'reason' => $data['reason']], $library->id);
        Notifier::library($library, 'billing.view', 'module.granted', (SubscriptionPlan::MODULES[$module]).' enabled',
            $type === LibraryModule::ADDON ? 'It is billed with your next subscription payment.' : 'It is free for your library'.($data['expires_on'] ? ' until '.$data['expires_on']->format('d M Y') : '').'.',
            route('app.subscription.index', ['library' => $library->slug]));

        return $row;
    }

    public function revoke(Library $library, string $module, string $reason): void
    {
        $this->authorizePlatform('subscriptions.manage');

        if (blank($reason)) {
            throw new BusinessRuleException('A reason is required.');
        }

        $row = LibraryModule::where('library_id', $library->id)->where('module', $module)->where('status', 'active')->first();
        if (! $row) {
            throw new BusinessRuleException('This module was not given separately. To remove one that is part of the plan, change the plan.');
        }

        $row->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        Entitlements::flush();

        AuditLog::record('library_module.revoked', $library, ['module' => $module, 'type' => $row->type, 'reason' => mb_substr(trim($reason), 0, 500)], $library->id);
        Notifier::library($library, 'billing.view', 'module.revoked', (SubscriptionPlan::MODULES[$module] ?? $module).' disabled', $reason, route('app.subscription.index', ['library' => $library->slug]));
    }
}
