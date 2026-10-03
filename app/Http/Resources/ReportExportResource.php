<?php

namespace App\Http\Resources;

use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportExport */
class ReportExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'report' => $this->report,
            /** @var 'csv'|'xlsx'|'pdf' */
            'format' => $this->format,
            /** The exact resolved filters the file is produced with (omitted dates were defaulted when the export was requested). */
            'filters' => (object) $this->filters,
            /** @var 'queued'|'processing'|'completed'|'failed'|'expired' */
            'status' => $this->status,
            'row_count' => $this->row_count, 'file_name' => $this->file_name, 'file_size' => $this->file_size,
            /** Present once the export is completed and not expired: GET this path (authenticated) to download the file. */
            'download_path' => $this->isDownloadable() ? '/api/v1/reports/exports/'.$this->id.'/download' : null,
            'error_code' => $this->error_code, 'error_message' => $this->error_message,
            'requested_at' => $this->created_at->toISOString(), 'started_at' => $this->started_at?->toISOString(), 'completed_at' => $this->completed_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(), 'expires_at' => $this->expires_at?->toISOString(), 'download_count' => $this->download_count,
        ];
    }
}
