<?php

namespace App\Http\Requests\Notifications;

use App\Services\Notifications\NotificationCatalogue;
use App\Support\Access\FarmContext;
use App\Support\Access\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;

class PatchNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $channels = implode(',', NotificationPreferences::CHANNELS);
        $types = implode(',', array_keys(NotificationCatalogue::availableTo(app(FarmContext::class))));

        return [
            /** Channel switches. Omitted channels keep their value. */
            'channels' => ['sometimes', 'array:'.$channels],
            'channels.*' => ['boolean'],
            /** Per-type switches ({type_code: bool}); only the types GET /notification-preferences lists for you are accepted. */
            'types' => ['sometimes', 'array'.($types !== '' ? ':'.$types : '')],
            'types.*' => ['boolean'],
        ];
    }
}
