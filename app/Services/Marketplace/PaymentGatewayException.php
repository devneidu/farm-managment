<?php

namespace App\Services\Marketplace;

use RuntimeException;

/** The payment provider could not be reached or answered unusably. Always retryable: nothing was decided, so no payment is failed or activated because of it. */
class PaymentGatewayException extends RuntimeException {}
