<?php

namespace App\Services\Inventory;

use App\Enums\InventoryCategory;
use App\Enums\StockInReason;
use App\Enums\StockOutReason;
use App\Models\InventoryItem;

/**
 * The single place that says which stock reasons exist, what they are called, and which authoritative endpoint records
 * the real-world event. The frontend reads this (GET /master/inventory-options) instead of hardcoding business rules, and
 * the manual stock-in / stock-out endpoints use the same definitions to refuse events another workflow owns.
 *
 * Reasons stay domain-level and stable. Channel detail (market, online, restaurant...) belongs to the sale, not to a reason.
 */
class StockReasonCatalogue
{
    public const KIND_FEED = 'feed';

    public const KIND_EGGS = 'eggs';

    public const KIND_MILK = 'milk';

    public const KIND_GENERAL = 'general';

    /** Outputs resolved automatically per farm: item kind => system_key. */
    public const OUTPUT_KEYS = [self::KIND_EGGS => 'output:eggs', self::KIND_MILK => 'output:milk'];

    public static function kindOf(InventoryItem $item): string
    {
        $kind = array_search($item->system_key, self::OUTPUT_KEYS, true);
        if ($kind !== false) {
            return $kind;
        }

        return $item->category === InventoryCategory::Feed ? self::KIND_FEED : self::KIND_GENERAL;
    }

    /** Human label of a stored movement reason (including system reasons such as harvest); null for reason-less movements (counts, transfers, reversals). */
    public static function label(?string $reason, string $direction): ?string
    {
        if ($reason === null) {
            return null;
        }
        if ($reason === 'harvest') {
            return 'Harvest';
        }
        $enum = $direction === 'in' ? StockInReason::tryFrom($reason) : StockOutReason::tryFrom($reason);

        return $enum?->label() ?? ucfirst(str_replace('_', ' ', $reason));
    }

    /** Where a system-only OUT reason is recorded instead (null = it is accepted on the manual endpoint). */
    public static function outRoute(StockOutReason $reason): ?array
    {
        return match ($reason) {
            StockOutReason::Sale => self::sale(),
            StockOutReason::ProductionUse => self::feedUse(),
            StockOutReason::Incubation => self::incubation(),
            default => null,
        };
    }

    public static function inRoute(StockInReason $reason): ?array
    {
        return $reason === StockInReason::Returned ? self::incubation() : null;
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return [
            'in' => array_map(fn (StockInReason $r) => ['code' => $r->value, 'label' => $r->label(), 'manual' => ! $r->isSystemOnly(), 'legacy' => false, 'route' => self::inRoute($r)], StockInReason::cases()),
            'out' => array_map(fn (StockOutReason $r) => ['code' => $r->value, 'label' => $r->label(), 'manual' => ! $r->isSystemOnly(), 'legacy' => $r->isLegacy(), 'route' => self::outRoute($r)], StockOutReason::cases()),
            'by_item_kind' => [
                self::KIND_FEED => ['in' => $this->feedIn(), 'out' => $this->feedOut()],
                self::KIND_EGGS => ['in' => $this->outputIn('egg_collection'), 'out' => $this->eggsOut()],
                self::KIND_MILK => ['in' => $this->outputIn('milk'), 'out' => $this->milkOut()],
                self::KIND_GENERAL => ['in' => $this->generalIn(), 'out' => $this->generalOut()],
            ],
        ];
    }

    // ----------------------------------------------------------- per kind

    private function feedIn(): array
    {
        return [
            $this->in(StockInReason::Purchase, 'Purchased', extra: ['also' => [['kind' => 'purchase', 'method' => 'POST', 'path' => '/purchases', 'note' => 'Books the expense together with the stock.']]]),
            $this->in(StockInReason::Donation, 'Donation / Sharing'),
            $this->in(StockInReason::Production, 'Produced on farm'),
            $this->in(StockInReason::Aid, 'Aid / Support'),
            $this->in(StockInReason::Received, 'Received'),
            $this->in(StockInReason::OpeningBalance, 'Opening balance'),
            $this->in(StockInReason::Other, 'Other'),
            ...$this->movementActions('in'),
        ];
    }

    private function feedOut(): array
    {
        return [
            $this->out(StockOutReason::ProductionUse, 'Used for livestock', record: true),
            $this->out(StockOutReason::Sale, 'Sold'),
            $this->out(StockOutReason::Donation, 'Gift / Donation'),
            $this->out(StockOutReason::Spoiled, 'Spoiled / Contaminated'),
            $this->out(StockOutReason::Lost, 'Lost / Stolen'),
            $this->out(StockOutReason::Disposal, 'Disposal / Compost'),
            $this->out(StockOutReason::Other, 'Other'),
            ...$this->movementActions('out'),
        ];
    }

