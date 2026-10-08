<?php

namespace App\Services\Marketplace;

use App\Enums\ListingStatus;
use App\Enums\ShopStatus;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingImage;
use App\Models\MarketplaceShop;
use App\Models\Unit;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Api\ApiHttpException;
use App\Support\Measurement\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Seller-side listing management. Everything is reached through the caller's SHOP MEMBERSHIP (a shop the caller does not belong to, or a listing of
 * another shop, answers 404); the shop role then decides the action:
 *  - `listing.view`    read;
 *  - `listing.manage`  create and edit DRAFTS and their photos (staff included);
 *  - `listing.publish` publish, pause, archive, restore and edit anything that is not a draft (owner, manager).
 *
 * Every write runs in one transaction that locks the shop row, then the listing row, and compares the optional `version` token, so two writers cannot
 * interleave. A listing becomes public only while it is `published` AND its shop is active - evaluated when read, so suspending a shop hides its
 * listings at once. Nothing here touches inventory, reserves stock or posts a sale.
 */
class MarketplaceListingService
{
    private const EDITABLE = ['title', 'description', 'product_kind', 'species_id', 'crop_type_id', 'custom_product_name', 'unit', 'unit_price', 'available_quantity',
        'min_order_quantity', 'negotiable', 'package', 'fulfilment', 'pickup_area', 'delivery_coverage', 'dispatch_estimate', 'delivery_charge', 'state', 'city', 'area'];

    public function __construct(
        private MarketplaceShopService $shops,
        private MarketplaceListingRules $rules,
        private MarketplaceInventoryLink $inventory,
        private MarketplaceImageService $images,
        private MarketplaceListingHistory $history,
        private AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ reads

    /** @param  array{status?: string, q?: string, product_kind?: string, per_page?: int}  $f */
    public function list(User $user, string $shopId, array $f): LengthAwarePaginator
    {
        $shop = $this->shops->memberShop($user, $shopId);
        $this->authorize($user, 'viewListings', $shop);
        $like = isset($f['q']) ? '%'.addcslashes($f['q'], '%_\\').'%' : null;

        return MarketplaceListing::query()->where('shop_id', $shop->id)->with($this->relations())
            ->when(isset($f['status']), fn (Builder $q) => $q->where('status', $f['status']))
            ->when(isset($f['product_kind']), fn (Builder $q) => $q->where('product_kind', $f['product_kind']))
            ->when($like, fn (Builder $q) => $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('custom_product_name', 'like', $like)))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($f['per_page'] ?? 20)->through(function (MarketplaceListing $l) use ($shop) {
                $l->setRelation('shop', $shop);

                return $l;
            });
    }

    public function show(User $user, string $shopId, string $listingId): MarketplaceListing
    {
        $shop = $this->shops->memberShop($user, $shopId);
        $this->authorize($user, 'viewListings', $shop);

        return $this->find($shop, $listingId)->load([...$this->relations(), 'events'])->setRelation('shop', $shop);
    }

    // ------------------------------------------------------------------ create / update / delete

