<?php

namespace App\Services\Inventory;

use App\Enums\Permission;
use App\Http\Requests\Inventory\InventoryRules;
use App\Http\Requests\Inventory\StoreFormulaRequest;
use App\Http\Requests\Inventory\UpdateFormulaRequest;
use App\Models\Farm;
use App\Models\FeedFormula;
use App\Models\InventoryItem;
use App\Models\Species;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Measurement\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A feed formula is a recipe (ingredients as percentages of the mix). It is deliberately NOT stock: creating or editing
 * one never creates an inventory movement, and an ingredient may point at an inventory item only for reference.
 */
class FeedFormulaService
{
    public function find(FarmContext $ctx, string $id): FeedFormula
    {
        $ctx->authorize(Permission::InventoryView);

        return FeedFormula::where('farm_id', $ctx->farm->id)->with('items')->findOrFail($id);
    }

    public function create(FarmContext $ctx, array $input): FeedFormula
    {
        $ctx->authorize(Permission::InventoryManage);
        $data = Validator::make($input, (new StoreFormulaRequest)->rules() + InventoryRules::forbidden())->validate();

        return DB::transaction(function () use ($ctx, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $formula = new FeedFormula([
                'farm_id' => $ctx->farm->id, 'name' => $this->clean($data['name']), 'description' => $data['description'] ?? null,
                'species_id' => $this->species($data['species_id'] ?? null), 'version' => 1, 'is_active' => true, 'created_by' => $ctx->membership->user_id,
            ]);
            $this->assertNameFree($ctx->farm, $formula->name);
            $items = $this->items($ctx->farm, $data['items']);
            $this->guard(fn () => $formula->save());
            $this->replaceItems($formula, $items);

            return $formula->load('items');
        }, 3);
    }

    public function update(FarmContext $ctx, string $id, array $input): FeedFormula
    {
        $ctx->authorize(Permission::InventoryManage);
        $data = Validator::make($input, (new UpdateFormulaRequest)->rules() + InventoryRules::forbidden())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $formula = FeedFormula::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($id);
            if (isset($data['name'])) {
                $name = $this->clean($data['name']);
                if ($name !== $formula->name) {
                    $this->assertNameFree($ctx->farm, $name, $formula->id);
                    $formula->name = $name;
                }
            }
            foreach (['description', 'is_active'] as $field) {
                if (array_key_exists($field, $data)) {
                    $formula->{$field} = $data[$field];
                }
            }
            if (array_key_exists('species_id', $data)) {
                $formula->species_id = $this->species($data['species_id']);
            }
            if (isset($data['items'])) {
                $this->replaceItems($formula, $this->items($ctx->farm, $data['items']));
                $formula->version++;
            }
            $this->guard(fn () => $formula->save());

            return $formula->refresh()->load('items');
        }, 3);
    }

    /** @return list<array{ingredient_name: string, inclusion_percent: string, inventory_item_id: string|null}> */
    private function items(Farm $farm, array $rows): array
    {
        $total = '0';
        $names = [];
        $ids = [];
        $out = [];
        foreach ($rows as $index => $row) {
            $percent = Decimal::parse($row['inclusion_percent']);
            if ($percent === null || Decimal::isNegative($percent) || Decimal::isZero($percent) || Decimal::cmp($percent, '100') > 0 || (str_contains($percent, '.') && strlen(explode('.', $percent)[1]) > 4)) {
                $this->invalid("items.$index.inclusion_percent", 'Use a percentage above 0 and up to 100 with at most 4 decimals.');
            }
            $name = $this->clean($row['ingredient_name']);
            if (in_array(mb_strtolower($name), $names, true)) {
                $this->invalid("items.$index.ingredient_name", 'Each ingredient may appear once.');
            }
            $names[] = mb_strtolower($name);
            $itemId = $row['inventory_item_id'] ?? null;
            if ($itemId !== null) {
                if (in_array($itemId, $ids, true)) {
                    $this->invalid("items.$index.inventory_item_id", 'Each inventory item may appear once.');
                }
                $ids[] = $itemId;
                if (! InventoryItem::ofFarm($farm)->where('id', $itemId)->exists()) {
                    $this->invalid("items.$index.inventory_item_id", 'Choose an inventory item of this farm.');
                }
            }
            $total = Decimal::add($total, $percent);
            $out[] = ['ingredient_name' => $name, 'inclusion_percent' => $percent, 'inventory_item_id' => $itemId];
        }
        if (Decimal::cmp($total, '100') !== 0) {
            $this->invalid('items', 'Ingredient percentages must add up to exactly 100.');
        }

        return $out;
    }

    private function replaceItems(FeedFormula $formula, array $items): void
    {
        $formula->items()->delete();
        foreach ($items as $item) {
            $formula->items()->create($item + ['farm_id' => $formula->farm_id]);
        }
    }

    private function species(?string $id): ?string
    {
        if ($id !== null && ! Species::whereKey($id)->exists()) {
            $this->invalid('species_id', 'Choose an existing species.');
        }

        return $id;
    }

    private function assertNameFree(Farm $farm, string $name, ?string $ignore = null): void
    {
        $q = FeedFormula::where('farm_id', $farm->id)->where('normalized_name', mb_strtolower($name));
        if ($ignore) {
            $q->where('id', '!=', $ignore);
        }
        if ($q->exists()) {
            throw new ApiHttpException(409, 'feed_formula_exists', 'A feed formula with this name already exists on this farm.');
        }
    }

    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (UniqueConstraintViolationException) {
            throw new ApiHttpException(409, 'feed_formula_exists', 'A feed formula with this name already exists on this farm.');
        }
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
