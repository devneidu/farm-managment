<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class UploadListingImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $kb = (int) config('marketplace.images.max_kilobytes');

        return [
            /** multipart file: JPEG, PNG or WebP, at most 5 MB. Re-encoded server-side (metadata is dropped). Remote URLs are never accepted. */
            'image' => ['required', 'file', 'max:'.$kb, 'mimetypes:image/jpeg,image/png,image/webp'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:160'],
        ];
    }
}
