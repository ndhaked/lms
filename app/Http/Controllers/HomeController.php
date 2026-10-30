<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use App\Support\BillingSettings;
use Illuminate\Http\Request;

/** "/": the public website, visible to everyone (signed-in people reach their workspace via the "workspace" route). */
class HomeController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! BillingSettings::get('landing_enabled')) {
            return $request->user() ? redirect()->route('workspace') : redirect()->route('login');
        }

        if (is_string($ref = $request->query('ref')) && preg_match('/^[A-Za-z0-9-]{3,30}$/', $ref)) {
            session(['ref' => strtoupper($ref)]);
        }

        $days = (int) BillingSettings::get('offer_free_days');
        $prices = (array) BillingSettings::get('module_prices');

        return view('marketing.landing', [
            'plans' => SubscriptionPlan::where('status', 'active')->orderBy('price_monthly')->limit(4)->get(),
            'offerDays' => $days,
            'offer' => self::offerLabel($days),
            'addons' => [
                'books' => ['name' => SubscriptionPlan::MODULES['books'], 'price' => $prices['books']['monthly'] ?? null],
                'ebooks' => ['name' => SubscriptionPlan::MODULES['ebooks'], 'price' => $prices['ebooks']['monthly'] ?? null],
            ],
            'appName' => BillingSettings::get('app_name') ?: config('app.name'),
            'supportEmail' => BillingSettings::get('support_email'),
            'supportPhone' => BillingSettings::get('support_phone'),
            'whatsapp' => self::whatsappLink((string) BillingSettings::get('support_phone'), (string) (BillingSettings::get('app_name') ?: config('app.name'))),
        ]);
    }

    /** A wa.me chat link for the support phone (a 10-digit Indian number gets +91), or null if there is no number. */
    public static function whatsappLink(string $phone, string $appName): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }

        return strlen($digits) >= 11 ? 'https://wa.me/'.$digits.'?text='.rawurlencode("Hi, I would like to know more about {$appName} for my library.") : null;
    }

    /** 60 -> "2 months", 30 -> "1 month", 15 -> "15 days", 0 -> null (no offer). */
    public static function offerLabel(int $days): ?string
    {
        if ($days < 1) {
            return null;
        }
        if ($days >= 28 && $days % 30 === 0) {
            $m = intdiv($days, 30);

            return $m.' month'.($m === 1 ? '' : 's');
        }

        return $days.' days';
    }
}
