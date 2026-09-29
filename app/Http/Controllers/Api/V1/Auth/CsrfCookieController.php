<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\Response;

class CsrfCookieController extends Controller
{
    /**
     * Initialise CSRF protection
     *
     * Call once before the first state-changing request (and again if a request fails with
     * 419). Sets the `XSRF-TOKEN` cookie; the frontend must echo its value in the
     * `X-XSRF-TOKEN` header on every POST/PUT/PATCH/DELETE (Axios does this automatically
     * with `withCredentials` + `withXSRFToken`). Returns an empty 204 response.
     *
     * @unauthenticated
     */
    #[ScrambleResponse(status: 204, description: 'CSRF cookie set')]
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
