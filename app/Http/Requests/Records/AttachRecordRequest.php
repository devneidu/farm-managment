<?php

namespace App\Http\Requests\Records;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class AttachRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $extensions = implode(',', config('records.attachments.extensions'));

        return ['file' => ['bail', 'required', 'file', 'mimes:'.$extensions, 'extensions:'.$extensions, 'max:'.config('records.attachments.max_kilobytes'), function ($attribute, $value, $fail) {
            if ($value instanceof UploadedFile) {
                $extension = strtolower($value->getClientOriginalExtension());
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($value->getRealPath());
                $allowed = match ($extension) {
                    'jpg', 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
                    'pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv', 'application/csv'],
                    'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage'],
                    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], default => [],
                };
                if (! in_array($mime, $allowed, true) || ! in_array($extension, config('records.attachments.extensions'), true) || Validator::make(['file' => $value], ['file' => ['mimes:'.$extension]])->fails()) {
                    $fail('The file contents must match its permitted filename extension.');
                }
            }
        }],
            /** @ignoreParam */
            'farm_id' => ['missing']];
    }
}
