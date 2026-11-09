<?php

namespace App\Services\Finance;

use App\Enums\Permission;
use App\Models\FinanceCategory;
use App\Models\Payment;
use App\Services\Farm\FarmSettings;
use App\Support\Access\FarmContext;

/**
 * What the single dynamic Income / Expense form offers. One ordered list of choices: the farm-sale (or store-purchase) entry first,
 * then each manual-entry category. Every choice says which endpoint and body shape to use. A choice the caller may not use is not listed.
 * Reuses the category rules (manual_entry), the permissions and the farm settings; nothing here is stored.
 */
class FormOptions
{
    private const DESCRIPTIONS = [
        'other_income' => 'Money received that is not a sale, such as a refund or a one-off payment.',
        'services' => 'Money earned for work or services done for someone.',
        'grants_loans_support' => 'A grant, loan or support payment received.',
        'feed' => 'Feed bought and paid for.',
        'medicine_veterinary' => 'Medicine or vet services paid for.',
        'labour' => 'Wages and casual labour paid.',
        'transport' => 'Transport and delivery costs.',
        'other_expense' => 'Any other cost of the farm.',
    ];

    /** @return array{direction: string, options: list<array<string, mixed>>, payment_methods: list<string>, settings: array{use_stock_for_usage: bool}, can_add_stock: bool} */
    public function for(FarmContext $ctx, string $direction): array
    {
        $options = [];
        $income = $direction === 'income';
        $special = $income ? Permission::SaleCreate : Permission::PurchaseCreate;
        if ($ctx->can($special)) {
            $options[] = $income ? [
                'code' => 'farm_sale', 'label' => 'Sold something from your farm', 'kind' => 'sale',
                'description' => 'Animals, eggs, milk, crops or items from your store. Records what left the farm, who bought it and what was paid.',
                'endpoint' => ['method' => 'POST', 'path' => '/sales'], 'finance_category_id' => null, 'permission' => $special->value,
            ] : [
                'code' => 'store_purchase', 'label' => 'Bought items for the store', 'kind' => 'purchase',
                'description' => 'Feed, medicine, seed or supplies bought. Adds them to the store and records the expense in one step.',
                'endpoint' => ['method' => 'POST', 'path' => '/purchases'], 'finance_category_id' => null, 'permission' => $special->value,
            ];
        }
        if ($ctx->can(Permission::FinanceCreate)) {
            foreach (FinanceCategory::visibleTo($ctx->farm)->where('is_active', true)->where('direction', $direction)->orderBy('sort_order')->orderBy('name')->get() as $category) {
                if (! $category->isManualEntry()) {
                    continue;
                }
                $options[] = [
                    'code' => $category->code ?? 'category_'.$category->id, 'label' => $category->name, 'kind' => 'manual',
                    'description' => self::DESCRIPTIONS[$category->code] ?? ($income ? 'Other money received by the farm.' : 'A cost paid by the farm.'),
                    'endpoint' => ['method' => 'POST', 'path' => $income ? '/income' : '/expenses'],
                    'finance_category_id' => $category->id, 'permission' => Permission::FinanceCreate->value,
                ];
            }
        }

        return [
            'direction' => $direction,
            'options' => $options,
            'payment_methods' => Payment::METHODS,
            'settings' => ['use_stock_for_usage' => FarmSettings::usesStockForUsage($ctx->farm)],
            'can_add_stock' => $ctx->can(Permission::InventoryAdd),
        ];
    }
}
