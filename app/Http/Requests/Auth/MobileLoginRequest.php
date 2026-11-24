<?php

namespace App\Http\Requests\Auth;

class MobileLoginRequest extends LoginRequest
{
    public function rules(): array
    {
        return parent::rules() + ['device_name' => ['required', 'string', 'max:100']];
    }
}
