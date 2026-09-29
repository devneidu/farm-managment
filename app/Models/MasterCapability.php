<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/** Registry row for an App\Enums\Capability code. */
class MasterCapability extends Model
{
    use HasUuidV7;

    protected $table = 'capabilities';

    protected $fillable = ['code', 'name'];
}
