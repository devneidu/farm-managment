<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Farm extends Model
{
    use HasFactory, HasUuidV7;

    protected $fillable = ['name'];

    protected static function booted(): void
    {
        // Every farm has a deterministic subscription state from the moment it exists (same transaction as creation).
        static::created(fn (Farm $farm) => app(SubscriptionService::class)->startDefault($farm));
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FarmMembership::class);
    }

    public function ownerMembership(): HasOne
    {
        return $this->hasOne(FarmMembership::class)->where('role', FarmMembership::ROLE_OWNER)->where('status', 'active');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(FarmInvitation::class);
    }

    public function productionCycles(): HasMany
    {
        return $this->hasMany(ProductionCycle::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function productionAreas(): HasMany
    {
        return $this->hasMany(ProductionArea::class);
    }

    public function storageLocations(): HasMany
    {
        return $this->hasMany(StorageLocation::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'farm_memberships')
            ->withPivot('role')
            ->wherePivot('status', 'active');
    }
}
