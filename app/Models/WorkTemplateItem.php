<?php

namespace App\Models;

use App\Enums\Recurrence;
use App\Enums\TaskCategory;
use App\Enums\TemplateAnchor;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class WorkTemplateItem extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'anchor' => TemplateAnchor::class, 'recurrence' => Recurrence::class, 'category' => TaskCategory::class, 'requires_evidence' => 'boolean',
            'weekdays' => 'array', 'reminder_offsets' => 'array',
        ];
    }
}
