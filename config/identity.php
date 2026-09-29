<?php

/*
| Identity / authentication tuning. Centralised so limits are never magic numbers in controllers.
| Rate limits are [maxAttempts, decayMinutes].
*/
return [
    'otp' => [
        'length' => 6,
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),
    ],

    // Lifetime of the single-use authorization issued after a password-reset OTP is verified.
    'reset_authorization_ttl_minutes' => (int) env('PASSWORD_RESET_AUTHORIZATION_TTL_MINUTES', 15),

    'rate_limits' => [
        'register' => ['ip' => [10, 60]],
        'login' => ['email_ip' => [5, 1], 'ip' => [30, 1]],
        'otp_verify' => ['user' => [10, 10]],
        'otp_resend' => ['user' => [5, 60]],
        'forgot_password' => ['email_ip' => [5, 60], 'ip' => [30, 60]],
        'reset_verify' => ['email_ip' => [10, 10], 'ip' => [30, 10]],
        'reset_password' => ['ip' => [10, 10]],
        'google' => ['ip' => [20, 1]],
    ],
];
