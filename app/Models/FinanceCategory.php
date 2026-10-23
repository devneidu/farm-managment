<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** An income or expense category: a platform row (farm_id NULL) every farm sees, or one farm's own. */
class FinanceCategory extends Model
{
    use HasUuidV7;

    /** Platform income categories that only Sales may book (payments use them); a manual transaction must go through Sales instead. */
    public const SALES_ONLY_CODES = ['livestock_sales', 'egg_sales', 'milk_sales', 'crop_sales'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** False for the product-sales categories: the form hides them and POST /finance/transactions refuses them. */
    public function isManualEntry(): bool
    {
        return ! ($this->farm_id === null && in_array($this->code, self::SALES_ONLY_CODES, true));
    }

    public function scopeVisibleTo(Builder $query, Farm $farm): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('farm_id')->orWhere('farm_id', $farm->id));
    }
}
