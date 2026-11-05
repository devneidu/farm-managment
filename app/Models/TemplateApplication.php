<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateApplication extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'removed_at' => 'datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkTemplate::class, 'work_template_id');
    }
}
