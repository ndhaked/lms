<?php

namespace App\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Razorpay Standard Checkout, server side.
 *
 * What makes it safe:
 *  - the amount is worked out on our server and fixed in the Razorpay order; the browser never sends an amount;
 *  - after checkout we check Razorpay's signature (HMAC-SHA256 of "order_id|payment_id" with the key secret),
 *    then fetch the payment from Razorpay and check order, amount, currency and status ourselves;
 *  - the webhook is accepted only with a valid X-Razorpay-Signature over the raw body (HMAC-SHA256 with the webhook secret);
 *  - the key secret and webhook secret are stored encrypted and are never shown again.
 */
class Razorpay
{
    protected const API = 'https://api.razorpay.com/v1';

    public static function keyId(): string
    {
        return trim((string) BillingSettings::get('razorpay_key_id'));
    }

    public static function secret(): string
    {
        return self::decrypt((string) BillingSettings::get('razorpay_key_secret'));
    }

    public static function webhookSecret(): string
    {
        return self::decrypt((string) BillingSettings::get('razorpay_webhook_secret'));
    }

    protected static function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return '';
        }
    }

    public static function encrypt(string $value): string
    {
        return $value === '' ? '' : Crypt::encryptString($value);
    }

    /** Switched on by the platform and keys saved. */
    public static function enabled(): bool
    {
        return (bool) BillingSettings::get('razorpay_enabled') && self::keyId() !== '' && self::secret() !== '';
    }

    /** "live" for rzp_live_ keys, "test" for rzp_test_ ones. */
    public static function mode(): string
    {
        return str_starts_with(self::keyId(), 'rzp_live_') ? 'live' : 'test';
    }

    /**
     * Create an order. $paise is the amount in paise.
     *
     * @param  array<string, scalar>  $notes
     * @return array<string, mixed> Razorpay's order
     *
     * @throws \RuntimeException with a message that is safe to show
     */
    public static function createOrder(int $paise, string $receipt, array $notes = []): array
    {
        $response = self::http()->post(self::API.'/orders', ['amount' => $paise, 'currency' => 'INR', 'receipt' => mb_substr($receipt, 0, 40), 'notes' => $notes]);

        if (! $response->successful() || ! preg_match('/^order_[A-Za-z0-9]+$/', (string) $response->json('id'))) {
            throw new \RuntimeException('Razorpay could not create the order: '.mb_substr((string) ($response->json('error.description') ?: 'status '.$response->status()), 0, 150));
        }

        return $response->json();
    }

    /** @return array<string, mixed> the payment as Razorpay holds it */
    public static function fetchPayment(string $paymentId): array
    {
        self::assertPaymentId($paymentId);
        $response = self::http()->get(self::API."/payments/{$paymentId}");
        if (! $response->successful()) {
            throw new \RuntimeException('Could not fetch the payment from Razorpay (status '.$response->status().').');
        }

        return $response->json();
    }

    /** Capture an authorized payment (only needed when the Razorpay account is set to manual capture). */
    public static function capture(string $paymentId, int $paise): bool
    {
        self::assertPaymentId($paymentId);

        return self::http()->post(self::API."/payments/{$paymentId}/capture", ['amount' => $paise, 'currency' => 'INR'])->successful();
    }

    /** A cheap authenticated call, to prove the saved keys work. @return array{0: bool, 1: string} */
    public static function testKeys(): array
    {
        try {
            $r = self::http()->get(self::API.'/orders', ['count' => 1]);
        } catch (\Throwable) {
            return [false, 'Could not reach Razorpay.'];
        }

        return $r->successful() ? [true, 'Connected to Razorpay ('.self::mode().' mode).'] : [false, 'Razorpay refused the keys: '.mb_substr((string) ($r->json('error.description') ?: 'status '.$r->status()), 0, 120)];
    }

    /** The signature Razorpay returns to the browser after checkout. */
    public static function checkoutSignatureValid(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = self::secret();
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', "{$orderId}|{$paymentId}", $secret), $signature);
    }

    /** The signature on a webhook, over the raw request body. */
    public static function webhookSignatureValid(string $rawBody, string $signature): bool
    {
        $secret = self::webhookSecret();
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public static function validPaymentId(string $id): bool
    {
        return (bool) preg_match('/^pay_[A-Za-z0-9]{6,30}$/', $id);
    }

    public static function validOrderId(string $id): bool
    {
        return (bool) preg_match('/^order_[A-Za-z0-9]{6,30}$/', $id);
    }

    protected static function assertPaymentId(string $id): void
    {
        if (! self::validPaymentId($id)) {
            throw new \InvalidArgumentException('Invalid payment id.');
        }
    }

    protected static function http()
    {
        return Http::withBasicAuth(self::keyId(), self::secret())->acceptJson()->timeout(15);
    }
}
