<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Support\BillingSettings;
use App\Support\Entitlements;
use App\Support\Notifier;
use Illuminate\Console\Command;

class SyncSubscriptions extends Command
{
    protected $signature = 'subscriptions:sync';

    protected $description = 'Mark subscriptions that ran past their grace period as expired and send renewal / expiry notifications (run daily).';

    public function handle(): int
    {
        $reminderDays = (int) BillingSettings::get('renewal_reminder_days');
        $expired = $grace = $soon = 0;

        $current = Subscription::with('library')->whereIn('id', Subscription::selectRaw('max(id)')->groupBy('library_id'))->get();

        foreach ($current as $s) {
            $eff = $s->effectiveStatus();
            $until = $s->coveredUntil();
            $url = route('app.subscription.index', ['library' => $s->library->slug]);
            $key = "{$s->id}:{$until?->toDateString()}";

            if ($eff === 'expired' && in_array($s->status, [Subscription::TRIAL, Subscription::ACTIVE], true)) {
                // Persist what the dates already say, with a history entry (no actor: the system did it).
                $from = $s->status;
                $s->forceFill(['status' => Subscription::EXPIRED])->save();
                SubscriptionEvent::log($s, 'expired', $from, Subscription::EXPIRED, 'Grace period ended');
                AuditLog::record('subscription.expired', $s, ['from' => $from], $s->library_id);

                Notifier::library($s->library, 'billing.view', 'subscription.expired', 'Subscription expired',
                    'Your grace period has ended, so only the Subscription page is available. Your data is safe — renew to continue.', $url, "sub-expired:{$key}");
                $expired++;
            } elseif ($eff === 'grace') {
                Notifier::library($s->library, 'billing.view', 'subscription.grace', 'Subscription ended — grace period',
                    "Full access continues until {$s->graceEndsOn()->format('d M Y')}. Please renew.", $url, "sub-grace:{$key}") && $grace++;
            } elseif (in_array($eff, ['active', 'trial'], true) && $s->daysLeft() !== null && $s->daysLeft() <= $reminderDays) {
                $what = $eff === 'trial' ? 'Your trial ends' : 'Your subscription expires';
                Notifier::library($s->library, 'billing.view', 'subscription.expiring', $eff === 'trial' ? 'Trial ending soon' : 'Subscription expiring soon',
                    "{$what} on {$until->format('d M Y')} ({$s->daysLeft()} day(s) left).", $url, "sub-soon:{$key}") && $soon++;
            }
        }

        Entitlements::flush();
        $this->info("Subscriptions checked: {$current->count()} · newly expired: {$expired} · grace notices: {$grace} · expiry reminders: {$soon}");

        return self::SUCCESS;
    }
}
