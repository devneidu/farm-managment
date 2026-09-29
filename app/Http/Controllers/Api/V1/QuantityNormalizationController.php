<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConversionContextType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Measurement\NormalizeQuantityRequest;
use App\Services\Measurement\QuantityNormalizer;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use App\Support\Measurement\ConversionContext;
use Illuminate\Http\JsonResponse;

/** Live preview of how an entered quantity normalizes. Uses exactly the service that operational modules will use. */
class QuantityNormalizationController extends Controller
{
    /**
     * Preview a normalized quantity
     *
     * Send the parts the user typed as `components` (`[{"quantity": "3", "unit": "crate"}, {"quantity": "14",
     * "unit": "piece"}]`) and, whenever a package unit (bag, crate, ...) is used, the `context`
     * (`{"type": "custom", "id": "<uuid>"}` - the `context.type`/`context.id` of a package conversion; the id must be
     * a crop type or one of this farm's active measurement contexts, else `422` on `context.id`). Optional
     * `result_unit` chooses the unit of `total`.
     *
     * Result: `entered` (preserved as typed), `normalized` (the total in the dimension's canonical unit - what to
     * store), `total` (the same in `result_unit`, else the package's target unit, else the first part's unit) and
     * `snapshot` (a self-contained record of every conversion used; store it with the record so later changes to
     * farm settings never change history). Example: 3 crates + 14 pieces with 1 crate = 30 pieces -> total
     * `104 piece`. Only this farm's active definitions are used, and the API never guesses a context.
     *
     * Errors: `403`, `422` validation, `422 incompatible_units` (details: from_unit, to_unit, dimensions),
     * `422 conversion_context_required` / `422 ambiguous_conversion` (details: candidates),
     * `422 conversion_not_configured` (details: unit, context), `422 invalid_quantity` (details: unit, reason),
     * `422 unknown_unit`, `422 unit_not_selectable`, `429`.
     *
     * @response array{data: array{entered: array{quantity: string, unit: string}[], normalized: array{quantity: string, unit: string}, total: array{quantity: string, unit: string}, snapshot: array<string, mixed>}, meta: object, message: string|null}
     */
    public function __invoke(NormalizeQuantityRequest $request, FarmContext $ctx, QuantityNormalizer $normalizer): JsonResponse
    {
        $context = $request->validated('context')
            ? new ConversionContext(ConversionContextType::from($request->validated('context.type')), $request->validated('context.id'))
            : null;

        $result = $normalizer->normalize($ctx->farm, $request->validated('components'), $context, $request->validated('result_unit'));

        return ApiResponse::success($result->toArray());
    }
}
