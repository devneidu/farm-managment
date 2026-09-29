<?php

namespace App\Models;

use App\Enums\FarmRole;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FarmInvitation extends Model
{
    use HasUuidV7;

    public const PENDING = 'pending';

    public const EXPIRED = 'expired';

    public const ACCEPTED = 'accepted';

    public const REVOKED = 'revoked';

    // Set explicitly by InvitationService; never from request input.
    protected $fillable = ['farm_id', 'email', 'role', 'invited_by_user_id', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'role' => FarmRole::class,
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{0: string, 1: string} [plaintext token, hash] */
    public static function newToken(): array
    {
        $token = Str::random(48);

        return [$token, self::hashToken($token)];
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => self::ACCEPTED,
            $this->revoked_at !== null => self::REVOKED,
            $this->expires_at->isPast() => self::EXPIRED,
            default => self::PENDING,
        };
    }

    public function isPending(): bool
    {
        return $this->status() === self::PENDING;
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
