<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceContentReport;
use App\Models\MarketplaceDealReport;
use App\Services\Marketplace\MarketplaceReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Confidential complaint: reporter sees the outcome; only platform roles see administrative history.
 *
 * @mixin MarketplaceContentReport
 *
 * @property MarketplaceContentReport|MarketplaceDealReport $resource
 */
class MarketplaceReportResource extends JsonResource
{
    public static $wrap = null;

    private bool $admin = false;

    public function admin(): static
    {
        $this->admin = true;

        return $this;
    }

    /** @return array{id: string, reference: string, type: string, reason: string, description: string|null, status: string, target: array{type: string, id: string}, outcome_reason: string|null, closed_at: string|null, created_at: string, reporter_id?: string, handled_by?: string|null, history?: array<array{id: string, actor_id: string|null, from_status: string|null, to_status: string, reason: string|null, enforcement_type: string|null, enforcement_id: string|null, enforcement_action: string|null, created_at: string}>} */
    public function toArray(Request $request): array
    {
        return app(MarketplaceReportService::class)->serialize($this->resource instanceof MarketplaceDealReport ? 'deal' : 'content', $this->resource, $this->admin);
    }
}
