<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\StoreShopRequest;
use App\Http\Requests\Marketplace\UpdateShopContactRequest;
use App\Http\Requests\Marketplace\UpdateShopRequest;
use App\Http\Resources\Marketplace\ShopContactResource;
use App\Http\Resources\Marketplace\ShopResource;
use App\Services\Marketplace\MarketplaceShopService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seller shop management. Needs sign-in and a verified email only: NO farm and NO farm onboarding (marketplace-only sellers). Every
 * `{shop}` is resolved through the caller's shop membership - a shop the caller does not belong to answers 404 - and the shop role then
 * grants or denies the action (`403`). A new shop is a private `draft` until a platform admin approves it.
 */
class MarketplaceShopController extends Controller
{
    /**
     * My shops
     *
     * Every shop the caller is a member of (any role), newest first, each with `viewer.role` and `viewer.permissions`.
     *
     * @response array{data: ShopResource[], meta: object, message: string|null}
     */
    public function mine(Request $request, MarketplaceShopService $shops): JsonResponse
    {
        return ApiResponse::success(ShopResource::collection($shops->mine($request->user()))->resolve($request));
    }

    /**
     * Create a shop
     *
     * Creates a `draft` shop and makes the caller its `owner`. Works for marketplace-only sellers (no farm). Optional `farm_id` links the shop to a
     * farm: the caller must be an active member of it with the `marketplace.manage` farm permission (Owner, Manager). The link is immutable and
     * a farm can have one shop. `slug` and `reference` (SHP-YYYY-NNNNN) are generated.
     *
     * Errors: `401`, `403 email_verification_required`, `403` (farm member without `marketplace.manage`), `409 shop_limit_reached` (platform setting
     * `marketplace_max_shops_per_user`, default 3), `409 farm_shop_exists`, `422` (validation; `name` already used by one of your shops;
     * `farm_id` not a farm you belong to). Audited as `marketplace.shop_created`.
     */
    #[Response(status: 409, description: 'Conflict', type: 'array{message: string, code: string, request_id: string}')]
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\ShopResource, meta: object, message: string|null}')]
    public function store(StoreShopRequest $request, MarketplaceShopService $shops): JsonResponse
    {
        return ApiResponse::success((new ShopResource($shops->create($request->user(), $request->validated())))->resolve($request), message: 'Shop created.', status: 201);
    }

    /**
     * Show my shop
     *
     * Any member. `404` for a shop the caller does not belong to. Never includes private contact values (see the contact endpoint).
     *
     * @response array{data: ShopResource, meta: object, message: string|null}
     */
    public function show(Request $request, string $shop, MarketplaceShopService $shops): JsonResponse
    {
        return ApiResponse::success((new ShopResource($shops->show($request->user(), $shop)))->resolve($request));
    }

    /**
     * Update the public profile
     *
     * Needs `shop.update` (owner, manager). Partial update of name, tagline, description, seller type, categories and public location. The `slug`
     * never changes. An active shop stays public while edited (post-moderation; admins can suspend). `409 shop_suspended` while suspended.
     * Audited as `marketplace.shop_updated` (field names only).
     *
     * @response array{data: ShopResource, meta: object, message: string|null}
     */
    public function update(UpdateShopRequest $request, string $shop, MarketplaceShopService $shops): JsonResponse
    {
        return ApiResponse::success((new ShopResource($shops->updateProfile($request->user(), $shop, $request->validated())))->resolve($request));
    }

    /**
     * Get private contact settings
     *
     * Needs `shop.manage_contact` (owner, manager). These values are PRIVATE: no public endpoint ever returns them.
     *
     * @response array{data: ShopContactResource, meta: object, message: string|null}
     */
    public function contact(Request $request, string $shop, MarketplaceShopService $shops): JsonResponse
    {
        return ApiResponse::success((new ShopContactResource($shops->contact($request->user(), $shop)))->resolve($request));
    }

    /**
     * Update private contact settings
     *
     * Needs `shop.manage_contact`. Partial update; send `null` to clear a field. A `preferred_contact_method` of phone, whatsapp or email needs that
     * channel configured (`422`). `409 shop_suspended` while suspended. Audited as `marketplace.shop_contact_updated` (field names only, never values).
     *
     * @response array{data: ShopContactResource, meta: object, message: string|null}
     */
    public function updateContact(UpdateShopContactRequest $request, string $shop, MarketplaceShopService $shops): JsonResponse
    {
        return ApiResponse::success((new ShopContactResource($shops->updateContact($request->user(), $shop, $request->validated())))->resolve($request));
    }

    /**
     * Submit for review
     *
     * Needs `shop.manage_lifecycle` (owner). `draft` or `rejected` -> `pending_review`. The profile must have a description of at least 20 characters,
     * state, city, at least one category and one private contact channel, otherwise `422 shop_incomplete` with `details.missing`.
     * `409 invalid_shop_state` / `409 shop_suspended`. Audited as `marketplace.shop_submitted`. The shop is still NOT public.
     *
     * @response array{data: ShopResource, meta: object, message: string|null}
     */
    public function submit(Request $request, string $shop, MarketplaceShopService $shops): JsonResponse
    {
        return ApiResponse::success((new ShopResource($shops->submit($request->user(), $shop)))->resolve($request));
    }

    /**
     * Close the shop
     *
     * Needs `shop.manage_lifecycle`. `active` -> `closed`: removed from public discovery until reopened. `409 invalid_shop_state`. Audited as `marketplace.shop_closed`.
     *
     * @response array{data: ShopResource, meta: object, message: string|null}
     */
    public function close(Request $request, string $shop, MarketplaceShopService $shops): JsonResponse
    {
        return ApiResponse::success((new ShopResource($shops->close($request->user(), $shop)))->resolve($request));
    }

    /**
     * Reopen the shop
     *
     * Needs `shop.manage_lifecycle`. `closed` -> `active` (no new review: only an approved shop can be closed). A suspended shop cannot be reopened
     * by the seller (`409 shop_suspended`). Audited as `marketplace.shop_reopened`.
     *
     * @response array{data: ShopResource, meta: object, message: string|null}
     */
    public function reopen(Request $request, string $shop, MarketplaceShopService $shops): JsonResponse
    {
        return ApiResponse::success((new ShopResource($shops->reopen($request->user(), $shop)))->resolve($request));
    }

    /**
     * Request verification
     *
     * Needs `shop.manage_lifecycle`. An `active` shop with `verification.status` `unverified` or `rejected` becomes `pending`; a platform admin decides.
     * `409 invalid_shop_state`, `409 verification_not_requestable`. Audited as `marketplace.verification_requested`.
     *
     * @response array{data: ShopResource, meta: object, message: string|null}
     */
    public function requestVerification(Request $request, string $shop, MarketplaceShopService $shops): JsonResponse
    {
        return ApiResponse::success((new ShopResource($shops->requestVerification($request->user(), $shop)))->resolve($request));
    }
}
