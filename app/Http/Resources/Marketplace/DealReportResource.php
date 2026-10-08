<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceDealReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A filed report. The reporter always sees their own; the OTHER party never sees it. Admins also see who filed it. A report is a record of a complaint
 * only: it triggers no refund, penalty or dispute settlement (moderation arrives in a later phase).
 *
 * @property MarketplaceDealReport $resource
 */
class DealReportResource extends JsonResource
{
    public static $wrap = null;

    private string $audience = 'reporter';

    public function audience(string $audience): static
    {
        $this->audience = $audience;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $out = [
            'id' => $r->id, 'reference' => $r->reference, 'deal_id' => $r->deal_id, 'target' => $r->target, 'reason' => $r->reason, 'description' => $r->description,
            'status' => $r->status, 'deal_status_at_report' => $r->deal_status_at_report, 'created_at' => $r->created_at->toIso8601String(),
            'outcome_reason' => $r->outcome_reason, 'closed_at' => $r->closed_at,
        ];
        if ($this->audience === 'admin') {
            $out['reporter'] = ['id' => $r->reporter_id, 'side' => $r->reporter_side, 'name' => $r->relationLoaded('reporter') ? $r->reporter?->name : null];
            if ($r->relationLoaded('deal')) {
                $out['deal'] = ['id' => $r->deal->id, 'reference' => $r->deal->reference, 'status' => $r->deal->status->value];
            }
        }

        return $out;
    }
}
