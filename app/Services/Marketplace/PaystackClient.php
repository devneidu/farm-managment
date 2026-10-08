<?php

namespace App\Services\Marketplace;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The whole Paystack integration: start a transaction, read its true state, and authenticate webhooks. Farmvest collects only its OWN service fees through
 * it (seller plans, promotions). The secret key comes from configuration, is never returned by any endpoint and never appears in an error message.
 */
class PaystackClient
{
    public function configured(): bool
    {
        return filled(config('services.paystack.secret_key'));
    }

    /** Naira (decimal string) to kobo (integer string), exactly. */
    public static function toKobo(string $naira): string
    {
        return bcmul($naira, '100', 0);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{authorization_url: string, access_code: string}
     */
    public function initialize(string $email, string $amount, string $currency, string $reference, ?string $callbackUrl, array $metadata = []): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/transaction/initialize', array_filter([
            'email' => $email, 'amount' => self::toKobo($amount), 'currency' => $currency, 'reference' => $reference, 'callback_url' => $callbackUrl, 'metadata' => $metadata,
        ], fn ($v) => $v !== null)));
        $data = $response->json('data');
        if (! $response->successful() || ! $response->json('status') || ! is_array($data) || empty($data['authorization_url'])) {
            throw new PaymentGatewayException('Paystack did not start the transaction (HTTP '.$response->status().').');
        }

        return ['authorization_url' => (string) $data['authorization_url'], 'access_code' => (string) ($data['access_code'] ?? '')];
    }

    /**
     * The provider's own record of a transaction. `status` is the provider's word (success | failed | abandoned | ongoing | pending | reversed ...), or
     * `not_found` when the provider has never seen the reference.
     *
     * @return array{status: string, reference: string|null, amount: string|null, currency: string|null}
     */
    public function verify(string $reference): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/transaction/verify/'.rawurlencode($reference)));
        if ($response->status() === 404) {
            return ['status' => 'not_found', 'reference' => null, 'amount' => null, 'currency' => null];
        }
        $data = $response->json('data');
        if (! $response->successful() || ! is_array($data) || ! isset($data['status'])) {
            throw new PaymentGatewayException('Paystack verification was unavailable (HTTP '.$response->status().').');
        }

        return [
            'status' => (string) $data['status'], 'reference' => isset($data['reference']) ? (string) $data['reference'] : null,
            'amount' => isset($data['amount']) ? (string) $data['amount'] : null, 'currency' => isset($data['currency']) ? (string) $data['currency'] : null,
        ];
    }

    /** HMAC-SHA512 of the RAW body with the secret key, compared in constant time. */
    public function validSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('services.paystack.secret_key');

        return $secret !== '' && is_string($signature) && $signature !== '' && hash_equals(hash_hmac('sha512', $rawBody, $secret), strtolower($signature));
    }

    private function send(callable $call): Response
    {
        if (! $this->configured()) {
            throw new PaymentGatewayException('Paystack is not configured.');
        }
        try {
            return $call(Http::baseUrl((string) config('services.paystack.base_url'))->withToken((string) config('services.paystack.secret_key'))->acceptJson()->asJson()
                ->timeout((int) config('services.paystack.timeout', 15)));
        } catch (ConnectionException) {
            throw new PaymentGatewayException('Paystack could not be reached.');
        }
    }
}