    /** @param  array<string, mixed>  $data */
    public function create(User $user, string $shopId, array $data): MarketplaceListing
    {
        // One global lock serialises reference allocation; released after the transaction commits (same pattern as shops).
        $lock = 'marketplace_listing_create';
        DB::select('SELECT GET_LOCK(?, 10)', [$lock]);
        try {
            return DB::transaction(function () use ($user, $shopId, $data) {
                $shop = $this->shops->memberShop($user, $shopId, lock: true);
                $this->authorize($user, 'manageListings', $shop);
                $this->assertShopWritable($shop);

                $effective = $data + ['fulfilment' => 'pickup', 'negotiable' => false, 'package' => null];
                foreach (['state', 'city', 'area'] as $field) {
                    $effective[$field] = array_key_exists($field, $data) ? $data[$field] : $shop->{$field};
                }
                $attributes = $this->rules->resolve($effective) + $this->plain($effective) + $this->extras($user, $shop, $data, $effective['product_kind']);

                $listing = new MarketplaceListing($attributes);
                $listing->forceFill([
                    'reference' => $this->nextReference(), 'slug' => $this->uniqueSlug($data['title']), 'shop_id' => $shop->id, 'created_by' => $user->id,
                    'updated_by' => $user->id, 'status' => ListingStatus::Draft, 'version' => 1, 'quantity_updated_at' => now(),
                ])->save();

                $this->history->record($listing, 'seller', $user->id, 'created', null, ListingStatus::Draft->value);
                $this->audit($user, $shop, $listing, 'marketplace.listing_created', ['product_kind' => $listing->product_kind, 'unit_price' => $listing->unit_price, 'unit' => $effective['unit']]);

                return $listing->load($this->relations())->setRelation('shop', $shop);
            });
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $user, string $shopId, string $listingId, array $data): MarketplaceListing
    {
        return DB::transaction(function () use ($user, $shopId, $listingId, $data) {
            [$shop, $listing] = $this->lockedFor($user, $shopId, $listingId);
            $this->assertEditable($user, $shop, $listing);
            $this->assertVersion($listing, $data['version'] ?? null);

            $effective = $this->effective($listing, array_intersect_key($data, array_flip([...self::EDITABLE, 'inventory_item_id'])));
            $attributes = $this->rules->resolve($effective) + $this->plain($effective);
            if (array_key_exists('inventory_item_id', $data)) {
                $attributes += $this->extras($user, $shop, $data, $effective['product_kind']);
            } elseif ($listing->inventory_item_id !== null && ! in_array($effective['product_kind'], MarketplaceProductCatalogue::INVENTORY_KINDS, true)) {
                $attributes += $this->inventory->unlinked();   // the product kind changed to one that has no stock
            }
            if (array_key_exists('catalog_image_id', $data)) {
                $attributes['catalog_image_id'] = $this->catalogImage($data['catalog_image_id']);
            }

            $before = $listing->only(['unit_price', 'available_quantity', 'min_order_quantity', 'negotiable', 'unit_id', 'fulfilment']);
            $listing->fill($attributes);
            if ($listing->isDirty('available_quantity')) {
                $listing->quantity_updated_at = now();
            }
            $dirty = array_keys($listing->getDirty());
            if ($dirty !== []) {
                $listing->updated_by = $user->id;
                $listing->version = $listing->version + 1;
                $listing->save();
                $this->audit($user, $shop, $listing, 'marketplace.listing_updated', ['fields' => $dirty] + $this->priceChange($before, $listing));
            }

            return $listing->load($this->relations())->setRelation('shop', $shop);
        });
    }

    /**
     * Soft delete of a DRAFT. History and audit rows stay. Anything that has been live is archived instead. The draft's own photo rows are removed with
     * it and, once the delete has committed, so are its uploaded files (only files under this listing's own folder; catalogue assets are never touched).
     */
    public function destroy(User $user, string $shopId, string $listingId): void
    {
        $paths = DB::transaction(function () use ($user, $shopId, $listingId) {
            [$shop, $listing] = $this->lockedFor($user, $shopId, $listingId);
            $this->authorize($user, 'manageListings', $shop);
            $this->assertShopWritable($shop);
            if ($listing->status !== ListingStatus::Draft) {
                throw $this->invalidState($listing, 'Only a draft can be deleted; archive a listing that has been published.');
            }
            $this->history->record($listing, 'seller', $user->id, 'deleted', ListingStatus::Draft->value, null);
            $this->audit($user, $shop, $listing, 'marketplace.listing_deleted');
            $paths = $this->images->detachAll($listing);
            $listing->delete();

            return $paths;
        });
        $this->images->purgeFiles($paths);
    }

    // ------------------------------------------------------------------ lifecycle

    /** draft | paused -> published. Needs an active shop and a complete listing. Repeating it on a published listing succeeds without change. */
    public function publish(User $user, string $shopId, string $listingId, ?int $version): MarketplaceListing
    {
        return $this->transition($user, $shopId, $listingId, $version, ListingStatus::Published, [ListingStatus::Draft, ListingStatus::Paused], 'published', function (MarketplaceListing $l, MarketplaceShop $shop) {
            if ($shop->status !== ShopStatus::Active) {
                throw new ApiHttpException(409, 'shop_not_active', 'Only an approved, open shop can publish listings.', details: ['shop_status' => $shop->status->value]);
            }
            if ($missing = $this->missingForPublish($l)) {
                throw new ApiHttpException(422, 'listing_incomplete', 'Complete the listing before publishing it.', details: ['missing' => $missing]);
            }
            $l->published_at ??= now();
            $l->paused_at = null;
        });
    }

    /** published -> paused: off the public feed, kept as is. */
    public function pause(User $user, string $shopId, string $listingId, ?int $version): MarketplaceListing
    {
        return $this->transition($user, $shopId, $listingId, $version, ListingStatus::Paused, [ListingStatus::Published], 'paused', fn (MarketplaceListing $l) => $l->paused_at = now());
    }

    /** draft | published | paused -> archived (terminal for the public; the seller may restore it to a draft). */
    public function archive(User $user, string $shopId, string $listingId, ?int $version): MarketplaceListing
    {
        return $this->transition($user, $shopId, $listingId, $version, ListingStatus::Archived, [ListingStatus::Draft, ListingStatus::Published, ListingStatus::Paused], 'archived', fn (MarketplaceListing $l) => $l->archived_at = now());
    }

    /** archived -> draft. It must be published again. */
    public function restore(User $user, string $shopId, string $listingId, ?int $version): MarketplaceListing
    {
        return $this->transition($user, $shopId, $listingId, $version, ListingStatus::Draft, [ListingStatus::Archived], 'restored', fn (MarketplaceListing $l) => $l->archived_at = null);
    }

    // ------------------------------------------------------------------ images

    public function addImage(User $user, string $shopId, string $listingId, UploadedFile $file, ?string $altText): MarketplaceListingImage
    {
        return DB::transaction(function () use ($user, $shopId, $listingId, $file, $altText) {
            [$shop, $listing] = $this->lockedFor($user, $shopId, $listingId);
            $this->assertEditable($user, $shop, $listing);

            $image = $this->images->store($listing, $file, $altText, $user->id);
            if ($image->wasRecentlyCreated) {
                $listing->forceFill(['updated_by' => $user->id])->save();   // photos are a sub-resource: they touch the listing but do not move the `version` token of its fields
                $this->audit($user, $shop, $listing, 'marketplace.listing_image_added', ['image_id' => $image->id]);
            }

            return $image;
        });
    }

    /** @param  array{alt_text?: string|null, position?: int}  $data */
    public function updateImage(User $user, string $shopId, string $listingId, string $imageId, array $data): MarketplaceListingImage
    {
        return DB::transaction(function () use ($user, $shopId, $listingId, $imageId, $data) {
            [$shop, $listing] = $this->lockedFor($user, $shopId, $listingId);
            $this->assertEditable($user, $shop, $listing);
            $image = $listing->images()->whereKey($imageId)->firstOrFail();
            if (array_key_exists('alt_text', $data)) {
                $image->update(['alt_text' => $data['alt_text']]);
            }
            if (isset($data['position'])) {
                $this->images->move($image, (int) $data['position']);
            }
            $listing->forceFill(['updated_by' => $user->id])->save();   // photos are a sub-resource: they touch the listing but do not move the `version` token of its fields
            $this->audit($user, $shop, $listing, 'marketplace.listing_image_updated', ['image_id' => $image->id]);

            return $image->refresh();
        });
    }

    public function removeImage(User $user, string $shopId, string $listingId, string $imageId): void
    {
        DB::transaction(function () use ($user, $shopId, $listingId, $imageId) {
            [$shop, $listing] = $this->lockedFor($user, $shopId, $listingId);
            $this->assertEditable($user, $shop, $listing);
            $image = $listing->images()->whereKey($imageId)->firstOrFail();
            $this->images->remove($image);
            $listing->forceFill(['updated_by' => $user->id])->save();   // photos are a sub-resource: they touch the listing but do not move the `version` token of its fields
            $this->audit($user, $shop, $listing, 'marketplace.listing_image_removed', ['image_id' => $imageId]);
        });
    }

    /** A member's own view of a stored photo (drafts included). */
    public function memberImage(User $user, string $shopId, string $listingId, string $imageId): MarketplaceListingImage
    {
        $shop = $this->shops->memberShop($user, $shopId);
        $this->authorize($user, 'viewListings', $shop);

        return $this->find($shop, $listingId)->images()->whereKey($imageId)->firstOrFail();
    }

    // ------------------------------------------------------------------ helpers for controllers

    public function shopFor(User $user, string $shopId): MarketplaceShop
    {
        $shop = $this->shops->memberShop($user, $shopId);
        $this->authorize($user, 'viewListings', $shop);

        return $shop;
    }

    /** Non-binding total for a quantity of this listing (seller preview). */
    public function previewFor(User $user, string $shopId, string $listingId, mixed $quantity): array
    {
        return $this->preview($this->show($user, $shopId, $listingId), $quantity);
    }

    /** @return array<string, mixed> */
    public function preview(MarketplaceListing $listing, mixed $quantity): array
    {
        $listing->loadMissing('unit');
        $qty = $this->rules->quantityFor('quantity', $quantity, $listing->unit);
        $price = Decimal::trim((string) $listing->unit_price);
        if ($listing->min_order_quantity !== null && Decimal::cmp($qty, Decimal::trim((string) $listing->min_order_quantity)) < 0) {
            throw ValidationException::withMessages(['quantity' => 'The quantity is below the minimum order of '.Decimal::trim((string) $listing->min_order_quantity).'.']);
        }
        if (Decimal::cmp($qty, Decimal::trim((string) $listing->available_quantity)) > 0) {
            throw ValidationException::withMessages(['quantity' => 'The quantity exceeds the quantity the seller has declared as available.']);
        }

        return [
            'quantity' => $qty, 'unit' => $listing->unit->code, 'unit_price' => $this->rules->money($price), 'currency' => MarketplaceListingRules::CURRENCY,
            'binding' => false, 'note' => 'An estimate only. Nothing is reserved or agreed by this calculation.',
        ] + $this->rules->total($price, $qty);
    }

    // ------------------------------------------------------------------ internals

    /** @return list<string> */
    private function relations(): array
    {
        return ['unit', 'packageBasisUnit', 'species', 'cropType', 'catalogImage', 'images'];
    }

    private function find(MarketplaceShop $shop, string $listingId, bool $lock = false): MarketplaceListing
    {
        return MarketplaceListing::query()->where('shop_id', $shop->id)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($listingId);
    }

    /** @return array{0: MarketplaceShop, 1: MarketplaceListing} shop row locked first, then the listing row */
    private function lockedFor(User $user, string $shopId, string $listingId): array
    {
        $shop = $this->shops->memberShop($user, $shopId, lock: true);
        $this->authorize($user, 'viewListings', $shop);

        return [$shop, $this->find($shop, $listingId, lock: true)];
    }

    private function authorize(User $user, string $ability, MarketplaceShop $shop): void
    {
        if (! $user->can($ability, $shop)) {
            throw new AuthorizationException;
        }
    }

    private function assertShopWritable(MarketplaceShop $shop): void
    {
        if ($shop->status === ShopStatus::Suspended) {
            throw new ApiHttpException(409, 'shop_suspended', 'This shop is suspended; contact support.');
        }
    }

    /** Drafts: `listing.manage`. Anything else live or paused: `listing.publish`. Archived and restricted listings are frozen. */
    private function assertEditable(User $user, MarketplaceShop $shop, MarketplaceListing $listing): void
    {
        $this->assertShopWritable($shop);
        if ($listing->status === ListingStatus::Restricted) {
            throw new ApiHttpException(409, 'listing_restricted', 'This listing is restricted by the platform and cannot be changed.', details: ['reason' => $listing->restricted_reason]);
        }
        if ($listing->status === ListingStatus::Archived) {
            throw $this->invalidState($listing, 'Restore the listing to a draft before editing it.');
        }
        $this->authorize($user, $listing->status === ListingStatus::Draft ? 'manageListings' : 'publishListings', $shop);
    }

    private function assertVersion(MarketplaceListing $listing, mixed $version): void
    {
        if ($version !== null && (int) $version !== $listing->version) {
            throw new ApiHttpException(409, 'stale_listing', 'The listing changed since you loaded it; reload it and try again.', details: ['current_version' => $listing->version]);
        }
    }

    private function invalidState(MarketplaceListing $listing, string $message): ApiHttpException
    {
        return new ApiHttpException(409, 'invalid_listing_state', $message, details: ['status' => $listing->status->value]);
    }

    /**
     * @param  list<ListingStatus>  $from
     * @param  callable(MarketplaceListing, MarketplaceShop): mixed  $apply
     */
    private function transition(User $user, string $shopId, string $listingId, ?int $version, ListingStatus $to, array $from, string $action, callable $apply): MarketplaceListing
    {
        return DB::transaction(function () use ($user, $shopId, $listingId, $version, $to, $from, $action, $apply) {
            [$shop, $listing] = $this->lockedFor($user, $shopId, $listingId);
            $this->authorize($user, 'publishListings', $shop);
            $this->assertShopWritable($shop);
            if ($listing->status === ListingStatus::Restricted) {
                throw new ApiHttpException(409, 'listing_restricted', 'This listing is restricted by the platform.', details: ['reason' => $listing->restricted_reason]);
            }
            if ($listing->status === $to) {   // idempotent: the requested state already holds
                return $listing->load($this->relations())->setRelation('shop', $shop);
            }
            $this->assertVersion($listing, $version);
            if (! in_array($listing->status, $from, true)) {
                throw $this->invalidState($listing, "This action is not available while the listing is {$listing->status->value}.");
            }
            $before = $listing->status->value;
            $listing->loadMissing($this->relations());
            $apply($listing, $shop);
            $listing->forceFill(['status' => $to, 'updated_by' => $user->id, 'version' => $listing->version + 1])->save();
            $this->history->record($listing, 'seller', $user->id, $action, $before, $to->value);
            $this->audit($user, $shop, $listing, 'marketplace.listing_'.$action, ['from' => $before, 'to' => $to->value]);

            return $listing->setRelation('shop', $shop);
        });
    }

    /** @return list<string> what a listing still lacks before it can go public */
    private function missingForPublish(MarketplaceListing $l): array
    {
        $missing = [];
        if (! $l->state) {
            $missing[] = 'state';
        }
        if (MarketplaceProductCatalogue::offersPickup($l->fulfilment) && ! $l->pickup_area && ! $l->city && ! $l->area) {
            $missing[] = 'pickup_area';
        }
        if (MarketplaceProductCatalogue::offersDelivery($l->fulfilment)) {
            foreach (['delivery_coverage', 'delivery_charge'] as $field) {
                if (empty($l->{$field})) {
                    $missing[] = $field;
                }
            }
        }

        return $missing;
    }

    /** Stored values overlaid with the request, in the vocabulary the rules expect. @return array<string, mixed> */
    private function effective(MarketplaceListing $l, array $data): array
    {
        $l->loadMissing('unit', 'packageBasisUnit');
        $unitChanged = isset($data['unit']) && $data['unit'] !== $l->unit->code;
        $current = [
            'product_kind' => $l->product_kind, 'species_id' => $l->species_id, 'crop_type_id' => $l->crop_type_id, 'custom_product_name' => $l->custom_product_name,
            'unit' => $l->unit->code, 'unit_price' => Decimal::trim((string) $l->unit_price), 'available_quantity' => Decimal::trim((string) $l->available_quantity),
            'min_order_quantity' => $l->min_order_quantity === null ? null : Decimal::trim((string) $l->min_order_quantity),
            'package' => $l->package_quantity === null ? null : ['quantity' => Decimal::trim((string) $l->package_quantity), 'unit' => $l->packageBasisUnit->code, 'description' => $l->package_description],
            'fulfilment' => $l->fulfilment, 'pickup_area' => $l->pickup_area, 'delivery_coverage' => $l->delivery_coverage ?? [],
            'dispatch_estimate' => $l->dispatch_estimate, 'delivery_charge' => $l->delivery_charge, 'state' => $l->state, 'city' => $l->city, 'area' => $l->area,
            'title' => $l->title, 'description' => $l->description, 'negotiable' => $l->negotiable,
        ];
        // A stated package belongs to the unit it was stated for: moving to a different unit without restating it drops the old statement.
        if ($unitChanged && ! array_key_exists('package', $data)) {
            $current['package'] = null;
        }
        // Changing the product kind without restating its identity would carry over fields that no longer apply.
        if (isset($data['product_kind']) && $data['product_kind'] !== $l->product_kind) {
            foreach (['species_id', 'crop_type_id', 'custom_product_name'] as $field) {
                if (! array_key_exists($field, $data)) {
                    $current[$field] = null;
                }
            }
        }

        return array_replace($current, $data);
    }

    /** Non-rule columns taken straight from the (already validated) request. @return array<string, mixed> */
    private function plain(array $e): array
    {
        return [
            'title' => $e['title'], 'description' => $e['description'] ?? null, 'negotiable' => (bool) ($e['negotiable'] ?? false),
            'state' => $e['state'] ?? null, 'city' => $e['city'] ?? null, 'area' => $e['area'] ?? null,
        ];
    }

    /** Catalogue image and inventory link, when the request carries them. @return array<string, mixed> */
    private function extras(User $user, MarketplaceShop $shop, array $data, string $productKind): array
    {
        $out = [];
        if (array_key_exists('catalog_image_id', $data)) {
            $out['catalog_image_id'] = $this->catalogImage($data['catalog_image_id']);
        }
        if (array_key_exists('inventory_item_id', $data)) {
            $out += $data['inventory_item_id'] === null ? $this->inventory->unlinked() : $this->inventory->link($user, $shop, $data['inventory_item_id'], $productKind);
        }

        return $out;
    }

    private function catalogImage(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        if ($this->images->pickable($id) === null) {
            throw ValidationException::withMessages(['catalog_image_id' => 'Choose an image from the image library.']);
        }

        return $id;
    }

    /** @return array<string, mixed> price facts for the audit trail (prices are public information) */
    private function priceChange(array $before, MarketplaceListing $l): array
    {
        if (! $l->wasChanged(['unit_price', 'available_quantity', 'unit_id'])) {
            return [];
        }

        return ['unit_price' => ['from' => Decimal::trim((string) $before['unit_price']), 'to' => Decimal::trim((string) $l->unit_price)],
            'available_quantity' => ['from' => Decimal::trim((string) $before['available_quantity']), 'to' => Decimal::trim((string) $l->available_quantity)]];
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');
        $last = MarketplaceListing::withTrashed()->where('reference', 'like', "LST-$year-%")->orderByDesc('reference')->value('reference');

        return "LST-$year-".str_pad((string) ($last ? ((int) substr($last, -5)) + 1 : 1), 5, '0', STR_PAD_LEFT);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'listing', 150, '');
        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (MarketplaceListing::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }

    /** @param  array<string, mixed>  $changes */
    private function audit(User $user, MarketplaceShop $shop, MarketplaceListing $listing, string $action, array $changes = []): void
    {
        $this->audit->record($shop->farm_id, $user->id, $action, 'marketplace_listing', $listing->id, $listing->title, $changes === [] ? null : $changes);
    }
}
