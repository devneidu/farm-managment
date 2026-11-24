<?php

/*
| Identity / authentication tuning. Centralised so limits are never magic numbers in controllers.
| Rate limits are [maxAttempts, decayMinutes].
*/
return [
    // User-facing product name shown in auth emails (APP_NAME stays the internal service name).
    'brand_name' => env('BRAND_NAME', 'Faramaka'),

    'otp' => [
        'length' => 6,
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),
    ],

    // Lifetime of the single-use authorization issued after a password-reset OTP is verified.
    'reset_authorization_ttl_minutes' => (int) env('PASSWORD_RESET_AUTHORIZATION_TTL_MINUTES', 15),

    // Team invitations (Phase 2). The emailed link is {frontend_url}{accept_path}?token=...
    'invitations' => [
        'ttl_days' => (int) env('INVITATION_TTL_DAYS', 7),
        'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),
        'accept_path' => '/invitations/accept',
        // Frontend page of one task: {frontend_url}{task_path}/{task id} (reminder e-mail, phone calendar events).
        'task_path' => '/'.trim((string) env('FRONTEND_TASK_PATH', 'app/tasks'), '/'),
    ],

    // Native/mobile clients: bearer tokens (personal access tokens, one per device sign-in).
    'mobile' => [
        'token_ttl_days' => (int) env('MOBILE_TOKEN_TTL_DAYS', 90),
    ],

    // Per-IP caps are higher than per-account caps because many mobile users share one carrier-NAT address.
    'rate_limits' => [
        'register' => ['ip' => [(int) env('RATE_REGISTER_IP_PER_HOUR', 30), 60]],
        'login' => ['email_ip' => [5, 1], 'ip' => [(int) env('RATE_LOGIN_IP_PER_MINUTE', 60), 1]],
        'otp_verify' => ['user' => [10, 10]],
        'otp_resend' => ['user' => [5, 60]],
        'forgot_password' => ['email_ip' => [5, 60], 'ip' => [30, 60]],
        'reset_verify' => ['email_ip' => [10, 10], 'ip' => [30, 10]],
        'reset_password' => ['ip' => [10, 10]],
        'google' => ['ip' => [(int) env('RATE_GOOGLE_IP_PER_MINUTE', 60), 1]],
        'account_password' => ['user' => [5, 10]],
        'invitation_accept' => ['user' => [10, 10]],
        'team_invite' => ['user' => [30, 60]],
        'master_data_write' => ['user' => [60, 60]],
        'measurement_write' => ['user' => [60, 60]],
        'location_write' => ['user' => [60, 60]],
        'measurement_preview' => ['user' => [120, 1]],
    ],
];
