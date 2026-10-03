<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\ListPaymentsRequest;
use App\Http\Requests\Payments\ReversePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Services\Sales\PaymentService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * List payments
     *
     * Requires payment.view. Current farm only, newest received_on first; includes reversal rows (entry_type reversal).
     *
     * @response array{data: PaymentResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function index(ListPaymentsRequest $request, FarmContext $ctx, PaymentService $service): JsonResponse
    {
        $page = $service->list($ctx, $request->validated());

        return ApiResponse::success(PaymentResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Show a payment
     *
     * Requires payment.view.
     *
     * @response array{data: PaymentResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, PaymentService $service, string $payment): JsonResponse
    {
        return ApiResponse::success((new PaymentResource($service->find($ctx, $payment)))->resolve($request));
    }

    /**
     * Reverse a payment without rewriting history
     *
     * Requires payment.reverse. Appends an offsetting payment row (entry_type reversal) and an offsetting income row in the
     * finance ledger; the invoice balance goes back up. Wrong amounts are corrected by reversing and recording a new payment.
     * 409 payment_already_reversed; 409 cycle_closed when the income had been allocated to a cycle that is now closed.
     * Idempotent through idempotency_key.
     */
    #[Response(status: 200, type: 'array{data: \App\Http\Resources\PaymentResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"payment_already_reversed"|"cycle_closed"|"idempotency_conflict", request_id:string}')]
    public function reverse(ReversePaymentRequest $request, FarmContext $ctx, PaymentService $service, string $payment): JsonResponse
    {
        return ApiResponse::success((new PaymentResource($service->reverse($ctx, $payment, $request->validated())))->resolve($request), message: 'Payment reversed.');
    }
}
