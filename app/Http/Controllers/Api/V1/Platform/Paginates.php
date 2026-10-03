<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

trait Paginates
{
    /** Standard list envelope; `$items` are already serialised. */
    protected function page(LengthAwarePaginator $page, array $items): JsonResponse
    {
        return ApiResponse::success($items, ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }
}
