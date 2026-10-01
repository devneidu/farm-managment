<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'original_name' => $this->original_name, 'mime_type' => $this->mime_type, 'size' => $this->size,
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(),
            'download_url' => route('api.v1.records.attachments.download', ['record' => $this->operational_record_id, 'attachment' => $this->id])];
    }
}
