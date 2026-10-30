<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\HomeController;
use App\Http\Resources\Api\V1\Present;
use App\Models\Library;
use App\Models\ReferralCoin;
use App\Models\SupportRequest;
use App\Support\BillingSettings;
use App\Support\HelpContent;
use App\Support\Notifier;
use App\Support\Qr;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Support & more: refer and earn, Need help (FAQs in English / Hindi and support requests), contact us, QR codes. */
class SupportController extends Controller
{
    public function refer(): JsonResponse
    {
        $this->authorizePermission('billing.view');
        $lib = $this->library();
        $link = url('/').'?ref='.$lib->code;
        $app = BillingSettings::get('app_name') ?: config('app.name');

        return $this->ok([
            'enabled' => (bool) BillingSettings::get('referral_enabled'),
            'code' => $lib->code,
            'link' => $link,
            'share_message' => "I run my library on {$app} and it saves me hours every week. Try it with my link: {$link}",
            'reward_coins' => (int) BillingSettings::get('referral_reward_coins'),
            'coin_value' => ReferralCoin::coinValue(),
            'redeem_percent' => (int) BillingSettings::get('referral_redeem_percent'),
            'balance' => ReferralCoin::balance($lib->id),
            'referrals' => Library::where('referred_by_id', $lib->id)->with('subscriptions')->latest('id')->get()->map(fn ($l) => [
                'name' => $l->name, 'joined_on' => Present::d($l->created_at),
                'rewarded' => ReferralCoin::where('library_id', $lib->id)->where('referred_library_id', $l->id)->where('coins', '>', 0)->exists(),
            ])->all(),
            'history' => ReferralCoin::where('library_id', $lib->id)->latest('id')->limit(20)->get()->map(fn ($c) => [
                'coins' => (int) $c->coins, 'kind' => $c->kind, 'description' => $c->description, 'at' => Present::t($c->created_at),
            ])->all(),
        ]);
    }

    /** FAQs in the chosen language (en | hi), help videos, and this library's support requests. */
    public function help(Request $request): JsonResponse
    {
        $lang = $request->query('lang') === 'hi' ? 'hi' : 'en';
        $videos = HelpContent::videos();

        return $this->ok([
            'lang' => $lang,
            'faqs' => collect(HelpContent::faqs())->map(fn ($f) => [
                'key' => $f['key'], 'icon' => $f['icon'], 'question' => $f[$lang][0] ?? $f['en'][0], 'answer' => $f[$lang][1] ?? $f['en'][1], 'video' => $videos[$f['key']] ?? null,
            ])->values()->all(),
            'full_video' => (string) BillingSettings::get('help_full_video') ?: null,
            'requests' => $this->can('billing.view') ? SupportRequest::with('responder:id,name')->where('library_id', Tenancy::currentId())->latest('id')->limit(15)->get()
                ->map(fn ($r) => ['id' => $r->id, 'subject' => $r->subject, 'message' => $r->message, 'status' => $r->status, 'response' => $r->response,
                    'responded_by' => $r->responder?->name, 'responded_at' => Present::t($r->responded_at), 'created_at' => Present::t($r->created_at)])->all() : null,
        ]);
    }

    public function sendRequest(Request $request): JsonResponse
    {
        $this->authorizePermission('billing.view');
        $d = $request->validate(['subject' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'max:3000']], [], ['body' => 'message']);
        $req = SupportRequest::create(['library_id' => Tenancy::currentId(), 'user_id' => auth()->id(), 'subject' => $d['subject'], 'message' => $d['body']]);
        Notifier::platform('libraries.manage', 'support.request', 'Support request: '.$req->subject, $this->library()->name, route('admin.support.index'));

        return $this->message('Sent. We will reply here and in your notifications.', ['id' => $req->id], 201);
    }

    public function contact(): JsonResponse
    {
        $phone = (string) BillingSettings::get('support_phone');
        $app = (string) (BillingSettings::get('app_name') ?: config('app.name'));

        return $this->ok([
            'phone' => $phone ?: null,
            'email' => (string) BillingSettings::get('support_email') ?: null,
            'whatsapp_url' => $phone ? HomeController::whatsappLink($phone, $app) : null,
        ]);
    }

    /** "Generate QR code": login page, website, referral link or any text. Returns the text and an SVG to show or print. */
    public function qr(Request $request): JsonResponse
    {
        $this->authorizePermission('students.view');
        $d = $request->validate(['source' => ['nullable', 'in:login,website,referral,custom'], 'text' => ['nullable', 'required_if:source,custom', 'string', 'max:500']]);
        $target = match ($d['source'] ?? 'login') {
            'website' => url('/'),
            'referral' => url('/').'?ref='.$this->library()->code,
            'custom' => trim((string) $d['text']),
            default => route('login'),
        };

        return $this->ok(['source' => $d['source'] ?? 'login', 'text' => $target, 'svg' => Qr::svg(mb_substr($target, 0, 500), 320)]);
    }
}
