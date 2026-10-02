<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** An income or expense category: a platform row (farm_id NULL) every farm sees, or one farm's own. */
class FinanceCategory extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeVisibleTo(Builder $query, Farm $farm): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('farm_id')->orWhere('farm_id', $farm->id));
    }
}
