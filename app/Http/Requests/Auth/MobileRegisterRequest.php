<?php

namespace App\Http\Requests\Auth;

class MobileRegisterRequest extends RegisterRequest
{
    public function rules(): array
    {
        return parent::rules() + ['device_name' => ['required', 'string', 'max:100']];
    }
}
