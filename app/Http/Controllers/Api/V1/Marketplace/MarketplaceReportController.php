<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Api\V1\Platform\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\MarketplaceReportRequest;
use App\Http\Requests\Marketplace\StoreContentReportRequest;
use App\Http\Resources\Marketplace\MarketplaceReportResource;
use App\Services\Marketplace\MarketplaceReportService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceReportController extends Controller
{
    use Paginates;

    /** File a confidential shop or listing complaint; duplicates return the active case. */
    #[Response(status: 200, description: 'OK', type: 'array{data: \App\Http\Resources\Marketplace\MarketplaceReportResource, meta: object, message: string|null}')]
    #[Response(status: 201, description: 'New complaint', type: 'array{data: \App\Http\Resources\Marketplace\MarketplaceReportResource, meta: object, message: string|null}')]
    public function store(StoreContentReportRequest $request, string $target, string $slug, MarketplaceReportService $reports): JsonResponse
    {
        [$r, $created] = $reports->file($request->user(), $target, $slug, $request->validated());

        return ApiResponse::success((new MarketplaceReportResource($r))->resolve($request), status: $created ? 201 : 200);
    }

    /** List the caller's content or deal reports. `type` defaults to content. */
    #[Response(status: 200, description: 'OK', type: 'array{data: \App\Http\Resources\Marketplace\MarketplaceReportResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}')]
    public function index(MarketplaceReportRequest $request, MarketplaceReportService $reports): JsonResponse
    {
        $f = $request->validated();
        $type = $f['type'] ?? 'content';
        $page = $reports->query($type)->where('reporter_id', $request->user()->id)->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))->orderByDesc('created_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);

        return $this->page($page, $page->getCollection()->map(fn ($r) => (new MarketplaceReportResource($r))->resolve($request))->all());
    }

    /** Read an owned complaint and its outcome; another reporter's case returns 404. */
    #[Response(status: 200, description: 'OK', type: 'array{data: \App\Http\Resources\Marketplace\MarketplaceReportResource, meta: object, message: string|null}')]
    public function show(Request $request, string $type, string $report, MarketplaceReportService $reports): JsonResponse
    {
        return ApiResponse::success((new MarketplaceReportResource($reports->show($type, $report, $request->user())))->resolve($request));
    }
}
