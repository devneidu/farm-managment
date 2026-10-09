<?php

namespace App\Services\Marketplace;

use App\Models\MarketplacePaymentWebhookEvent;
use App\Models\MarketplaceServicePayment;
use App\Support\Api\ApiHttpException;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Paystack deliveries. Authentication is the HMAC signature over the raw body (anything else answers 401, touches nothing). A delivery is STORED before it
 * is processed; (provider, dedupe_key) is unique, so a redelivery is recognised and acknowledged without granting anything twice. The body is never
 * trusted for amounts or status: processing asks the provider (ServicePaymentSettler). If processing fails for a transient reason the event stays
 * `failed`, the endpoint answers 5xx so Paystack redelivers, and the reconcile command retries it too - a successful payment is never lost.
 */
class MarketplacePaymentWebhook
{
    public const PROVIDER = 'paystack';

    private const MAX_ATTEMPTS = 12;

    public function __construct(private PaystackClient $paystack, private ServicePaymentSettler $settler) {}

    /** @return string ignored | duplicate | processed | pending_confirmation */
    public function receive(string $rawBody, ?string $signature): string
    {
        if (! $this->paystack->validSignature($rawBody, $signature)) {
            throw new ApiHttpException(401, 'invalid_signature', 'Invalid signature.');
        }
        $body = json_decode($rawBody, true);
        $event = is_array($body) ? (string) ($body['event'] ?? '') : '';
        $reference = is_array($body) ? ($body['data']['reference'] ?? null) : null;
        if ($event !== 'charge.success' || ! is_string($reference) || $reference === '') {
            return 'ignored';          // refunds, transfers, subscriptions...: Farmvest acts on successful charges only
        }

        $key = $event.':'.$reference;
        try {
            $row = MarketplacePaymentWebhookEvent::create(['provider' => self::PROVIDER, 'dedupe_key' => $key, 'event' => $event, 'payment_reference' => mb_substr($reference, 0, 40), 'status' => 'received']);
        } catch (UniqueConstraintViolationException) {
            $row = MarketplacePaymentWebhookEvent::where('provider', self::PROVIDER)->where('dedupe_key', $key)->firstOrFail();
            if ($row->status === 'processed') {
                return 'duplicate';
            }
        }

        return $this->process($row);
    }

    /** Settles the payment an event refers to. Throws on a transient failure after recording it, so the caller can answer 5xx. */
    public function process(MarketplacePaymentWebhookEvent $row): string
    {
        $row->forceFill(['attempts' => $row->attempts + 1])->save();
        try {
            $payment = $this->settler->settle((string) $row->payment_reference);
        } catch (Throwable $e) {
            $row->forceFill(['status' => 'failed', 'last_error' => mb_substr($e::class.': '.$e->getMessage(), 0, 255)])->save();
            throw $e;
        }
        if ($payment !== null && $payment->settled_at === null && $payment->status === MarketplaceServicePayment::PENDING) {
            // The provider does not (yet) confirm it: keep the event for the reconcile command rather than discarding it.
            $row->forceFill(['status' => 'failed', 'last_error' => 'not_confirmed'])->save();

            return 'pending_confirmation';
        }
        $row->forceFill(['status' => 'processed', 'processed_at' => now(), 'last_error' => null])->save();

        return 'processed';
    }

    /** Retries stored deliveries that did not finish. @return int how many were attempted */
    public function retryUnfinished(): int
    {
        $n = 0;
        MarketplacePaymentWebhookEvent::whereIn('status', ['failed', 'received'])->where('attempts', '<', self::MAX_ATTEMPTS)->where('updated_at', '<=', now()->subMinutes(2))->orderBy('created_at')->limit(100)->get()
            ->each(function ($row) use (&$n) {
                $n++;
                try {
                    $this->process($row);
                } catch (Throwable) {
                    // recorded on the row; tried again on the next run
                }
            });

        return $n;
    }
}
