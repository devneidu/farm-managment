<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    use HasUuidV7;

    public const PROVIDER_GOOGLE = 'google';

    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'email'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
