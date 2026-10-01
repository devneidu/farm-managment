<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ListFormulasRequest;
use App\Http\Requests\Inventory\StoreFormulaRequest;
use App\Http\Requests\Inventory\UpdateFormulaRequest;
use App\Http\Resources\FeedFormulaResource;
use App\Services\Inventory\FeedFormulaService;
use App\Services\Inventory\InventoryQueries;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedFormulaController extends Controller
{
    /**
     * List feed formulas
     *
     * Requires inventory.view. A formula is a recipe, not stock. Active formulas by default.
     *
     * @response array{data: FeedFormulaResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function index(ListFormulasRequest $request, FarmContext $ctx, InventoryQueries $queries): JsonResponse
    {
        $page = $queries->formulas($ctx, $request->validated());

        return ApiResponse::success(FeedFormulaResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Create a feed formula
     *
     * Requires inventory.manage. Ingredient percentages must add up to exactly 100. Creating a formula never creates or changes stock.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\FeedFormulaResource, meta: object, message: string}')]
    #[Response(status: 409, type: 'array{message:string, code:"feed_formula_exists", request_id:string}')]
    public function store(StoreFormulaRequest $request, FarmContext $ctx, FeedFormulaService $service): JsonResponse
    {
        return ApiResponse::success((new FeedFormulaResource($service->create($ctx, $request->validated())))->resolve($request), message: 'Feed formula created.', status: 201);
    }

    /**
     * Show a feed formula. Requires inventory.view. Foreign formulas return 404.
     *
     * @response array{data: FeedFormulaResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, FeedFormulaService $service, string $formula): JsonResponse
    {
        return ApiResponse::success((new FeedFormulaResource($service->find($ctx, $formula)))->resolve($request));
    }

    /**
     * Update a feed formula
     *
     * Requires inventory.manage. Sending `items` replaces the ingredient list and increments `version`.
     */
    #[Response(status: 409, type: 'array{message:string, code:"feed_formula_exists", request_id:string}')]
    public function update(UpdateFormulaRequest $request, FarmContext $ctx, FeedFormulaService $service, string $formula): JsonResponse
    {
        return ApiResponse::success((new FeedFormulaResource($service->update($ctx, $formula, $request->validated())))->resolve($request), message: 'Feed formula updated.');
    }
}
