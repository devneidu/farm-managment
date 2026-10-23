<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'name' => $this->name,
            /** @var 'income'|'expense' */
            'direction' => $this->direction,
            /** True for platform categories shared by every farm. */
            'is_system' => $this->farm_id === null,
            /** False for livestock/egg/milk/crop sales: record those through Sales so stock stays correct. Hide them on the manual form. */
            'manual_entry' => $this->isManualEntry(),
        ];
    }
}
