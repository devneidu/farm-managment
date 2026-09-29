<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    use NormalizesEmail;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', self::passwordRule()],
        ];
    }

    /** Shared new-password policy: 8-72 chars with at least one letter and one number. */
    public static function passwordRule(): Password
    {
        return Password::min(8)->max(72)->letters()->numbers();
    }
}
