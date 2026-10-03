<?php

namespace App\Models;

use App\Enums\PlatformRole;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A platform-admin grant. Created only by the platform:grant-admin command; never mass assignable from a request. */
class PlatformAdmin extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['role' => PlatformRole::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
