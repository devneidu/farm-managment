<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Enums\ShopRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\AddShopMemberRequest;
use App\Http\Requests\Marketplace\UpdateShopMemberRequest;
use App\Http\Resources\Marketplace\ShopMemberResource;
use App\Services\Marketplace\MarketplaceShopService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Shop members and their shop-scoped roles. All actions need `shop.manage_members` (owner only). The owner is never editable. */
class MarketplaceShopMemberController extends Controller
{
    /**
     * List shop members
     *
     * Owner only (`403` for manager/staff, `404` for non-members).
     *
     * @response array{data: ShopMemberResource[], meta: object, message: string|null}
     */
    public function index(Request $request, string $shop, MarketplaceShopService $shops): JsonResponse
    {
        return ApiResponse::success(ShopMemberResource::collection($shops->members($request->user(), $shop))->resolve($request));
    }

    /**
     * Add a shop member
     *
     * Owner only. Adds an EXISTING, verified, active account by email as `manager` or `staff`. An unknown, unverified or suspended account all answer
     * the same `422` on `email` (account existence is not revealed). `409 already_member`, `409 shop_suspended`. Audited as `marketplace.member_added`.
     */
    #[Response(status: 409, description: 'Conflict', type: 'array{message: string, code: string, request_id: string}')]
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\ShopMemberResource, meta: object, message: string|null}')]
    public function store(AddShopMemberRequest $request, string $shop, MarketplaceShopService $shops): JsonResponse
    {
        $member = $shops->addMember($request->user(), $shop, $request->validated('email'), ShopRole::from($request->validated('role')));

        return ApiResponse::success((new ShopMemberResource($member))->resolve($request), message: 'Member added.', status: 201);
    }

    /**
     * Change a member's role
     *
     * Owner only. `409 owner_protected` for the owner. Audited as `marketplace.member_role_changed`.
     *
     * @response array{data: ShopMemberResource, meta: object, message: string|null}
     */
    public function update(UpdateShopMemberRequest $request, string $shop, string $member, MarketplaceShopService $shops): JsonResponse
    {
        $updated = $shops->changeMemberRole($request->user(), $shop, $member, ShopRole::from($request->validated('role')));

        return ApiResponse::success((new ShopMemberResource($updated))->resolve($request));
    }

    /**
     * Remove a member
     *
     * Owner only. `409 owner_protected` for the owner. Audited as `marketplace.member_removed`.
     *
     * @response array{data: null, meta: object, message: string|null}
     */
    public function destroy(Request $request, string $shop, string $member, MarketplaceShopService $shops): JsonResponse
    {
        $shops->removeMember($request->user(), $shop, $member);

        return ApiResponse::success(null, message: 'Member removed.');
    }
}
