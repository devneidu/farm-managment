<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceCatalogImage;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingImage;
use App\Support\Api\ApiHttpException;
use App\Support\Api\ApiRoute;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Listing images, in two kinds that are never confused:
 *  - ILLUSTRATIVE catalogue images: reusable rows referenced by id (never copied per listing), always flagged `illustrative: true`;
 *  - SELLER photos: validated, decoded and RE-ENCODED (which drops EXIF/GPS and any appended payload), stored under a random name on the private
 *    marketplace disk and served only through the API.
 * Nothing here ever accepts or fetches a remote URL. An image-less listing is valid: it resolves to a placeholder indicator.
 */
class MarketplaceImageService
{
    /** @var Collection<int, MarketplaceCatalogImage>|null */
    private ?Collection $catalogue = null;

    public function disk(): string
    {
        return config('marketplace.images.disk');
    }

    // ------------------------------------------------------------------ catalogue

    /** @return Collection<int, MarketplaceCatalogImage> active catalogue rows that have an asset; rows still awaiting an asset are invisible */
    public function available(): Collection
    {
        return $this->catalogue ??= MarketplaceCatalogImage::available()->orderBy('sort_order')->orderBy('code')->get();
    }

    public function forgetCache(): void
    {
        $this->catalogue = null;
    }

    /** A catalogue image a seller may pick: active and backed by an asset. Anything else answers like an unknown id. */
    public function pickable(string $id): ?MarketplaceCatalogImage
    {
        return MarketplaceCatalogImage::available()->whereKey($id)->first();
    }

    // ------------------------------------------------------------------ resolution

    /**
     * The image a listing shows, in this order: the seller's primary photo, the catalogue image the seller chose, a catalogue image matching
     * the product name, one matching the species / crop, the category fallback, and finally a placeholder indicator (no url).
     *
     * @return array{source: string, kind: string|null, illustrative: bool, has_image: bool, url: string|null, alt_text: string, placeholder_kind: string|null}
     */
    public function resolve(MarketplaceListing $listing, string $audience): array
    {
        $photo = $listing->images->first();
        if ($photo !== null) {
            return ['source' => 'seller', 'kind' => 'seller', 'illustrative' => false, 'has_image' => true, 'url' => $this->photoUrl($photo, $audience), 'alt_text' => $photo->alt_text ?: $listing->title, 'placeholder_kind' => null];
        }
        $catalogue = $this->available();
        $chosen = $listing->catalog_image_id ? $catalogue->firstWhere('id', $listing->catalog_image_id) : null;
        $source = 'catalogue';
        if ($chosen === null) {
            $name = $listing->custom_product_name ? Str::lower(trim($listing->custom_product_name)) : null;
            $byName = $name ? $catalogue->first(fn ($c) => ! $c->is_kind_fallback && $c->product_kind === $listing->product_kind && Str::lower($c->label) === $name) : null;
            $byMaster = $catalogue->first(fn ($c) => ! $c->is_kind_fallback && $c->product_kind === $listing->product_kind
                && (($listing->species_id && $c->species_id === $listing->species_id) || ($listing->crop_type_id && $c->crop_type_id === $listing->crop_type_id)));
            $chosen = $byName ?? $byMaster;
        }
        if ($chosen === null) {
            $chosen = $catalogue->first(fn ($c) => $c->is_kind_fallback && $c->product_kind === $listing->product_kind);
            $source = 'fallback';
        }
        if ($chosen !== null) {
            return ['source' => $source, 'kind' => 'catalogue', 'illustrative' => true, 'has_image' => true, 'url' => ApiRoute::url('/public/marketplace/catalogue-images/'.$chosen->code), 'alt_text' => $chosen->alt_text, 'placeholder_kind' => null];
        }

        return ['source' => 'placeholder', 'kind' => null, 'illustrative' => true, 'has_image' => false, 'url' => null, 'alt_text' => $listing->title, 'placeholder_kind' => $listing->product_kind];
    }

    /** @param  'public'|'member'|'admin'  $audience who will fetch the file: anyone (published only), a shop member (drafts too), a platform admin */
    public function photoUrl(MarketplaceListingImage $image, string $audience): string
    {
        return ApiRoute::url(match ($audience) {
            'public' => '/public/marketplace/images/'.$image->id,
            'admin' => '/platform-admin/marketplace/listings/'.$image->listing_id.'/images/'.$image->id.'/file',
            default => '/marketplace/shops/'.$image->shop_id.'/listings/'.$image->listing_id.'/images/'.$image->id.'/file',
        });
    }

    // ------------------------------------------------------------------ seller photos

