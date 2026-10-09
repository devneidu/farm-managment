<?php

return [
    // Images are stored on a PRIVATE disk and served through the API (never a guessable public path). Set MARKETPLACE_DISK=s3 to move storage.
    'images' => [
        'disk' => env('MARKETPLACE_DISK', 'marketplace'),
        'max_per_listing' => 6,
        'max_kilobytes' => 5120,
        'max_input_dimension' => 4096,   // longest accepted side, pixels
        'store_dimension' => 2048,       // stored images are downscaled to this longest side
        'max_pixels' => 16_000_000,      // decompression-bomb guard, checked before the file is decoded
        'mime_types' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
        'cache_seconds' => 3600,
    ],

    // Farmvest's own service payments (seller plans and promotions). Buyer-seller product payments never pass through the platform.
    'payments' => [
        // Where Paystack sends the browser afterwards (the frontend). The page only calls the verify endpoint: a redirect never activates anything.
        'callback_url' => env('MARKETPLACE_PAYMENT_CALLBACK_URL'),
        'currency' => 'NGN',
        'pending_reuse_minutes' => 30,       // a repeated checkout inside this window returns the same pending payment
        'abandon_after_hours' => 24,         // the reconcile command marks an unpaid pending payment abandoned after this long
        'reconcile_lookback_days' => 3,
    ],
];
