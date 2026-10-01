<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class FeedFormulaItem extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];
}
