<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmOperation extends Model
{
    use HasUuidV7;

    protected $fillable = ['farm_id', 'operation_type_id'];

    public function operationType(): BelongsTo
    {
        return $this->belongsTo(OperationType::class);
    }
}
