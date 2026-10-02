<?php

namespace Database\Seeders;

use App\Models\FinanceCategory;
use Illuminate\Database\Seeder;

/**
 * Platform income/expense categories (farm_id NULL). Idempotent by code+direction; never overwrites a platform edit.
 * The codes feed/medicine_veterinary/seed_planting_material/fertilizer_agrochemical mirror the inventory categories so a
 * stocked purchase books to the matching expense category by default.
 */
class FinanceCategorySeeder extends Seeder
{
    public const EXPENSE = [
        'feed' => 'Feed', 'medicine_veterinary' => 'Medicine & veterinary', 'seed_planting_material' => 'Seed & planting material',
        'fertilizer_agrochemical' => 'Fertilizer & agrochemicals', 'livestock_purchase' => 'Livestock purchase', 'labour' => 'Labour',
        'transport' => 'Transport', 'utilities' => 'Utilities', 'equipment_repairs' => 'Equipment & repairs',
        'general_supplies' => 'General supplies', 'other_expense' => 'Other expense',
    ];

    public const INCOME = [
        'livestock_sales' => 'Livestock sales', 'egg_sales' => 'Egg sales', 'milk_sales' => 'Milk sales', 'crop_sales' => 'Crop & produce sales',
        'other_income' => 'Other income',
    ];

    public function run(): void
    {
        foreach (['expense' => self::EXPENSE, 'income' => self::INCOME] as $direction => $rows) {
            $order = 0;
            foreach ($rows as $code => $name) {
                FinanceCategory::firstOrCreate(['farm_id' => null, 'code' => $code, 'direction' => $direction], ['name' => $name, 'is_active' => true, 'sort_order' => ++$order * 10]);
            }
        }
    }
}
