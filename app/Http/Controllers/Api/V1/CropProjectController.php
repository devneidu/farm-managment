<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Records\CropMetrics;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

class CropProjectController extends Controller
{
    /**
     * Crop project detail
     *
     * Requires production_cycle.view and record.view. Everything is derived from the project's immutable planting-unit
     * baseline and its own non-reversed records (reversed records are excluded; nothing is stored): planting progress,
     * latest establishment check (established, failed units and survival percent against the baseline: 47 of 50 = 94),
     * current growth stage, crop losses by cause, harvest totals per normalised unit (g, ml, piece) and activity counts.
     * Planting units, seed/material quantity, land area and harvested output stay separate. Livestock cycles return
     * 409 not_a_crop_project; foreign or unknown projects return 404.
     */
    #[Response(status: 200, type: 'array{data: array<string,mixed>, meta: object, message: null}')]
    #[Response(status: 409, type: 'array{message:string, code:"not_a_crop_project", request_id:string}')]
    public function show(FarmContext $ctx, CropMetrics $metrics, string $cycle): JsonResponse
    {
        return ApiResponse::success($metrics->summary($ctx, $cycle));
    }
}