    /** Eggs and milk produced on the farm come from their operational record; every other source is stock only. */
    private function outputIn(string $recordType): array
    {
        return [
            ['code' => StockInReason::Production->value, 'label' => 'Produced on farm', 'manual' => false, 'creates_operational_record' => true,
                'route' => ['kind' => 'record', 'method' => 'POST', 'path' => '/records', 'record_type' => $recordType]],
            $this->in(StockInReason::Purchase, 'Purchased', extra: ['also' => [['kind' => 'purchase', 'method' => 'POST', 'path' => '/purchases', 'note' => 'Books the expense together with the stock.']]]),
            $this->in(StockInReason::Donation, 'Gift / Donation'),
            $this->in(StockInReason::Received, 'Received'),
            $this->in(StockInReason::OpeningBalance, 'Opening balance'),
            $this->in(StockInReason::Other, 'Other'),
            ...$this->movementActions('in'),
        ];
    }

    private function eggsOut(): array
    {
        return [
            $this->out(StockOutReason::Sale, 'Sold'),
            $this->out(StockOutReason::Incubation, 'Put into incubation'),
            $this->out(StockOutReason::Donation, 'Gift / Donation'),
            $this->out(StockOutReason::InternalUse, 'Personal / Internal use'),
            $this->out(StockOutReason::Damaged, 'Damaged / Broken'),
            $this->out(StockOutReason::Spoiled, 'Spoiled'),
            $this->out(StockOutReason::Lost, 'Lost / Stolen'),
            $this->out(StockOutReason::Other, 'Other'),
            ...$this->movementActions('out'),
        ];
    }

    private function milkOut(): array
    {
        return [
            $this->out(StockOutReason::Sale, 'Sold'),
            $this->out(StockOutReason::Donation, 'Gift / Donation'),
            $this->out(StockOutReason::InternalUse, 'Personal / Internal use'),
            $this->out(StockOutReason::Spoiled, 'Spoiled / Contaminated'),
            $this->out(StockOutReason::Lost, 'Lost'),
            $this->out(StockOutReason::Other, 'Other'),
            ...$this->movementActions('out'),
        ];
    }

    private function generalIn(): array
    {
        return [
            $this->in(StockInReason::Purchase, 'Purchased'),
            $this->in(StockInReason::Donation, 'Gift / Donation'),
            $this->in(StockInReason::Aid, 'Aid / Support'),
            $this->in(StockInReason::Received, 'Received'),
            $this->in(StockInReason::OpeningBalance, 'Opening balance'),
            $this->in(StockInReason::Other, 'Other'),
            ...$this->movementActions('in'),
        ];
    }

    private function generalOut(): array
    {
        return [
            $this->out(StockOutReason::Use, 'Used'),
            $this->out(StockOutReason::Sale, 'Sold'),
            $this->out(StockOutReason::Donation, 'Gift / Donation'),
            $this->out(StockOutReason::Damaged, 'Damaged'),
            $this->out(StockOutReason::Expired, 'Expired'),
            $this->out(StockOutReason::Spoiled, 'Spoiled'),
            $this->out(StockOutReason::Lost, 'Lost / Stolen'),
            $this->out(StockOutReason::Disposal, 'Disposal'),
            $this->out(StockOutReason::Other, 'Other'),
            ...$this->movementActions('out'),
        ];
    }

    // -------------------------------------------------------------- entries

    private function in(StockInReason $reason, string $label, array $extra = []): array
    {
        return ['code' => $reason->value, 'label' => $label, 'manual' => ! $reason->isSystemOnly(), 'creates_operational_record' => false,
            'route' => self::inRoute($reason) ?? ['kind' => 'inventory', 'method' => 'POST', 'path' => '/inventory/stock-in']] + $extra;
    }

    private function out(StockOutReason $reason, string $label, bool $record = false): array
    {
        return ['code' => $reason->value, 'label' => $label, 'manual' => ! $reason->isSystemOnly(), 'creates_operational_record' => $record,
            'route' => self::outRoute($reason) ?? ['kind' => 'inventory', 'method' => 'POST', 'path' => '/inventory/stock-out']];
    }

    /** Transfers and counts are movement types, not reasons; they are listed so the frontend has one place to look. */
    private function movementActions(string $direction): array
    {
        return [
            ['code' => $direction === 'in' ? 'transfer_in' : 'transfer_out', 'label' => 'Moved between stores', 'manual' => false, 'creates_operational_record' => false,
                'route' => ['kind' => 'transfer', 'method' => 'POST', 'path' => '/inventory/transfers']],
            ['code' => 'adjustment', 'label' => 'Stock count correction', 'manual' => false, 'creates_operational_record' => false,
                'route' => ['kind' => 'adjustment', 'method' => 'POST', 'path' => '/inventory/adjustments']],
        ];
    }

    private static function sale(): array
    {
        return ['kind' => 'sale', 'method' => 'POST', 'path' => '/sales', 'note' => 'Add a stock line; the sale is the only writer of a sale stock-out.'];
    }

    private static function feedUse(): array
    {
        return ['kind' => 'record', 'method' => 'POST', 'path' => '/records', 'record_type' => 'feed_use', 'note' => 'Choose the cycle and details.inventory; one request writes the feed_use record and the stock-out.'];
    }

    private static function incubation(): array
    {
        return ['kind' => 'breeding_project', 'method' => 'POST', 'path' => '/breeding-projects', 'workflow' => 'incubation', 'field' => 'consume_egg_stock', 'note' => 'Starting an incubation project with consume_egg_stock takes the eggs from stock.'];
    }
}
