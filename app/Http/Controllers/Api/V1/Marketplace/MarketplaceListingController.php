<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Api\V1\Platform\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\EligibleInventoryRequest;
use App\Http\Requests\Marketplace\ListingTransitionRequest;
use App\Http\Requests\Marketplace\ListShopListingsRequest;
use App\Http\Requests\Marketplace\PricePreviewRequest;
use App\Http\Requests\Marketplace\StoreListingRequest;
use App\Http\Requests\Marketplace\UpdateListingRequest;
use App\Http\Resources\Marketplace\ListingResource;
use App\Services\Marketplace\MarketplaceInventoryLink;
use App\Services\Marketplace\MarketplaceListingService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seller listing management, always inside a shop the caller belongs to (`404` for a shop or listing that is not theirs). Needs sign-in and a verified
 * email only - no farm. Shop role permissions: `listing.view` (all members), `listing.manage` (create / edit DRAFTS: owner, manager, staff) and
 * `listing.publish` (publish, pause, archive, restore, edit non-drafts: owner, manager). A suspended shop is frozen (`409 shop_suspended`).
 */
class MarketplaceListingController extends Controller
{
    use Paginates;

    /**
     * List my shop's listings
     *
     * Needs `listing.view`. Every state, newest first. Filters: `status`, `product_kind`, `q` (title, reference, product name). Paginated.
     *
     * @response array{data: ListingResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function index(ListShopListingsRequest $request, string $shop, MarketplaceListingService $listings): JsonResponse
    {
        $page = $listings->list($request->user(), $shop, $request->validated());

        return $this->page($page, ListingResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Create a listing (draft)
     *
     * Needs `listing.manage` (owner, manager, staff). Creates a private `draft`. Validation: `product_kind` + product identity (species / crop type from the
     * existing master data, or `custom_product_name`; required for `other`); `unit` must be allowed for the kind; `unit_price` positive naira with at
     * most 2 decimals per ONE unit; `available_quantity` > 0 honouring the unit (whole numbers for head / egg / piece / tuber, otherwise at most the unit's
     * decimal places); optional `min_order_quantity` <= available; container units (bag, sack, crate, tray, carton, bottle, basket) REQUIRE `package`
     * (what one holds, seller-declared, never converted); `fulfilment` pickup | seller_delivery | both. The listing location defaults from the shop.
     * Optional `catalog_image_id` and (farm-backed shops) `inventory_item_id`. An image is NOT required. Money and quantities are decimal strings.
     *
     * Errors: `401`, `403`, `404` (not a member), `409 shop_suspended`, `422` (`unit`, `unit_price`, `available_quantity`, `min_order_quantity`, `package`,
     * `species_id`, `custom_product_name`, `inventory_item_id` ...). Audited as `marketplace.listing_created`.
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\ListingResource, meta: object, message: string|null}')]
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function store(StoreListingRequest $request, string $shop, MarketplaceListingService $listings): JsonResponse
    {
        return ApiResponse::success((new ListingResource($listings->create($request->user(), $shop, $request->validated())))->resolve($request), message: 'Listing created.', status: 201);
    }

    /**
     * Show my listing
     *
     * Needs `listing.view`. Includes the lifecycle `history`, the `version` token, and for a listing linked to farm inventory an `inventory` block (live
     * on-hand next to the declared quantity, `changed_since_link`, `exceeds_stock` when the units match) visible to authorised members only.
     *
     * @response array{data: ListingResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $shop, string $listing, MarketplaceListingService $listings, MarketplaceInventoryLink $inventory): JsonResponse
    {
        $model = $listings->show($request->user(), $shop, $listing);
        $view = $model->inventory_item_id !== null && $request->user()->can('manageListings', $model->shop) ? $this->safeInventory($request, $model, $inventory) : null;

        return ApiResponse::success((new ListingResource($model))->detailed($view)->resolve($request));
    }

    /**
     * Update a listing
     *
     * Partial update. A DRAFT needs `listing.manage`; a published or paused listing needs `listing.publish` (owner, manager) and stays live while
     * edited. Archived listings must be restored first (`409 invalid_listing_state`); restricted ones are frozen (`409 listing_restricted`). Send the
     * `version` you loaded: a different current version answers `409 stale_listing` (`details.current_version`). Changing the unit without restating
     * `package` drops the old package statement. Audited as `marketplace.listing_updated` (field names, plus old/new price and quantity).
     *
     * @response array{data: ListingResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function update(UpdateListingRequest $request, string $shop, string $listing, MarketplaceListingService $listings): JsonResponse
    {
        return ApiResponse::success((new ListingResource($listings->update($request->user(), $shop, $listing, $request->validated())))->resolve($request));
    }

    /**
     * Delete a draft
     *
     * Needs `listing.manage`. Soft delete of a DRAFT only (`409 invalid_listing_state` otherwise - archive a listing that has been public). History and audit
     * records are kept. Audited as `marketplace.listing_deleted`.
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function destroy(Request $request, string $shop, string $listing, MarketplaceListingService $listings): JsonResponse
    {
        $listings->destroy($request->user(), $shop, $listing);

        return ApiResponse::success(null, message: 'Listing deleted.');
    }

    /**
     * Publish
     *
     * Needs `listing.publish` (owner, manager; never staff). `draft` | `paused` -> `published`, immediately and without individual admin approval, but only
     * while the shop is `active` (`409 shop_not_active`) and the listing is complete (`422 listing_incomplete`, `details.missing`: `state`, `pickup_area`,
     * `delivery_coverage`, `delivery_charge`). No image is required. Repeating the call on a published listing succeeds unchanged. `409 listing_restricted`,
     * `409 invalid_listing_state`, `409 stale_listing`. Publishing never reserves or deducts stock. Audited as `marketplace.listing_published`.
     *
     * @response array{data: ListingResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function publish(ListingTransitionRequest $request, string $shop, string $listing, MarketplaceListingService $listings): JsonResponse
    {
        return ApiResponse::success((new ListingResource($listings->publish($request->user(), $shop, $listing, $request->validated('version'))))->resolve($request));
    }

    /**
     * Pause
     *
     * Needs `listing.publish`. `published` -> `paused`: off the public feed, kept as is. Idempotent. Audited as `marketplace.listing_paused`.
     *
     * @response array{data: ListingResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function pause(ListingTransitionRequest $request, string $shop, string $listing, MarketplaceListingService $listings): JsonResponse
    {
        return ApiResponse::success((new ListingResource($listings->pause($request->user(), $shop, $listing, $request->validated('version'))))->resolve($request));
    }

    /**
     * Archive
     *
     * Needs `listing.publish`. `draft` | `published` | `paused` -> `archived`. Idempotent. Audited as `marketplace.listing_archived`.
     *
     * @response array{data: ListingResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function archive(ListingTransitionRequest $request, string $shop, string $listing, MarketplaceListingService $listings): JsonResponse
    {
        return ApiResponse::success((new ListingResource($listings->archive($request->user(), $shop, $listing, $request->validated('version'))))->resolve($request));
    }

    /**
     * Restore
     *
     * Needs `listing.publish`. `archived` -> `draft`; publish it again to make it public. Idempotent. Audited as `marketplace.listing_restored`.
     *
     * @response array{data: ListingResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function restore(ListingTransitionRequest $request, string $shop, string $listing, MarketplaceListingService $listings): JsonResponse
    {
        return ApiResponse::success((new ListingResource($listings->restore($request->user(), $shop, $listing, $request->validated('version'))))->resolve($request));
    }

    /**
     * Price preview
     *
     * Needs `listing.view`. A NON-BINDING estimate of the total for a quantity: `unit_price x quantity` computed with exact decimals and rounded to kobo
     * (`total`, `total_minor`). The quantity must respect the unit's decimal places, the minimum order and the declared available quantity (`422`).
     * Nothing is reserved or agreed.
     *
     * @response array{data: array{quantity: string, unit: string, unit_price: string, currency: string, binding: bool, note: string, total: string, total_minor: int}, meta: object, message: string|null}
     */
    public function preview(PricePreviewRequest $request, string $shop, string $listing, MarketplaceListingService $listings): JsonResponse
    {
        return ApiResponse::success($listings->previewFor($request->user(), $shop, $listing, $request->validated('quantity')));
    }

    /**
     * Eligible inventory
     *
     * For a farm-backed shop only (`422` otherwise), and only for a member who holds `marketplace.manage` and `inventory.view` on the shop's farm (`403`).
     * Lists the farm's active, sellable (produce, feed) items that can back a listing of `product_kind`, with live on-hand. Linking is informational:
     * it never reserves, reduces or posts stock.
     *
     * @response array{data: array<int, array{id: string, name: string, category: string, kind: string, on_hand: array{quantity: string, unit: string}}>, meta: object, message: string|null}
     */
    public function eligibleInventory(EligibleInventoryRequest $request, string $shop, MarketplaceListingService $listings, MarketplaceInventoryLink $inventory): JsonResponse
    {
        $model = $listings->shopFor($request->user(), $shop);

        return ApiResponse::success($inventory->eligibleItems($request->user(), $model, $request->validated('product_kind'))->all());
    }

    /** The inventory block is a courtesy: a member without the farm permissions simply does not get it. */
    private function safeInventory(Request $request, $model, MarketplaceInventoryLink $inventory): ?array
    {
        try {
            $inventory->authorizeFarm($request->user(), $model->shop);
        } catch (\Throwable) {
            return null;
        }

        return $inventory->view($model);
    }
}
