<?php

namespace App\Http\Requests\Auth;

class MobileGoogleLoginRequest extends GoogleLoginRequest
{
    public function rules(): array
    {
        return parent::rules() + ['device_name' => ['required', 'string', 'max:100']];
    }
}
