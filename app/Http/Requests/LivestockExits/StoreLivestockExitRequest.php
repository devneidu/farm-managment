<?php

namespace App\Http\Requests\LivestockExits;

use App\Models\Payment;
use App\Rules\MoneyAmount;
use App\Rules\PositiveWholeCount;
use App\Services\Records\LivestockExitService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLivestockExitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $decimal = ['regex:/^\d{1,9}(\.\d{1,3})?$/', 'numeric', 'gt:0'];

        return [
            /** The open livestock batch the animals leave. */
            'production_cycle_id' => ['required', 'uuid'],
            /** What happened to the animals. */
            'type' => ['required', Rule::in(LivestockExitService::TYPES)],
            /** Whole animals. Never more than the batch holds on that date (409 insufficient_population). */
            'head_count' => ['required', new PositiveWholeCount],
            /** When it happened, with an explicit offset; between the batch start and now. */
            'recorded_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            /** One key for the whole request; every internal key is derived from it, so a retry never duplicates anything. */
            'idempotency_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            /** sold: price per animal, a string with at most two decimals. */
            'unit_price' => ['required_if:type,sold', 'prohibited_unless:type,sold', new MoneyAmount],
            /** sold: the buyer (optional). Reuses an active contact with the same name or creates a customer. */
            'buyer' => ['sometimes', 'nullable', 'array:name,phone', 'prohibited_unless:type,sold'],
            'buyer.name' => ['required_with:buyer', 'string', 'max:150'],
            'buyer.phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            /** sold: money received now (optional). The same block as POST /sales. */
            'payment' => ['sometimes', 'nullable', 'array:amount,method,received_on', 'prohibited_unless:type,sold'],
            'payment.amount' => ['required_with:payment', new MoneyAmount],
            'payment.method' => ['required_with:payment', Rule::in(Payment::METHODS)],
            'payment.received_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            /** died: defaults to "Unknown". */
            'cause' => ['sometimes', 'nullable', 'string', 'max:500', 'prohibited_unless:type,died'],
            /** slaughtered: kilograms of meat that go into the store. */
            'meat_weight_kg' => ['required_if:type,slaughtered', 'prohibited_unless:type,slaughtered', ...$decimal],
            /** slaughtered: live weight before slaughter, for the farmer's own records. */
            'live_weight_kg' => ['sometimes', 'nullable', 'prohibited_unless:type,slaughtered', ...$decimal],
            /** slaughtered: which store receives the meat. Optional when the farm has exactly one active store. */
            'storage_location_id' => ['sometimes', 'nullable', 'uuid', 'prohibited_unless:type,slaughtered'],
            /** culled: why. */
            'reason' => ['required_if:type,culled', 'prohibited_unless:type,culled', Rule::in(LivestockExitService::CULL_REASONS)],
            /** given_away: who received them (optional). */
            'given_to' => ['sometimes', 'nullable', 'string', 'max:200', 'prohibited_unless:type,given_away'],
            /** moved: the open batch of the same species that receives them. */
            'destination_cycle_id' => ['required_if:type,moved', 'prohibited_unless:type,moved', 'uuid'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
