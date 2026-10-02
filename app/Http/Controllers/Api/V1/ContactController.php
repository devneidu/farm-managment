<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\ListContactsRequest;
use App\Http\Requests\Contacts\StoreContactRequest;
use App\Http\Requests\Contacts\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Services\Finance\ContactService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * List contacts
     *
     * Requires contact.view. Current farm only; active contacts by default, alphabetical. A contact that is both supplier and
     * customer is one row and appears under either role filter.
     *
     * @response array{data: ContactResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function index(ListContactsRequest $request, FarmContext $ctx, ContactService $service): JsonResponse
    {
        $page = $service->list($ctx, $request->validated());

        return ApiResponse::success(ContactResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Create a contact
     *
     * Requires contact.manage. roles holds supplier and/or customer. Names are unique per farm (case-insensitive): 409
     * contact_exists tells the client to add the missing role to the existing contact instead of creating a duplicate.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\ContactResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"contact_exists", request_id:string}')]
    public function store(StoreContactRequest $request, FarmContext $ctx, ContactService $service): JsonResponse
    {
        return ApiResponse::success((new ContactResource($service->create($ctx, $request->validated())))->resolve($request), message: 'Contact saved.', status: 201);
    }

    /**
     * Show a contact
     *
     * Requires contact.view. Foreign contacts return 404.
     *
     * @response array{data: ContactResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, ContactService $service, string $contact): JsonResponse
    {
        return ApiResponse::success((new ContactResource($service->find($ctx, $contact)))->resolve($request));
    }

    /**
     * Update a contact
     *
     * Requires contact.manage. Add or remove roles, edit details or deactivate (is_active=false). A contact with purchases
     * cannot lose its supplier role (409 contact_in_use); deactivate it instead. Inactive contacts cannot be chosen on new
     * purchases or transactions (409 contact_inactive) but stay on existing documents.
     */
    #[Response(status: 409, type: 'array{message:string, code:"contact_exists"|"contact_in_use", request_id:string}')]
    public function update(UpdateContactRequest $request, FarmContext $ctx, ContactService $service, string $contact): JsonResponse
    {
        return ApiResponse::success((new ContactResource($service->update($ctx, $contact, $request->validated())))->resolve($request), message: 'Contact updated.');
    }
}
