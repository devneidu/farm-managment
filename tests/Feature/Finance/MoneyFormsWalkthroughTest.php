<?php

namespace Tests\Feature\Finance;

use App\Enums\FarmRole;
use App\Models\FinanceTransaction;
use App\Models\InventoryItem;
use App\Models\OperationType;
use App\Models\Species;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/**
 * The Income and Expense forms end to end, through the API only: form options, sales (animals, store items, typed products),
 * manual income, purchases (existing item and new_item), a manual expense, the duplicate warning, and the money pages that read it all.
 */
class MoneyFormsWalkthroughTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
        $this->signInAs($this->owner);
    }

    // ------------------------------------------------------------------ helpers

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function at(int $hoursAgo = 2): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    private function cycle(int $population): string
    {
        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => 'Layer flock', 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'production_purpose' => 'breeding', 'initial_population' => $population, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function population(string $cycle): int
    {
        return $this->getJson('/api/v1/production-cycles/'.$cycle)->assertOk()->json('data.livestock.current_population');
    }

    private function sale(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/sales', array_replace(['recorded_at' => $this->at(), 'idempotency_key' => $this->key(), 'items' => $items], $extra));
    }

    private function item(string $name, string $category, string $unit): string
    {
        return $this->postJson('/api/v1/inventory/items', ['name' => $name, 'category' => $category, 'stock_unit' => $unit])->assertCreated()->json('data.id');
    }

    private function stockOf(string $item): string
    {
        return $this->getJson('/api/v1/inventory/items/'.$item)->assertOk()->json('data.stock.quantity');
    }

    private function purchase(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/purchases', array_replace(['recorded_at' => $this->at(), 'idempotency_key' => $this->key(), 'items' => $items], $extra));
    }

    private function option(string $direction, string $code): array
    {
        $found = collect($this->getJson('/api/v1/finance/form-options?direction='.$direction)->assertOk()->json('data.options'))->firstWhere('code', $code);
        $this->assertNotNull($found, "Option {$code} missing");

        return $found;
    }

    private function codes(string $direction): array
    {
        return array_column($this->getJson('/api/v1/finance/form-options?direction='.$direction)->assertOk()->json('data.options'), 'code');
    }

    private function manual(string $direction, string $code, string $amount, array $extra = [])
    {
        $option = $this->option($direction, $code);

        return $this->postJson($option['endpoint']['path'] === '/income' ? '/api/v1/income' : '/api/v1/expenses', array_replace(
            ['finance_category_id' => $option['finance_category_id'], 'amount' => $amount, 'occurred_on' => '2026-10-14', 'idempotency_key' => $this->key()], $extra));
    }

    private function totals(): array
    {
        return $this->getJson('/api/v1/finance/income')->assertOk()->json('meta.totals');
    }

    // ------------------------------------------------------------------ the walkthrough

    public function test_the_income_and_expense_forms_end_to_end(): void
    {
        // ---- form options drive the forms
        $income = $this->getJson('/api/v1/finance/form-options?direction=income')->assertOk()->json('data');
        $this->assertSame('farm_sale', $income['options'][0]['code']);
        $this->assertSame(['method' => 'POST', 'path' => '/sales'], $income['options'][0]['endpoint']);
        foreach (['other_income', 'services', 'grants_loans_support'] as $code) {
            $this->assertContains($code, array_column($income['options'], 'code'));
        }
        $this->assertSame(['cash', 'bank_transfer', 'pos', 'mobile_money', 'cheque', 'other'], $income['payment_methods']);
        $this->assertFalse($income['settings']['use_stock_for_usage']);
        $this->assertTrue($income['can_add_stock']);
        foreach (array_slice($income['options'], 1) as $manual) { // never a product-sales category on the manual form
            $this->assertSame('manual', $manual['kind']);
            $this->assertSame('/income', $manual['endpoint']['path']);
            $this->assertNotNull($manual['finance_category_id']);
            $this->assertNotContains($manual['code'], ['livestock_sales', 'egg_sales', 'milk_sales', 'crop_sales']);
        }
        $expense = $this->getJson('/api/v1/finance/form-options?direction=expense')->assertOk()->json('data');
        $this->assertSame('store_purchase', $expense['options'][0]['code']);
        $this->assertSame(['method' => 'POST', 'path' => '/purchases'], $expense['options'][0]['endpoint']);
        $this->assertSame('/expenses', $expense['options'][1]['endpoint']['path']);

        // ---- 1. farm sale of animals, paid in full
        $flock = $this->cycle(50);
        $sale1 = $this->sale([['kind' => 'livestock', 'production_cycle_id' => $flock, 'head_count' => 10, 'amount' => '80000.00']],
            ['payment' => ['amount' => '80000.00', 'method' => 'cash', 'received_on' => '2026-10-14']])->assertCreated()->json('data');
        $this->assertSame('paid', $sale1['money_status']);
        $this->assertSame('0.00', $sale1['balance']);
        $this->assertSame(40, $this->population($flock));
        $this->assertSame(['currency' => 'NGN', 'from' => null, 'to' => null, 'money_received' => '80000.00', 'still_owed' => '0.00'], $this->totals());

        // ---- 2. store-item sale, part paid, then the rest through POST /sales/{id}/payments
        $tomatoes = $this->item('Tomatoes', 'produce', 'piece');
        $this->postJson('/api/v1/inventory/stock-in', ['inventory_item_id' => $tomatoes, 'reason' => 'opening_balance', 'components' => [['quantity' => '100', 'unit' => 'piece']],
            'recorded_at' => $this->at(20), 'idempotency_key' => $this->key()])->assertCreated();
        $this->assertSame('100', $this->stockOf($tomatoes));
        $sale2 = $this->sale([['kind' => 'stock', 'inventory_item_id' => $tomatoes, 'components' => [['quantity' => '30', 'unit' => 'piece']], 'amount' => '9000.00']],
            ['payment' => ['amount' => '4000.00', 'method' => 'mobile_money', 'received_on' => '2026-10-14']])->assertCreated()->json('data');
        $this->assertSame(['part_paid', '4000.00', '5000.00'], [$sale2['money_status'], $sale2['amount_received'], $sale2['balance']]);
        $this->assertSame('70', $this->stockOf($tomatoes));
        $totals = $this->totals();
        $this->assertSame(['84000.00', '5000.00'], [$totals['money_received'], $totals['still_owed']]);
        $this->postJson('/api/v1/sales/'.$sale2['id'].'/payments', ['amount' => '5000.00', 'method' => 'bank_transfer', 'received_on' => '2026-10-14', 'idempotency_key' => $this->key()])
            ->assertCreated()->assertJsonPath('data.money_status', 'paid')->assertJsonPath('data.balance', '0.00');
        $this->assertSame('70', $this->stockOf($tomatoes)); // payment never touches stock
        $totals = $this->totals();
        $this->assertSame(['89000.00', '0.00'], [$totals['money_received'], $totals['still_owed']]);

        // ---- 3. typed product sale: allowed with the switch OFF, refused (422) with it ON
        $typed = $this->sale([['kind' => 'other', 'product_type' => 'eggs', 'description' => 'Eggs', 'quantity' => '4', 'unit_price' => '500.00']],
            ['payment' => ['amount' => '2000.00', 'method' => 'cash', 'received_on' => '2026-10-14']])->assertCreated()->json('data');
        $this->assertSame('paid', $typed['money_status']);
        $this->assertSame('egg_sales', $typed['finance_category']['code']);
        $this->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => true]])->assertOk();
        $this->assertTrue($this->getJson('/api/v1/finance/form-options?direction=income')->json('data.settings.use_stock_for_usage'));
        $this->sale([['kind' => 'other', 'product_type' => 'eggs', 'description' => 'Eggs', 'amount' => '1000.00']])->assertStatus(422)
            ->assertJsonPath('errors', ['items.0.product_type' => ['Your farm sells from the store. Pick the item from your store.']]);
        $this->patchJson('/api/v1/farm', ['settings' => ['use_stock_for_usage' => false]])->assertOk();

        // ---- 4. each manual income category
        $this->manual('income', 'services', '3000.00')->assertCreated()->assertJsonPath('data.direction', 'income');
        $this->manual('income', 'grants_loans_support', '5000.00', ['payment_method' => 'bank_transfer'])->assertCreated();
        $this->manual('income', 'other_income', '1000.00')->assertCreated();
        $totals = $this->totals();
        $this->assertSame(['100000.00', '0.00'], [$totals['money_received'], $totals['still_owed']]);

        // ---- 5. store purchase: an existing item, then new_item (created), then new_item again (reused)
        $maize = $this->item('Maize', 'feed', 'kg');
        $first = $this->purchase([['kind' => 'stock', 'inventory_item_id' => $maize, 'components' => [['quantity' => '50', 'unit' => 'kg']], 'amount' => '20000.00']])->assertCreated()->json('data');
        $this->assertSame('50', $this->stockOf($maize));
        $this->assertSame('feed', $first['finance_category']['code']);
        $this->assertArrayNotHasKey('item_reused', $first['items'][0]);
        $this->assertNotNull($first['finance_transaction_id']);

        $newKey = $this->key();
        $newLine = [['kind' => 'stock', 'new_item' => ['name' => 'Layer Mash', 'category' => 'feed', 'unit' => 'kg'], 'components' => [['quantity' => '25', 'unit' => 'kg']], 'amount' => '15000.00']];
        $at = $this->at();
        $created = $this->purchase($newLine, ['idempotency_key' => $newKey, 'recorded_at' => $at])->assertCreated()->json('data');
        $this->assertFalse($created['items'][0]['item_reused']);
        $mash = $created['items'][0]['inventory_item_id'];
        $this->assertSame('25', $this->stockOf($mash));
        $this->assertSame(1, InventoryItem::where('farm_id', $this->farm->id)->where('normalized_name', InventoryItem::normalizeName('Layer Mash'))->count());
        $this->assertNotNull($created['finance_transaction_id']);

        // idempotent: the same request again changes nothing and returns the same purchase
        $replay = $this->purchase($newLine, ['idempotency_key' => $newKey, 'recorded_at' => $at])->assertSuccessful()->json('data');
        $this->assertSame($created['id'], $replay['id']);
        $this->assertFalse($replay['items'][0]['item_reused']);
        $this->assertSame('25', $this->stockOf($mash));

        $reused = $this->purchase([['kind' => 'stock', 'new_item' => ['name' => ' layer  mash ', 'category' => 'feed', 'unit' => 'kg'], 'components' => [['quantity' => '25', 'unit' => 'kg']], 'amount' => '10000.00']])
            ->assertCreated()->json('data');
        $this->assertTrue($reused['items'][0]['item_reused']);
        $this->assertSame($mash, $reused['items'][0]['inventory_item_id']);
        $this->assertSame('50', $this->stockOf($mash));
        $this->assertSame(1, InventoryItem::where('farm_id', $this->farm->id)->where('normalized_name', InventoryItem::normalizeName('Layer Mash'))->count());
        $this->getJson('/api/v1/purchases/'.$created['id'])->assertOk()->assertJsonPath('data.items.0.item_reused', false);

        // bad new_item is a 422 under the line, and nothing is booked
        $before = FinanceTransaction::count();
        $this->purchase([['kind' => 'stock', 'new_item' => ['name' => 'Odd', 'category' => 'feed'], 'components' => [['quantity' => '1', 'unit' => 'kg']], 'amount' => '100']])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.new_item.unit');
        $this->purchase([['kind' => 'stock', 'inventory_item_id' => $maize, 'new_item' => ['name' => 'Odd', 'category' => 'feed', 'unit' => 'kg'], 'components' => [['quantity' => '1', 'unit' => 'kg']], 'amount' => '100']])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.new_item');
        $this->assertSame($before, FinanceTransaction::count());
        $this->assertNull(InventoryItem::where('farm_id', $this->farm->id)->where('name', 'Odd')->first());

        // ---- 6. a manual expense, and the duplicate-purchase warning
        $this->manual('expense', 'other_expense', '1500.00')->assertCreated()->assertJsonPath('data.direction', 'expense');
        $supplier = $this->postJson('/api/v1/contacts', ['name' => 'Fuel Depot', 'kind' => 'business', 'roles' => ['supplier']])->assertCreated()->json('data.id');
        $fuel = $this->purchase([['kind' => 'non_stock', 'description' => 'Fuel', 'amount' => '900.00']], ['contact_id' => $supplier])->assertCreated()->json('data');
        $this->manual('expense', 'other_expense', '900.00', ['contact_id' => $supplier])->assertStatus(409)
            ->assertJsonPath('code', 'possible_duplicate')->assertJsonPath('details.purchase_id', $fuel['id']);
        $this->manual('expense', 'other_expense', '900.00', ['contact_id' => $supplier, 'confirm_duplicate' => true])->assertCreated();

        // ---- the money pages
        $incomeList = $this->getJson('/api/v1/finance/income')->assertOk();
        $rows = collect($incomeList->json('data'));
        $this->assertSame(6, $incomeList->json('meta.total'));
        $this->assertSame(3, $rows->where('kind', 'sale')->count());
        $this->assertSame(3, $rows->where('kind', 'other_income')->count());
        $this->assertSame(['paid'], $rows->where('kind', 'sale')->pluck('money_status')->unique()->values()->all());
        $this->assertSame(['received'], $rows->where('kind', 'other_income')->pluck('money_status')->unique()->values()->all());
        $this->assertSame('100000.00', $incomeList->json('meta.totals.money_received'));
        $this->assertSame('0.00', $incomeList->json('meta.totals.still_owed'));

        // 7 income entries (4 sale payments, 3 manual) and 6 expense entries (4 purchases, 2 manual)
        $all = $this->getJson('/api/v1/finance/transactions?entry_type=entry&per_page=100')->assertOk();
        $this->assertSame(13, $all->json('meta.total'));
        $this->assertSame(7, $this->getJson('/api/v1/finance/transactions?entry_type=entry&direction=income')->json('meta.total'));
        $this->assertSame(6, $this->getJson('/api/v1/finance/transactions?entry_type=entry&direction=expense')->json('meta.total'));

        // 20000 + 15000 + 10000 + 900 purchases, + 1500 + 900 manual
        $summary = $this->getJson('/api/v1/finance/summary')->assertOk()->json('data');
        $this->assertSame(['100000.00', '48300.00', '51700.00'], [$summary['totals']['income'], $summary['totals']['expense'], $summary['totals']['net']]);
        $this->assertSame($summary['totals']['income'], $incomeList->json('meta.totals.money_received')); // the Income page and the summary agree
    }

    // ------------------------------------------------------------------ the options per role

    public function test_form_options_follow_the_role_and_hide_what_the_user_cannot_use(): void
    {
        $owner = ['income' => $this->codes('income'), 'expense' => $this->codes('expense')];
        $this->assertSame('farm_sale', $owner['income'][0]);
        $this->assertContains('services', $owner['income']);
        $this->assertSame('store_purchase', $owner['expense'][0]);
        $this->assertContains('other_expense', $owner['expense']);
        $this->assertGreaterThan(2, count($owner['expense']));

        // Farm Worker: may sell, may add stock, cannot book purchases or manual money.
        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->assertSame(['farm_sale'], $this->codes('income'));
        $this->assertSame([], $this->codes('expense'));
        $this->assertTrue($this->getJson('/api/v1/finance/form-options?direction=income')->json('data.can_add_stock'));

        // Finance: everything on both forms, but no inventory.add (cannot add stock).
        $this->signInAs($this->member(FarmRole::Finance));
        $this->assertSame($owner['income'], $this->codes('income'));
        $this->assertSame($owner['expense'], $this->codes('expense'));
        $this->assertFalse($this->getJson('/api/v1/finance/form-options?direction=expense')->json('data.can_add_stock'));

        // Vet: nothing to show.
        $this->signInAs($this->member(FarmRole::Vet));
        $this->assertSame([], $this->codes('income'));
        $this->assertSame([], $this->codes('expense'));

        // direction is required and checked
        $this->signInAs($this->owner);
        $this->getJson('/api/v1/finance/form-options')->assertStatus(422)->assertJsonValidationErrors('direction');
        $this->getJson('/api/v1/finance/form-options?direction=sideways')->assertStatus(422)->assertJsonValidationErrors('direction');
    }

    public function test_a_purchase_cannot_use_another_farms_item_or_create_items_across_farms(): void
    {
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $foreign = $this->item('Foreign feed', 'feed', 'kg');

        $this->signInAs($this->owner);
        $this->purchase([['kind' => 'stock', 'inventory_item_id' => $foreign, 'components' => [['quantity' => '1', 'unit' => 'kg']], 'amount' => '100']])->assertStatus(404);
        // new_item with the same name is this farm's own item, not the foreign one
        $made = $this->purchase([['kind' => 'stock', 'new_item' => ['name' => 'Foreign feed', 'category' => 'feed', 'unit' => 'kg'], 'components' => [['quantity' => '1', 'unit' => 'kg']], 'amount' => '100']])
            ->assertCreated()->json('data');
        $this->assertFalse($made['items'][0]['item_reused']);
        $this->assertNotSame($foreign, $made['items'][0]['inventory_item_id']);
    }
}
