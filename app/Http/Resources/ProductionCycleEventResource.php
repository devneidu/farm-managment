<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionCycleEventResource extends JsonResource
{
    /** @return array{id: string, action: string, actor_id: string, changes: array<string, array{old: mixed, new: mixed}>, reason: string|null, recorded_at: string, created_at: string} */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'action' => $this->action, 'actor_id' => $this->actor_id,
            /** @var array<string, array{old: mixed, new: mixed}> */
            'changes' => $this->changes, 'reason' => $this->reason, 'recorded_at' => $this->recorded_at->toISOString(), 'created_at' => $this->created_at->toISOString()];
    }
}
