<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Google Identity Services ID token (JWT) obtained by the frontend.
            'credential' => ['required', 'string', 'max:4096'],
        ];
    }
}
