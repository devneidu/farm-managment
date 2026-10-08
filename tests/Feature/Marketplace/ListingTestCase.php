<?php

namespace Tests\Feature\Marketplace;

use App\Enums\PlatformRole;
use App\Models\CropType;
use App\Models\MarketplaceListing;
use App\Models\PlatformAdmin;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Team\TeamTestCase;

/** Shared fixtures for the Phase 23 listing tests: sellers (no farm), approved shops, shop members and listing payloads. */
abstract class ListingTestCase extends TeamTestCase
{
    protected const SELLER = '/api/v1/marketplace';

    protected const PUBLIC = '/api/v1/public/marketplace/listings';

    protected const ADMIN = '/api/v1/platform-admin/marketplace';

    protected const PHONE = '+2348031234567';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('marketplace');   // no test ever writes to the real marketplace disk
    }

    protected function seller(string $name = 'Ada Seller'): User
    {
        return User::factory()->create(['name' => $name]);
    }

    protected function admin(PlatformRole $role = PlatformRole::Admin): User
    {
        $user = User::factory()->create(['name' => 'Pat Admin']);
        PlatformAdmin::create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** An APPROVED, active shop owned by $user (marketplace-only unless $extra carries farm_id). */
    protected function activeShop(User $user, array $extra = []): string
    {
        $id = $this->signInAs($user)->postJson(self::SELLER.'/shops', array_replace([
            'name' => 'Ada Poultry Hub '.substr(md5(uniqid('', true)), 0, 5), 'tagline' => 'Fresh daily', 'seller_type' => 'business', 'categories' => ['livestock', 'eggs_dairy'],
            'description' => 'Layers, broilers and fresh crates of eggs delivered across Ibadan.', 'state' => 'Oyo', 'city' => 'Ibadan', 'area' => 'Bodija',
        ], $extra))->assertCreated()->json('data.id');
        $this->patchJson(self::SELLER."/shops/$id/contact", [
            'contact_phone' => self::PHONE, 'contact_whatsapp' => '+2348099998888', 'contact_email' => 'ada.private@example.com', 'address_line' => '12 Secret Street, Bodija', 'preferred_contact_method' => 'whatsapp',
        ])->assertOk();
        $this->postJson(self::SELLER."/shops/$id/submit")->assertOk();
        $this->signInAs($this->admin())->postJson(self::ADMIN."/shops/$id/approve")->assertOk();
        $this->signInAs($user);

        return $id;
    }

    /** A draft (not yet submitted) shop. */
    protected function draftShop(User $user): string
    {
        return $this->signInAs($user)->postJson(self::SELLER.'/shops', [
            'name' => 'Draft Shop '.substr(md5(uniqid('', true)), 0, 5), 'seller_type' => 'individual', 'state' => 'Oyo', 'city' => 'Ibadan',
        ])->assertCreated()->json('data.id');
    }

    /** Adds an existing verified account to the shop with $role; leaves the owner signed in. */
    protected function addMember(User $owner, string $shopId, string $role, string $name = 'Member'): User
    {
        $member = User::factory()->create(['name' => $name]);
        $this->signInAs($owner)->postJson(self::SELLER."/shops/$shopId/members", ['email' => $member->email, 'role' => $role])->assertCreated();

        return $member;
    }

    protected function chicken(array $extra = []): array
    {
        return array_replace([
            'title' => 'Healthy point-of-lay chickens', 'product_kind' => 'livestock', 'species_id' => Species::where('code', 'chicken')->value('id'),
            'unit' => 'head', 'unit_price' => '8000', 'available_quantity' => '120', 'min_order_quantity' => '5', 'negotiable' => true,
            'description' => 'Vaccinated, 16 weeks old.',
        ], $extra);
    }

    protected function catfish(array $extra = []): array
    {
        return array_replace([
            'title' => 'Fresh catfish', 'product_kind' => 'fish', 'species_id' => Species::where('code', 'fish')->value('id'), 'custom_product_name' => 'Catfish',
            'unit' => 'kg', 'unit_price' => '3500', 'available_quantity' => '250.5', 'fulfilment' => 'both',
            'delivery_coverage' => ['Oyo', 'Lagos'], 'dispatch_estimate' => '1_2_days', 'delivery_charge' => 'agreed_separately',
        ], $extra);
    }

    protected function eggsCrate(array $extra = []): array
    {
        return array_replace([
            'title' => 'Fresh eggs by the crate', 'product_kind' => 'eggs', 'unit' => 'crate', 'unit_price' => '6000', 'available_quantity' => '40',
            'package' => ['quantity' => '30', 'unit' => 'egg', 'description' => 'Standard crate of 30 eggs'],
        ], $extra);
    }

    protected function yam(array $extra = []): array
    {
        return array_replace([
            'title' => 'Big yam tubers', 'product_kind' => 'crop_produce', 'crop_type_id' => CropType::where('code', 'yam')->value('id'),
            'unit' => 'tuber', 'unit_price' => '2000', 'available_quantity' => '300',
        ], $extra);
    }

    protected function tomatoes(array $extra = []): array
    {
        return array_replace([
            'title' => 'Fresh tomatoes', 'product_kind' => 'crop_produce', 'custom_product_name' => 'Tomatoes',
            'unit' => 'basket', 'unit_price' => '15000', 'available_quantity' => '20',
            'package' => ['quantity' => '25', 'unit' => 'kg', 'description' => 'Large basket, about 25 kg'],
        ], $extra);
    }

    /** POST a listing as the signed-in user and return the response. */
    protected function create(string $shop, array $payload)
    {
        return $this->postJson(self::SELLER."/shops/$shop/listings", $payload);
    }

    protected function createId(string $shop, array $payload): string
    {
        return $this->create($shop, $payload)->assertCreated()->json('data.id');
    }

    protected function publish(string $shop, string $listing, array $body = [])
    {
        return $this->postJson(self::SELLER."/shops/$shop/listings/$listing/publish", $body);
    }

    /** Creates and publishes in one go (signed-in user must be owner/manager). */
    protected function live(string $shop, array $payload): string
    {
        $id = $this->createId($shop, $payload);
        $this->publish($shop, $id)->assertOk()->assertJsonPath('data.status', 'published');

        return $id;
    }

    protected function slug(string $listingId): string
    {
        return MarketplaceListing::findOrFail($listingId)->slug;
    }

    protected function publicSlugs(string $query = ''): array
    {
        // anonymous read, then back to whoever was signed in
        $who = $this->app['auth']->guard('web')->user();
        $this->app['auth']->forgetGuards();
        $slugs = collect($this->getJson(self::PUBLIC.($query ? "?$query" : ''))->assertOk()->json('data'))->pluck('slug')->all();
        if ($who) {
            $this->signInAs($who);
        }

        return $slugs;
    }
}
