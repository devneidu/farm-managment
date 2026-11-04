<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/** One user's phone-calendar subscription for one farm. The secret token is stored hashed (lookup) and encrypted (so the link can be shown again). */
class CalendarFeed extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'include_milestones' => 'boolean'];
    }
}
