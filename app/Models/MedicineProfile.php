<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/** Withdrawal metadata of one medicine item. Health lines snapshot the days they used, so edits never rewrite history. */
class MedicineProfile extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['default_withdrawal_days' => 'integer'];
    }
}
