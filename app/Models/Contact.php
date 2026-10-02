<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/** A person or business the farm deals with. One row may be both supplier and customer; there is no second copy per role. */
class Contact extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_supplier' => 'boolean', 'is_customer' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $c) => $c->normalized_name = static::normalizeName($c->name));
        static::updating(function (self $c) {
            if ($c->isDirty(['farm_id', 'created_by'])) {
                throw new LogicException('Contact ownership is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Deactivate contacts; purchases and transactions keep referring to them.'));
    }

    public static function normalizeName(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $name)));
    }

    public function scopeOfFarm(Builder $query, Farm $farm): Builder
    {
        return $query->where($this->qualifyColumn('farm_id'), $farm->id);
    }

    /** @return list<string> */
    public function roles(): array
    {
        return array_values(array_filter([$this->is_supplier ? 'supplier' : null, $this->is_customer ? 'customer' : null]));
    }
}
