<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Auth\RespondsWithAuthState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\CreateFarmRequest;
use App\Services\Farm\FarmOnboardingService;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

class OnboardingController extends Controller
{
    use RespondsWithAuthState;

    /**
     * Complete farm setup
     *
     * Creates the user's first farm (platform defaults: Nigeria, NGN, Africa/Lagos, English),
     * makes them its owner and sets `onboarded`, atomically. Returns the auth state with
     * `next_action = "none"`. Requires a verified email.
     *
     * Errors: `401`, `403 email_verification_required`, `409 already_onboarded`
     * (no duplicate farm is created), `422` (name required, 2-120 chars).
     */
    #[Response(status: 409, description: 'Farm setup already completed', type: 'array{message: string, code: "already_onboarded", request_id: string}')]
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\AuthStateResource, meta: object, message: string}')]
    public function createFarm(CreateFarmRequest $request, FarmOnboardingService $onboarding): JsonResponse
    {
        $user = $request->user();

        $onboarding->createInitialFarm($user, $request->validated('name'));

        return $this->authState($user, 'Farm created.', 201);
    }
}
