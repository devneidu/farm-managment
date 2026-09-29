<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/** Registry row for a Feature/Limit key (the enums are the source of valid keys). */
class Entitlement extends Model
{
    use HasUuidV7;

    public const TYPE_FEATURE = 'feature';

    public const TYPE_LIMIT = 'limit';

    protected $fillable = ['key', 'type', 'name', 'description'];
}
