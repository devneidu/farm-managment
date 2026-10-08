<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\UpdateListingImageRequest;
use App\Http\Requests\Marketplace\UploadListingImageRequest;
use App\Http\Resources\Marketplace\ListingImageResource;
use App\Services\Marketplace\MarketplaceImageService;
use App\Services\Marketplace\MarketplaceListingService;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A listing's own photos (up to 6). Same rights as editing the listing: `listing.manage` for a draft, `listing.publish` otherwise. Files are validated,
 * re-encoded (metadata dropped), stored on the private marketplace disk under a random name and served only through the API.
 */
class MarketplaceListingImageController extends Controller
{
    /**
     * Upload a photo
     *
     * `multipart/form-data` with `image` (JPEG, PNG or WebP, at most 5 MB, at most 4096 px on the longest side) and optional `alt_text`. The file is decoded
     * and re-encoded server-side, so EXIF/GPS data and appended payloads are removed; it is stored under a random name. Remote URLs are never accepted.
     * The first photo is the primary one (`position` 0). Uploading the same picture again returns the existing photo. `409 image_limit_reached` (6),
     * `422 image` (not an image / too large), `409 listing_restricted`, `409 invalid_listing_state` (archived), `409 shop_suspended`. Audited as
     * `marketplace.listing_image_added`.
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\Marketplace\ListingImageResource, meta: object, message: string|null}')]
    #[Response(status: 200, description: 'The same picture was already on the listing', type: 'array{data: \App\Http\Resources\Marketplace\ListingImageResource, meta: object, message: string|null}')]
    public function store(UploadListingImageRequest $request, string $shop, string $listing, MarketplaceListingService $listings): JsonResponse
    {
        $image = $listings->addImage($request->user(), $shop, $listing, $request->file('image'), $request->validated('alt_text'));

        return ApiResponse::success((new ListingImageResource($image))->resolve($request), message: 'Photo added.', status: $image->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Update a photo
     *
     * Change `alt_text` and/or move the photo to `position` (0 = primary); the others are renumbered. A photo of another listing answers `404`.
     *
     * @response array{data: ListingImageResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Conflict (see the endpoint description for the `code`)', type: 'array{message: string, code: string, request_id: string, details?: object}')]
    public function update(UpdateListingImageRequest $request, string $shop, string $listing, string $image, MarketplaceListingService $listings): JsonResponse
    {
        return ApiResponse::success((new ListingImageResource($listings->updateImage($request->user(), $shop, $listing, $image, $request->validated())))->resolve($request));
    }

    /**
     * Delete a photo
     *
     * Removes the photo row and its stored file. `404` for a photo that is not on this listing of this shop. Audited as `marketplace.listing_image_removed`.
     */
    public function destroy(Request $request, string $shop, string $listing, string $image, MarketplaceListingService $listings): JsonResponse
    {
        $listings->removeImage($request->user(), $shop, $listing, $image);

        return ApiResponse::success(null, message: 'Photo removed.');
    }

    /**
     * Fetch a photo file
     *
     * For shop members (drafts included): streams the stored image. `404` for non-members and for photos of other listings. Use the `url` from the listing.
     */
    public function file(Request $request, string $shop, string $listing, string $image, MarketplaceListingService $listings, MarketplaceImageService $images): StreamedResponse
    {
        $row = $listings->memberImage($request->user(), $shop, $listing, $image);

        return $images->respond($row->path, $row->mime_type);
    }
}
