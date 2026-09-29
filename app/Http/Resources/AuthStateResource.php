<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The single "where does this user belong?" contract returned by register, login,
 * Google login, email verification, onboarding and GET /auth/me.
 *
 * @property User $resource
 */
class AuthStateResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     authenticated: true,
     *     email_verified: bool,
     *     onboarded: bool,
     *     next_action: 'verify_email'|'complete_farm_setup'|'none',
     *     user: array{id: string, email: string, name: string|null, email_verified_at: string|null, has_password: bool, providers: string[]},
     *     farm: array{id: string, name: string, country_code: string, currency: string, timezone: string, locale: string, role: string}|null
     * }
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $farm = $user->isOnboarded() ? $user->currentFarm() : null;

        return [
            'authenticated' => true,
            'email_verified' => $user->hasVerifiedEmail(),
            'onboarded' => $user->isOnboarded(),
            'next_action' => match (true) {
                ! $user->hasVerifiedEmail() => 'verify_email',
                ! $user->isOnboarded() => 'complete_farm_setup',
                default => 'none',
            },
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'has_password' => $user->password !== null,
                'providers' => $user->socialAccounts()->pluck('provider')->all(),
            ],
            'farm' => $farm ? [
                'id' => $farm->id,
                'name' => $farm->name,
                'country_code' => $farm->country_code,
                'currency' => $farm->currency,
                'timezone' => $farm->timezone,
                'locale' => $farm->locale,
                'role' => $farm->pivot->role,
            ] : null,
        ];
    }
}
