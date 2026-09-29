<?php

namespace App\Http\Resources;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Models\Plan;
use App\Services\Subscription\EntitlementService;
use App\Support\Entitlements\LimitValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan for pricing/comparison screens. `features` and `limits` always list EVERY known key so the UI
 * can render a comparison table without knowing plan names. Prices are integer minor units (kobo);
 * `formatted` is for display only.
 *
 * @property Plan $resource
 */
class PlanResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string,
     *     slug: string,
     *     name: string,
     *     description: string|null,
     *     currency: string,
     *     is_default: bool,
     *     prices: array<int, array{interval: 'monthly'|'annual', amount_minor: int, currency: string, formatted: string}>,
     *     features: array<int, array{key: string, label: string, enabled: bool}>,
     *     limits: array<int, array{key: string, label: string, limit: int|null, unlimited: bool}>
     * }
     */
    public function toArray(Request $request): array
    {
        $plan = $this->resource;
        [$features, $limits] = app(EntitlementService::class)->forPlan($plan);

        return [
            'id' => $plan->id,
            'slug' => $plan->slug,
            'name' => $plan->name,
            'description' => $plan->description,
            'currency' => $plan->currency,
            'is_default' => $plan->is_default,
            'prices' => $plan->prices->where('is_active', true)->sortBy('amount_minor')->values()->map(fn ($price) => [
                'interval' => $price->interval->value,
                'amount_minor' => $price->amount_minor,
                'currency' => $price->currency,
                'formatted' => self::money($price->amount_minor, $price->currency),
            ])->all(),
            'features' => array_map(fn (Feature $f) => [
                'key' => $f->value,
                'label' => $f->label(),
                'enabled' => $features[$f->value] ?? false,
            ], Feature::cases()),
            'limits' => array_map(function (Limit $l) use ($limits) {
                $value = $limits[$l->value] ?? LimitValue::none();

                return ['key' => $l->value, 'label' => $l->label(), 'limit' => $value->value, 'unlimited' => $value->unlimited];
            }, Limit::cases()),
        ];
    }

    /** Display helper only; the authoritative amount is always `amount_minor`. */
    public static function money(int $minor, string $currency): string
    {
        $amount = number_format($minor / 100, 2);

        return $currency === 'NGN' ? "₦{$amount}" : "{$currency} {$amount}";
    }
}