    /**
     * Validates, re-encodes and stores one photo. The caller holds the listing row lock, so the count cannot race. Returns the existing row when
     * the same picture (after re-encoding) is already on the listing.
     */
    public function store(MarketplaceListing $listing, UploadedFile $file, ?string $altText, string $userId): MarketplaceListingImage
    {
        $cfg = config('marketplace.images');
        [$bytes, $mime, $width, $height] = $this->reencode($file, $cfg);

        $hash = hash('sha256', $bytes);
        if ($existing = $listing->images()->where('sha256', $hash)->first()) {
            return $existing;
        }
        if ($listing->images()->count() >= $cfg['max_per_listing']) {
            throw new ApiHttpException(409, 'image_limit_reached', 'This listing already has the maximum number of photos.', details: ['limit' => (int) $cfg['max_per_listing']]);
        }

        $path = 'listings/'.$listing->shop_id.'/'.$listing->id.'/'.Str::uuid7().'.'.$cfg['mime_types'][$mime];
        if (! Storage::disk($this->disk())->put($path, $bytes, 'private')) {
            throw new ApiHttpException(503, 'image_storage_failed', 'The photo could not be stored.');
        }

        try {
            return MarketplaceListingImage::create([
                'listing_id' => $listing->id, 'shop_id' => $listing->shop_id, 'path' => $path, 'original_name' => $this->displayName($file),
                'mime_type' => $mime, 'size' => strlen($bytes), 'width' => $width, 'height' => $height, 'sha256' => $hash,
                'position' => (int) $listing->images()->max('position') + ($listing->images()->exists() ? 1 : 0),
                'alt_text' => $altText, 'uploaded_by' => $userId,
            ]);
        } catch (\Throwable $e) {
            Storage::disk($this->disk())->delete($path);
            throw $e;
        }
    }

    public function remove(MarketplaceListingImage $image): void
    {
        $listing = $image->listing;
        $path = $image->path;
        $image->delete();
        $this->renumber($listing);
        Storage::disk($this->disk())->delete($path);
    }

    /**
     * Deletes every photo ROW of a listing (inside the caller's transaction) and returns the file paths that belonged to it. Only rows owned by this
     * listing are touched, and only paths inside its own storage folder are returned, so a stray or shared path can never be queued for deletion.
     *
     * @return list<string>
     */
    public function detachAll(MarketplaceListing $listing): array
    {
        $own = 'listings/'.$listing->shop_id.'/'.$listing->id.'/';
        $images = MarketplaceListingImage::where('listing_id', $listing->id)->get();
        $paths = $images->pluck('path')->filter(fn ($p) => is_string($p) && str_starts_with($p, $own) && ! str_contains($p, '..'))->values()->all();
        MarketplaceListingImage::where('listing_id', $listing->id)->delete();

        return $paths;
    }

    /** Best-effort removal AFTER the database commit: a storage failure is logged and leaves an orphan file, never a broken delete or a dangling row. */
    public function purgeFiles(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk($this->disk())->delete($path);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** Moves an image to `$position` (0-based) and renumbers the rest contiguously. */
    public function move(MarketplaceListingImage $image, int $position): void
    {
        $ids = $image->listing->images()->pluck('id')->reject(fn ($id) => $id === $image->id)->values()->all();
        array_splice($ids, max(0, min($position, count($ids))), 0, [$image->id]);
        foreach ($ids as $i => $id) {
            MarketplaceListingImage::whereKey($id)->update(['position' => $i]);
        }
    }

    public function renumber(MarketplaceListing $listing): void
    {
        foreach ($listing->images()->pluck('id') as $i => $id) {
            MarketplaceListingImage::whereKey($id)->update(['position' => $i]);
        }
    }

    // ------------------------------------------------------------------ serving

    /** Streams a stored file through the application. Never a public disk URL. */
    public function respond(string $path, string $mime): StreamedResponse
    {
        $disk = Storage::disk($this->disk());
        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, null, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age='.(int) config('marketplace.images.cache_seconds'),
        ]);
    }

    // ------------------------------------------------------------------ internals

    /** @return array{0: string, 1: string, 2: int, 3: int} bytes, mime, width, height of the re-encoded image */
    private function reencode(UploadedFile $file, array $cfg): array
    {
        $path = $file->getRealPath();
        $info = $path ? @getimagesize($path) : false;
        $mime = $info['mime'] ?? null;
        if ($info === false || ! isset($cfg['mime_types'][$mime])) {
            throw ValidationException::withMessages(['image' => 'The file must be a JPEG, PNG or WebP image.']);
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || max($w, $h) > $cfg['max_input_dimension'] || $w * $h > $cfg['max_pixels']) {
            throw ValidationException::withMessages(['image' => "The image is too large (at most {$cfg['max_input_dimension']} pixels on its longest side)."]);
        }
        $source = @imagecreatefromstring((string) file_get_contents($path));
        if ($source === false) {
            throw ValidationException::withMessages(['image' => 'The image could not be read.']);
        }
        $source = $this->orient($source, $mime, $path);

        $w = imagesx($source);
        $h = imagesy($source);
        $longest = max($w, $h);
        if ($longest > $cfg['store_dimension']) {
            $scale = $cfg['store_dimension'] / $longest;
            $scaled = imagescale($source, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
            if ($scaled !== false) {
                $source = $scaled;
                $w = imagesx($source);
                $h = imagesy($source);
            }
        }

        ob_start();
        match ($mime) {
            'image/png' => (function () use ($source) {
                imagealphablending($source, false);
                imagesavealpha($source, true);
                imagepng($source, null, 6);
            })(),
            'image/webp' => (function () use ($source) {
                imagealphablending($source, false);
                imagesavealpha($source, true);
                imagewebp($source, null, 82);
            })(),
            default => imagejpeg($source, null, 85),
        };
        $bytes = (string) ob_get_clean();
        if ($bytes === '') {
            throw ValidationException::withMessages(['image' => 'The image could not be processed.']);
        }

        return [$bytes, $mime, $w, $h];
    }

    /** Applies the EXIF orientation before the metadata is dropped, so a phone photo is not stored sideways. */
    private function orient(\GdImage $image, string $mime, string $path): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = @exif_read_data($path)['Orientation'] ?? 1;
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private function displayName(UploadedFile $file): string
    {
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 200);
    }
}
