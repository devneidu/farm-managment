<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Breeding\CancelBreedingProjectRequest;
use App\Http\Requests\Breeding\ListBreedingProjectsRequest;
use App\Http\Requests\Breeding\ReverseBreedingOutcomeRequest;
use App\Http\Requests\Breeding\StoreBreedingCheckRequest;
use App\Http\Requests\Breeding\StoreBreedingOutcomeRequest;
use App\Http\Requests\Breeding\StoreBreedingProjectRequest;
use App\Http\Requests\Breeding\UpdateBreedingProjectRequest;
use App\Http\Resources\BreedingOutcomeResource;
use App\Http\Resources\BreedingProjectResource;
use App\Services\Breeding\BreedingService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BreedingProjectController extends Controller
{
    private function project($project, Request $request): array
    {
        return (new BreedingProjectResource($project))->resolve($request);
    }

    /**
     * List breeding projects
     *
     * Requires breeding.view. Current farm only; newest start first. Filter by production_cycle_id, workflow (incubation|pregnancy) and status (active|completed|cancelled).
     *
     * @response array{data: BreedingProjectResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function index(ListBreedingProjectsRequest $request, FarmContext $ctx, BreedingService $service): JsonResponse
    {
        $page = $service->list($ctx, $request->validated());

        return ApiResponse::success(BreedingProjectResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Start a breeding project
     *
     * Requires breeding.create. The workflow must be supported by the species capabilities of the cycle's species
     * (incubation -> supports_incubation, pregnancy -> supports_pregnancy, both need supports_breeding), else 422 on workflow.
     * The biological reference is frozen on the project. Expectation: an exact reference gives expectation.type=exact
     * (start + days); a ranged reference gives expectation.type=window (start + min .. start + max, never a midpoint);
     * a species without a numeric reference, or whose reference_config sets automatic_expectation=false (e.g. honeybee, caste-dependent), gets type=none unless a
     * manual expected_date / expected_from+expected_to is supplied. Nothing here changes population.
     * Closed cycles are rejected (409 cycle_closed). The required idempotency_key is farm-wide: an identical retry returns the
     * original project (201), a changed payload is 409.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\BreedingProjectResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"idempotency_conflict", request_id:string}')]
    public function store(StoreBreedingProjectRequest $request, FarmContext $ctx, BreedingService $service): JsonResponse
    {
        return ApiResponse::success($this->project($service->create($ctx, $request->validated()), $request), message: 'Breeding project started.', status: 201);
    }

    /**
     * Show a breeding project
     *
     * Requires breeding.view. Includes the frozen biological reference, the expectation, parents, checks and outcome history. Foreign projects return 404.
     *
     * @response array{data: BreedingProjectResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, BreedingService $service, string $project): JsonResponse
    {
        return ApiResponse::success($this->project($service->find($ctx, $project), $request));
    }

    /**
     * Update an active breeding project
     *
     * Requires breeding.create. Only while the project is active (409 project_not_active otherwise) and its cycle is open.
     * Changing start_date recalculates a reference-derived expectation from the stored snapshot; a manual expectation is kept
     * (validated against the new start) unless revert_to_reference is true. Workflow, cycle and the biological reference never change.
     */
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"project_not_active", request_id:string}')]
    public function update(UpdateBreedingProjectRequest $request, FarmContext $ctx, BreedingService $service, string $project): JsonResponse
    {
        return ApiResponse::success($this->project($service->update($ctx, $project, $request->validated()), $request), message: 'Breeding project updated.');
    }

    /**
     * Record a pregnancy check or incubation candling
     *
     * Requires breeding.create. Append-only. fertile_count applies to incubation only and cannot exceed eggs_set.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\BreedingProjectResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"project_not_active", request_id:string}')]
    public function check(StoreBreedingCheckRequest $request, FarmContext $ctx, BreedingService $service, string $project): JsonResponse
    {
        return ApiResponse::success($this->project($service->addCheck($ctx, $project, $request->validated()), $request), message: 'Check recorded.', status: 201);
    }

    /**
     * Cancel an active breeding project
     *
     * Requires breeding.create. The project is kept (never deleted) with its reason; it can no longer receive checks or outcomes.
     */
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"project_not_active", request_id:string}')]
    public function cancel(CancelBreedingProjectRequest $request, FarmContext $ctx, BreedingService $service, string $project): JsonResponse
    {
        return ApiResponse::success($this->project($service->cancel($ctx, $project, $request->validated()), $request), message: 'Breeding project cancelled.');
    }

    /**
     * Record the actual outcome
     *
     * Requires breeding.create; a correction (corrects_outcome_id) additionally needs breeding.reverse. Expected offspring and
     * eggs set never change population. Live offspring (live_count > 0) automatically append ONE Phase 8 operational record
     * (type breeding_outcome, population_delta = +live_count) and its population movement in the same transaction.
     * Example: 50 eggs set, expected 40, 37 hatched -> population +37. live_count 0 adds nothing; there is no client flag. The required idempotency_key
     * is farm-wide: an identical retry returns the original outcome (201) with no second movement, a changed payload is 409.
     * One effective outcome per project (409 project_not_active); to correct, reverse then record a replacement.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\BreedingOutcomeResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"project_not_active"|"idempotency_conflict"|"invalid_correction"|"insufficient_population", request_id:string}')]
    public function outcome(StoreBreedingOutcomeRequest $request, FarmContext $ctx, BreedingService $service, string $project): JsonResponse
    {
        return ApiResponse::success((new BreedingOutcomeResource($service->recordOutcome($ctx, $project, $request->validated())))->resolve($request), message: 'Outcome recorded.', status: 201);
    }

    /**
     * Reverse a breeding outcome without rewriting history
     *
     * Requires breeding.reverse. Appends a reversal outcome and, when the original added population, one compensating Phase 8
     * record (409 insufficient_population when that would make the dated ledger negative). The project becomes active again
     * so a replacement outcome (corrects_outcome_id) can be recorded. Cannot reverse a reversal or reverse twice.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\BreedingOutcomeResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"outcome_already_reversed"|"idempotency_conflict"|"insufficient_population", request_id:string}')]
    public function reverseOutcome(ReverseBreedingOutcomeRequest $request, FarmContext $ctx, BreedingService $service, string $project, string $outcome): JsonResponse
    {
        return ApiResponse::success((new BreedingOutcomeResource($service->reverseOutcome($ctx, $project, $outcome, $request->validated())))->resolve($request), message: 'Reversal recorded.', status: 201);
    }

    /**
     * Project milestones
     *
     * Requires breeding.view. A derived timeline: started, expected_outcome (exact date or from/to window; status not_estimated
     * when none), checks and the actual outcome. Stored expectations only; reminders and tasks belong to a later phase.
     *
     * @response array{data: array<int, array{code: string, date: string|null, from: string|null, to: string|null, status: string, result?: string}>, meta: object, message: null}
     */
    public function milestones(FarmContext $ctx, BreedingService $service, string $project): JsonResponse
    {
        return ApiResponse::success($service->milestones($ctx, $project));
    }
}
