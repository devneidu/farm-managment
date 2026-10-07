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
     *     has_active_farm: bool,
     *     next_action: 'verify_email'|'marketplace'|'complete_farm_setup'|'no_active_farm'|'none',
     *     user: array{id: string, email: string, name: string|null, email_verified_at: string|null, has_password: bool, platform_role: 'admin'|'support'|null, providers: string[], locale: string|null},
     *     farm: array{id: string, name: string, country_code: string, currency: string, timezone: string, locale: string, role: string}|null,
     *     farms: list<array{id: string, name: string, role: string}>,
     *     marketplace: array{shop_count: int}
     * }
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        // Distinct states: email verification, onboarding completion, active membership, current farm.
        $farm = $user->currentFarm();
        $shops = $user->marketplaceShopCount();

        return [
            'authenticated' => true,
            'email_verified' => $user->hasVerifiedEmail(),
            'onboarded' => $user->isOnboarded(),
            'has_active_farm' => $farm !== null,
            'next_action' => match (true) {
                ! $user->hasVerifiedEmail() => 'verify_email',
                // A seller who runs Marketplace shops but has no active farm: send them to the shop dashboard, not to farm setup.
                $farm === null && $shops > 0 => 'marketplace',
                ! $user->isOnboarded() => 'complete_farm_setup',
                $farm === null => 'no_active_farm',
                default => 'none',
            },
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'has_password' => $user->password !== null,
                'platform_role' => $user->platformRole()?->value,
                'providers' => $user->socialAccounts()->pluck('provider')->all(),
                'locale' => $user->locale,
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
            /**
             * Every farm the user ACTIVELY belongs to (oldest membership first, the same order the default farm is chosen by). Send a farm's `id` as the
             * `X-Farm-Id` header to work in it; permissions are never listed here - they come from GET /farm → membership.permissions for the selected farm.
             *
             * @var list<array{id: string, name: string, role: string}>
             */
            'farms' => $user->memberships()->active()->with('farm')->orderBy('created_at')->orderBy('id')->get()
                ->filter(fn ($m) => $m->farm !== null)
                ->map(fn ($m) => ['id' => $m->farm->id, 'name' => $m->farm->name, 'role' => $m->role->value])->values()->all(),
            /**
             * Marketplace participation, independent of farms. `shop_count` = shops the user is a member of (any status); drive the seller area from
             * GET /marketplace/my/shops. A user can have farms, shops, both or neither.
             *
             * @var array{shop_count: int}
             */
            'marketplace' => ['shop_count' => $shops],
        ];
    }
}
