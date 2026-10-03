<?php

namespace App\Http\Resources\Platform;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Models\Plan;
use App\Services\Subscription\EntitlementService;
use App\Support\Entitlements\LimitValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The administrator's view of a plan: inactive/private plans included, every price (active or not), every feature and limit.
 * `subscribers_count` is the farms currently on the plan (active or past due). Money is integer kobo.
 *
 * @property Plan $resource
 */
class PlatformPlanResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string, slug: string, name: string, description: string|null, currency: string, is_active: bool, is_public: bool, is_default: bool, sort_order: int,
     *     subscribers_count: int|null,
     *     prices: array<int, array{interval: string, amount_minor: int, currency: string, is_active: bool}>,
     *     features: array<int, array{key: string, label: string, enabled: bool}>,
     *     limits: array<int, array{key: string, label: string, limit: int|null, unlimited: bool}>,
     *     created_at: string|null, updated_at: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        $plan = $this->resource;
        [$features, $limits] = app(EntitlementService::class)->forPlan($plan);

        return [
            'id' => $plan->id, 'slug' => $plan->slug, 'name' => $plan->name, 'description' => $plan->description, 'currency' => $plan->currency,
            'is_active' => $plan->is_active, 'is_public' => $plan->is_public, 'is_default' => $plan->is_default, 'sort_order' => $plan->sort_order,
            'subscribers_count' => $plan->getAttribute('subscribers_count'),
            'prices' => $plan->prices->sortBy('amount_minor')->values()->map(fn ($p) => ['interval' => $p->interval->value, 'amount_minor' => $p->amount_minor, 'currency' => $p->currency, 'is_active' => $p->is_active])->all(),
            'features' => array_map(fn (Feature $f) => ['key' => $f->value, 'label' => $f->label(), 'enabled' => $features[$f->value] ?? false], Feature::cases()),
            'limits' => array_map(function (Limit $l) use ($limits) {
                $v = $limits[$l->value] ?? LimitValue::none();

                return ['key' => $l->value, 'label' => $l->label(), 'limit' => $v->value, 'unlimited' => $v->unlimited];
            }, Limit::cases()),
            'created_at' => $plan->created_at?->toIso8601String(), 'updated_at' => $plan->updated_at?->toIso8601String(),
        ];
    }
}
