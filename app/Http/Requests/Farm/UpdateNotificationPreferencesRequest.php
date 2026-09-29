<?php

namespace App\Http\Requests\Farm;

use App\Support\Access\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $channels = implode(',', NotificationPreferences::CHANNELS);

        return [
            // array:<keys> rejects unknown channels
            'channels' => ['required', 'array:'.$channels],
            'channels.*' => ['boolean'],
        ];
    }
}
