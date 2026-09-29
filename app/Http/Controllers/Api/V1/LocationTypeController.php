<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlaceKind;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class LocationTypeController extends Controller
{
    /**
     * Read-only place type catalogue
     *
     * Shared stable codes grouped by resource kind. Custom names are unrestricted; use other for unlisted types.
     * Type never implies a biological capacity or operation. Requires location.view.
     *
     * @response array{data: list<array{kind: string, types: list<array{code: string, label: string}>}>, meta: object, message: null}
     */
    public function index(): JsonResponse
    {
        $groups = [];
        foreach (PlaceKind::cases() as $kind) {
            $types = [];
            foreach ($kind->types() as $code => $label) {
                $types[] = ['code' => $code, 'label' => $label];
            }
            $groups[] = ['kind' => $kind->value, 'types' => $types];
        }

        return ApiResponse::success($groups);
    }
}
