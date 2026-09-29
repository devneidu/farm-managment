<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmMembership extends Model
{
    use HasUuidV7;

    public const ROLE_OWNER = 'owner';

    // Never populated from request input; set explicitly by services.
    protected $fillable = ['farm_id', 'user_id', 'role'];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
