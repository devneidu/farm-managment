<?php

namespace App\Http\Resources;

use App\Services\Inventory\StockLedger;
use App\Support\Access\FarmContext;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ledger = app(StockLedger::class);
        $total = Decimal::trim((string) ($this->stock_total ?? '0'));
        $today = now(app(FarmContext::class)->farm->timezone)->toDateString();

        return [
            'id' => $this->id, 'inventory_item_id' => $this->inventory_item_id, 'code' => $this->code,
            'expires_on' => $this->expires_on?->toDateString(),
            'is_expired' => $this->expires_on !== null && $this->expires_on->toDateString() < $today,
            /** @var array{quantity: string, unit: string, normalized: array{quantity: string, unit: string}} */
            'stock' => ['quantity' => $ledger->display($this->item, $total)['quantity'], 'unit' => $this->item->stockUnit->code, 'normalized' => $ledger->canonical($this->item, $total)],
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
