<?php

namespace App\Http\Resources;

use App\Models\FarmNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FarmNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** A notification type code; GET /notification-preferences lists the codes available to you. */
            'type' => $this->type,
            /** @var 'critical'|'warning'|'info' */
            'severity' => $this->severity,
            'title' => $this->title, 'message' => $this->message,
            /** What it is about, to open it: a production cycle, inventory item, task, export, ... (null for farm-wide conditions). */
            'source' => $this->subject_type ? ['type' => $this->subject_type, 'id' => $this->subject_id, 'reference' => $this->subject_reference] : null,
            /** Why it was raised (rule, measured values, thresholds) for insight-based notifications. */
            'data' => (object) ($this->data ?? []),
            'is_read' => $this->read_at !== null, 'read_at' => $this->read_at?->toISOString(), 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
