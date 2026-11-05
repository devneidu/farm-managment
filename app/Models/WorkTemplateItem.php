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

    // Explicit (not guarded): the platform-template provisioning migration builds items through this model, and Laravel then caches the table's
    // column list for the rest of the process, silently dropping columns added by later migrations.
    protected $fillable = [
        'work_template_id', 'position', 'title', 'category', 'instructions', 'anchor', 'offset_days', 'recurrence', 'interval_value', 'weekdays', 'until_offset_days',
        'occurrence_limit', 'ends_type', 'ends_on', 'due_time', 'reminder_offsets', 'reminder_presets', 'assigned_role', 'linked_record_type', 'requires_evidence',
    ];

    protected function casts(): array
    {
        return [
            'anchor' => TemplateAnchor::class, 'recurrence' => Recurrence::class, 'category' => TaskCategory::class, 'requires_evidence' => 'boolean',
            'weekdays' => 'array', 'reminder_offsets' => 'array', 'reminder_presets' => 'array', 'ends_on' => 'date',
        ];
    }
}
