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
];
