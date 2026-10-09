<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplacePaymentWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplacePaymentWebhookController extends Controller
{
    /**
     * Paystack webhook
     *
     * Server-to-server, NOT for the frontend. No session; authenticated only by the `x-paystack-signature` header (HMAC-SHA512 of the raw body with the
     * secret key) - a missing or wrong signature is `401` and does nothing. Only `charge.success` is acted on and even then the body is not believed: the
     * payment is re-read from Paystack and activated only when reference, amount, currency and status all match. Deliveries are stored first and de-duplicated,
     * so a redelivery answers `200` and never activates twice. A temporary failure answers `500` so Paystack redelivers, and the event is also retried by the
     * scheduler.
     */
    public function __invoke(Request $request, MarketplacePaymentWebhook $webhook): JsonResponse
    {
        $result = $webhook->receive($request->getContent(), $request->header('x-paystack-signature'));   // 401 on a bad signature

        return response()->json(['received' => true, 'result' => $result]);
    }
}
