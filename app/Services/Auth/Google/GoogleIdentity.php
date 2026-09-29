<?php

namespace App\Services\Auth\Google;

final class GoogleIdentity
{
    public function __construct(
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name = null,
    ) {}
}
