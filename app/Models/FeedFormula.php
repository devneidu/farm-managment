<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/** A recipe only. It never holds stock and creates no inventory movement. */
class FeedFormula extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $f) => $f->normalized_name = Str::lower(trim(preg_replace('/\s+/u', ' ', $f->name))));
        static::deleting(fn () => throw new LogicException('Deactivate feed formulas; never delete them.'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(FeedFormulaItem::class)->orderBy('created_at')->orderBy('id');
    }
}
