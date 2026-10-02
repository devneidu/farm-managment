<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class BreedingCheck extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checked_on' => 'immutable_date', 'fertile_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Breeding checks are append-only.'));
        static::deleting(fn () => throw new LogicException('Breeding checks are append-only.'));
    }
}
