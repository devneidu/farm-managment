<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\AccountResource;
use App\Support\Api\ApiHttpException;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    /**
     * Get my account
     *
     * @response array{data: AccountResource, meta: object, message: string|null}
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success((new AccountResource($request->user()))->resolve($request));
    }

    /**
     * Update my profile
     *
     * Only `name` is editable. Email is read-only in this phase; verification and onboarding state can
     * never be changed here (extra fields are ignored).
     *
     * @response array{data: AccountResource, meta: object, message: string|null}
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->validated())->save();

        return ApiResponse::success((new AccountResource($user))->resolve($request), message: 'Profile updated.');
    }

    /**
     * Change my password
     *
     * Requires the current password. On success every other session and all API tokens are revoked; the
     * current session stays signed in. Accounts without a password (Google-only) get `409 password_not_set`:
     * use the forgot-password flow to set one.
     *
     * Errors: `401`, `409 password_not_set`, `422` (wrong `current_password`, weak/unconfirmed password), `429`.
     */
    public function password(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->password === null) {
            throw new ApiHttpException(409, 'password_not_set', 'Your account has no password. Use "Forgot password" to set one.');
        }

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        DB::transaction(function () use ($user, $request) {
            $user->forceFill(['password' => $request->validated('password'), 'remember_token' => null])->save();
            $user->tokens()->delete();

            if (config('session.driver') === 'database' && $request->hasSession()) {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->where('id', '!=', $request->session()->getId())
                    ->delete();
            }
        });

        return ApiResponse::success(message: 'Password changed.');
    }
}
