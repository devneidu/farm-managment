<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class OneTimeCode extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
