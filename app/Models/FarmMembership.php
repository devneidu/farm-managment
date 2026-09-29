<?php

namespace App\Models;

use App\Enums\FarmRole;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's relationship to one farm: role + lifecycle (active|removed).
 * Authorization always starts from an ACTIVE membership.
 */
class FarmMembership extends Model
{
    use HasUuidV7;

    public const ROLE_OWNER = 'owner';

    // Never populated from request input; set explicitly by services.
    protected $fillable = ['farm_id', 'user_id', 'role'];

    protected function casts(): array
    {
        return [
            'role' => FarmRole::class,
            'status' => MembershipStatus::class,
            'removed_at' => 'datetime',
            'notification_preferences' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), MembershipStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function can(Permission $permission): bool
    {
        return $this->isActive() && $this->role->can($permission);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
