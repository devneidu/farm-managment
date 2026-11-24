<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Exactly the word DELETE (case-sensitive): a deliberate typed confirmation.
            'confirm' => ['required', 'string', 'in:DELETE'],
            // One of the two: the account password, or the code emailed by POST /account/delete/request-code.
            'password' => ['nullable', 'string', 'max:255', 'required_without:code'],
            'code' => ['nullable', 'string', 'digits:'.(int) config('identity.otp.length'), 'required_without:password'],
        ];
    }
}
