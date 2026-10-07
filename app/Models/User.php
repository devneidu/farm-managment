<?php

namespace App\Models;

use App\Enums\PlatformRole;
use App\Models\Concerns\HasUuidV7;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuidV7, Notifiable;

    // email_verified_at / onboarded_at / suspended_at are deliberately NOT mass assignable.
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FarmMembership::class);
    }

    public function farms(): BelongsToMany
    {
        return $this->belongsToMany(Farm::class, 'farm_memberships')
            ->withPivot('role')
            ->wherePivot('status', 'active');
    }

    /** Shops this user is a member of (Marketplace, any status). */
    public function marketplaceShopCount(): int
    {
        return MarketplaceShopMember::where('user_id', $this->id)->count();
    }

    public function platformAdmin(): HasOne
    {
        return $this->hasOne(PlatformAdmin::class);
    }

    /** The platform role granted to this user, if any. Independent of every farm membership. */
    public function platformRole(): ?PlatformRole
    {
        return $this->platformAdmin?->role;
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** Default farm context: the oldest ACTIVE membership (an X-Farm-Id header can select another one). */
    public function currentFarm(): ?Farm
    {
        return $this->farms()->orderBy('farm_memberships.created_at')->first();
    }
}
