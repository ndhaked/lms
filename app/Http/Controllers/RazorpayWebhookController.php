<?php

namespace App\Http\Controllers;

use App\Actions\Billing\FulfilOnlinePayment;
use App\Models\OnlinePayment;
use App\Support\Razorpay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Razorpay's server-to-server notifications (payment.captured, order.paid, payment.failed ...).
 * They arrive even when the person closes the browser after paying, so this is what makes a payment reliable.
 *
 * Only a request signed with the webhook secret is believed (HMAC-SHA256 over the raw body, compared in constant time).
 * Nothing in the body is trusted for money: the payment is fetched from Razorpay again before anything is granted.
 * Razorpay retries on any non-2xx answer, so a temporary failure here (for example Razorpay's API being down) returns 500.
 */
class RazorpayWebhookController extends Controller
{
    public function __invoke(Request $request, FulfilOnlinePayment $fulfil)
    {
        if (Razorpay::webhookSecret() === '') {
            return response('Webhook is not configured', 503);
        }

        $raw = $request->getContent();
        if (! Razorpay::webhookSignatureValid($raw, (string) $request->header('X-Razorpay-Signature'))) {
            Log::warning('Razorpay webhook with a bad signature', ['ip' => $request->ip()]);

            return response('Invalid signature', 400);
        }

        $body = json_decode($raw, true);
        if (! is_array($body)) {
            return response('Bad payload', 400);
        }

        $event = (string) ($body['event'] ?? '');
        $eventId = (string) $request->header('X-Razorpay-Event-Id');
        if ($eventId !== '' && DB::table('razorpay_events')->where('event_id', $eventId)->exists()) {
            return response('Already handled', 200);
        }

        $payment = (array) data_get($body, 'payload.payment.entity', []);
        $orderId = (string) ($payment['order_id'] ?? data_get($body, 'payload.order.entity.id', ''));
        $order = Razorpay::validOrderId($orderId) ? OnlinePayment::where('razorpay_order_id', $orderId)->first() : null;

        if ($order) {
            if (in_array($event, ['payment.captured', 'payment.authorized', 'order.paid'], true) && Razorpay::validPaymentId((string) ($payment['id'] ?? ''))) {
                try {
                    // the body only tells us which payment to look at; what it says about the money comes from Razorpay itself
                    $fulfil($order, Razorpay::fetchPayment($payment['id']));
                } catch (\Throwable $e) {
                    report($e);

                    return response('Try again later', 500);
                }
            } elseif ($event === 'payment.failed' && $order->status === 'created') {
                $order->update(['failure_reason' => mb_substr((string) (data_get($payment, 'error_description') ?: 'Payment failed'), 0, 200)]);
            }
        }

        if ($eventId !== '') {
            DB::table('razorpay_events')->insertOrIgnore(['event_id' => $eventId, 'event' => mb_substr($event, 0, 60), 'order_id' => $order?->razorpay_order_id]);
        }

        return response('OK', 200);
    }
}
