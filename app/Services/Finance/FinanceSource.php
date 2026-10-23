<?php

namespace App\Services\Finance;

use App\Models\FinanceTransaction;
use App\Models\Purchase;

/** Human label and navigation ids for the source of a ledger row (what the Transactions page shows and links to). */
final class FinanceSource
{
    /** "PUR-2026-00008" -> "#8". */
    public static function number(string $reference): string
    {
        return '#'.(ltrim(substr($reference, strrpos($reference, '-') + 1), '0') ?: '0');
    }

    public static function purchaseLabel(Purchase $purchase): string
    {
        return 'Purchase '.self::number($purchase->reference);
    }

    /**
     * @return array{type: string|null, id: string|null, label: string}&array<string, string|null>
     */
    public static function describe(FinanceTransaction $row): array
    {
        $row->loadMissing(['sourcePayment.invoice.sale', 'sourcePurchase']);
        $type = $row->source_type;
        if ($type === null) {
            return ['type' => null, 'id' => null, 'label' => 'Entered manually'];
        }
        $base = ['type' => $type, 'id' => $row->source_id];
        if ($type === 'payment') {
            $payment = $row->sourcePayment;
            $sale = $payment?->invoice?->sale;

            return $base + [
                'label' => $sale ? 'Payment for Sale '.self::number($sale->reference) : 'Customer payment',
                'reference' => $payment?->reference, 'invoice_id' => $payment?->invoice_id, 'sale_id' => $sale?->id,
            ];
        }
        if ($type === 'purchase') {
            $purchase = $row->sourcePurchase;

            return $base + ['label' => $purchase ? self::purchaseLabel($purchase) : 'Purchase', 'reference' => $purchase?->reference];
        }

        return $base + ['label' => $type === 'health_record' ? 'Health record' : 'Farm record'];
    }
}
