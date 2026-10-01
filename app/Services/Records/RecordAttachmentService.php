<?php

namespace App\Services\Records;

use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Http\Requests\Records\AttachRecordRequest;
use App\Models\Farm;
use App\Models\ProductionCycle;
use App\Models\RecordAttachment;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class RecordAttachmentService
{
    public function __construct(private RecordService $records) {}

    public function attach(FarmContext $ctx, string $id, UploadedFile $file): RecordAttachment
    {
        $ctx->authorize(Permission::RecordCreate);
        Validator::make(['file' => $file], (new AttachRecordRequest)->rules())->validate();
        $path = null;
        try {
            // No automatic transaction retry around a filesystem write; compensate on DB failure.
            return DB::transaction(function () use ($ctx, $id, $file, &$path) {
                Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
                $record = $this->records->find($ctx, $id);
                $cycle = ProductionCycle::ofFarm($ctx->farm)->lockForUpdate()->findOrFail($record->production_cycle_id);
                if ($cycle->status !== CycleStatus::Active) {
                    throw new ApiHttpException(409, 'cycle_closed', 'Reopen the cycle before adding evidence.');
                }
                $hash = hash_file('sha256', $file->getRealPath());
                if ($existing = $record->attachments()->where('sha256', $hash)->first()) {
                    return $existing;
                }
                if ($record->attachments()->count() >= config('records.attachments.max_per_record')) {
                    throw new ApiHttpException(409, 'attachment_limit_reached', 'The attachment limit for this record has been reached.');
                }
                $path = $file->store('records/'.$ctx->farm->id.'/'.$record->id, ['disk' => 'local', 'visibility' => 'private']);
                if (! $path) {
                    throw new ApiHttpException(503, 'attachment_storage_failed', 'The attachment could not be stored.');
                }

                return RecordAttachment::create(['farm_id' => $ctx->farm->id, 'operational_record_id' => $record->id, 'path' => $path,
                    'original_name' => mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 200),
                    'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => $hash, 'created_by' => $ctx->membership->user_id]);
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }
}
