<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A snapshotted invoice line: later edits to products, contacts or sale lines never change it. */
class InvoiceItem extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Invoice lines are immutable.'));
    }
}
