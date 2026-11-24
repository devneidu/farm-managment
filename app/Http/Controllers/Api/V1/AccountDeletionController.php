<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DeleteAccountRequest;
use App\Services\Account\AccountDeletionService;
use App\Services\Auth\OtpDelivery;
use App\Services\Auth\OtpService;
use App\Services\Auth\SessionAuthenticator;
use App\Support\Api\ApiHttpException;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Delete my account (user-level; works for a cookie session and for a Bearer token alike; no verified email or farm needed, so an
 * unverified or never-onboarded account can also be deleted). The work is done by AccountDeletionService.
 */
class AccountDeletionController extends Controller
{
    /**
     * Can I delete my account?
     *
     * Call this before showing the delete screen. `allowed` is false while any blocker exists; every blocker has a stable `code`
     * (`owner_must_transfer`, `marketplace_deals_open`, `platform_admin_protected`) and a plain `message`. `owner_must_transfer` names the
     * farm in `farm`; `marketplace_deals_open` carries `count` and the affected `shops`. `has_password` tells the UI whether to ask for the
     * password or to offer "email me a code" (`POST /account/delete/request-code`); either method works for any account.
     *
     * Errors: `401`.
     *
     * @response array{data: array{allowed: bool, blockers: array<int, array{code: string, message: string, farm?: array{id: string, name: string}, shops?: array<int, array{id: string, name: string}>, count?: int}>, has_password: bool, confirmation: string}, meta: object, message: string|null}
     */
    public function check(Request $request, AccountDeletionService $deletion): JsonResponse
    {
        $blockers = $deletion->blockers($request->user());

        return ApiResponse::success([
            'allowed' => $blockers === [],
            'blockers' => $blockers,
            'has_password' => $request->user()->password !== null,
            'confirmation' => 'DELETE',
        ]);
    }

    /**
     * Email me a deletion code
     *
     * For accounts without a password (Google sign-in) and for anyone who prefers a code: emails a one-time 6-digit code (same OTP system
     * as email verification; valid for the usual OTP lifetime, single use, a new request replaces the previous code). A cooldown applies
     * between sends (`429` with `Retry-After`).
     *
     * Errors: `401`, `429`.
     *
     * @response array{data: array{cooldown_seconds: int}, meta: object, message: string}
     */
    public function requestCode(Request $request, OtpService $otps, OtpDelivery $delivery): JsonResponse
    {
        $user = $request->user();

        $wait = $otps->cooldownRemaining($user, OtpPurpose::AccountDeletion);

        if ($wait > 0) {
            throw new ApiHttpException(429, 'too_many_requests', "Please wait {$wait} seconds before requesting another code.", ['Retry-After' => $wait]);
        }

        $delivery->send($user, OtpPurpose::AccountDeletion);

        return ApiResponse::success(['cooldown_seconds' => (int) config('identity.otp.resend_cooldown_seconds')], message: 'A confirmation code has been sent to your email.');
    }

    /**
     * Delete my account
     *
     * Body: `{ "confirm": "DELETE", "password": "…" }` or `{ "confirm": "DELETE", "code": "123456" }`. Deletes immediately and permanently:
     * the account is anonymised (name, email, password, Google link, devices and sessions are removed and the email can be used to sign up
     * again), your farm memberships end, a farm you were the only member of is archived, your marketplace shops are closed, and a final
     * email goes to your old address. Farm records you created are kept and show "Deleted user". Everything happens in one transaction;
     * on any error nothing changes and the request can be repeated. After success every token/session is gone, so a repeated request is `401`.
     *
     * Errors: `401`, `409 account_deletion_blocked` (`details.blockers` lists what to resolve first; see `GET /account/delete/check`),
     * `422` (`confirm` is not exactly `DELETE`, wrong `password`, or invalid/expired `code`), `429`.
     *
     * @response array{data: null, meta: object, message: string}
     */
    #[Response(status: 409, description: 'Deletion is blocked', type: 'array{message: string, code: "account_deletion_blocked", details: array{blockers: array<int, array{code: string, message: string}>}, request_id: string}')]
    public function destroy(DeleteAccountRequest $request, AccountDeletionService $deletion, OtpService $otps, SessionAuthenticator $sessions): JsonResponse
    {
        $user = $request->user();

        // Blockers first, so a blocked request never spends the emailed code.
        if ($blockers = $deletion->blockers($user)) {
            throw new ApiHttpException(409, 'account_deletion_blocked', 'This account cannot be deleted yet.', details: ['blockers' => $blockers]);
        }

        $method = $this->verifyCredential($request, $user, $otps);

        $deletion->delete($user, method: $method);

        $sessions->logout($request);

        return ApiResponse::success(null, message: 'Your account has been deleted.');
    }

    private function verifyCredential(DeleteAccountRequest $request, $user, OtpService $otps): string
    {
        if ($request->filled('password')) {
            if ($user->password === null || ! Hash::check($request->validated('password'), $user->password)) {
                throw ValidationException::withMessages(['password' => ['The password is incorrect.']]);
            }

            return 'password';
        }

        if (! $otps->consume($user, OtpPurpose::AccountDeletion, $request->validated('code'))) {
            throw ValidationException::withMessages(['code' => ['The code is invalid or has expired.']]);
        }

        return 'code';
    }
}
