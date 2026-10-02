<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class WorkTemplate extends Model
{
    use HasUuidV7;

    public const PLATFORM = 'platform';

    public const FARM = 'farm';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Deactivate templates; never delete them.'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkTemplateItem::class)->orderBy('position');
    }
}
