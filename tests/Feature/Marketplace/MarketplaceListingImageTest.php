<?php

namespace Tests\Feature\Marketplace;

use App\Models\CropType;
use App\Models\MarketplaceCatalogImage;
use App\Models\MarketplaceListingImage;
use App\Models\Species;
use App\Models\User;
use App\Services\Marketplace\MarketplaceImageService;
use App\Support\Api\ApiRoute;
use Database\Seeders\MarketplaceImageCatalogueSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Seller photos (validation, re-encoding, ownership, serving) and the reusable illustrative image catalogue. */
class MarketplaceListingImageTest extends ListingTestCase
{
    private User $user;

    private string $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('marketplace');
        $this->user = $this->seller();
        $this->shop = $this->activeShop($this->user);
    }

    private function listing(array $payload = []): string
    {
        return $this->createId($this->shop, $payload ?: $this->chicken());
    }

    private function upload(string $listing, UploadedFile $file, array $extra = [])
    {
        return $this->post(self::SELLER."/shops/{$this->shop}/listings/$listing/images", ['image' => $file] + $extra, ['Accept' => 'application/json']);
    }

    /** A real PNG/JPEG of the given size, with optional garbage appended after the image data. */
    private function picture(string $name = 'photo.png', int $w = 120, int $h = 80, string $append = ''): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 140, 60));
        ob_start();
        str_ends_with($name, '.jpg') ? imagejpeg($im) : imagepng($im);
        $bytes = ob_get_clean().$append;
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, str_ends_with($name, '.jpg') ? 'image/jpeg' : 'image/png', null, true);
    }

    private function seedAsset(string $code, string $mime = 'image/png'): MarketplaceCatalogImage
    {
        $row = MarketplaceCatalogImage::where('code', $code)->firstOrFail();
        $path = "catalogue/$code.png";
        Storage::disk('marketplace')->put($path, file_get_contents($this->picture()->getRealPath()));
        $row->update(['asset_path' => $path, 'mime_type' => $mime, 'licence' => 'CC0 (test)']);
        app(MarketplaceImageService::class)->forgetCache();

        return $row->fresh();
    }

    // ------------------------------------------------------------------ uploads

    public function test_a_photo_is_validated_reencoded_and_stored_privately_under_a_random_name(): void
    {
        $id = $this->listing();
        $r = $this->upload($id, $this->picture('my chicken <script>.png', 120, 80), ['alt_text' => 'Two layers'])->assertCreated();
        $r->assertJsonPath('data.mime_type', 'image/png')->assertJsonPath('data.width', 120)->assertJsonPath('data.height', 80)->assertJsonPath('data.position', 0)
            ->assertJsonPath('data.alt_text', 'Two layers')->assertJsonPath('data.kind', 'seller')->assertJsonPath('data.illustrative', false);

        $row = MarketplaceListingImage::findOrFail($r->json('data.id'));
        $this->assertMatchesRegularExpression('#^listings/'.$this->shop.'/'.$id.'/[0-9a-f-]{36}\.png$#', $row->path);
        $this->assertStringNotContainsString('chicken', $row->path, 'the client file name never reaches the path');
        Storage::disk('marketplace')->assertExists($row->path);
        $this->assertSame(hash('sha256', Storage::disk('marketplace')->get($row->path)), $row->sha256);
        $this->assertSame(strlen(Storage::disk('marketplace')->get($row->path)), $row->size);
        $this->assertSame($this->user->id, $row->uploaded_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketplace.listing_image_added', 'resource_id' => $id]);

        // the listing now shows the seller's photo as its primary image
        $this->getJson(self::SELLER."/shops/{$this->shop}/listings/$id")->assertOk()->assertJsonPath('data.image.source', 'seller')->assertJsonPath('data.image.illustrative', false)
            ->assertJsonPath('data.images.0.id', $row->id)->assertJsonPath('data.version', 1);   // photos never move the field version token
        $this->assertSame(ApiRoute::url("/marketplace/shops/{$this->shop}/listings/$id/images/{$row->id}/file"), $r->json('data.url'));
    }

    public function test_reencoding_removes_appended_payloads_and_metadata(): void
    {
        $id = $this->listing();
        $payload = '<?php system($_GET["c"]); ?>SECRET-MARKER';
        foreach (['evil.png', 'evil.jpg'] as $name) {
            $file = $this->picture($name, 60, 40, $payload);
            $this->assertStringContainsString('SECRET-MARKER', file_get_contents($file->getRealPath()));
            $row = MarketplaceListingImage::findOrFail($this->upload($id, $file)->assertCreated()->json('data.id'));
            $stored = Storage::disk('marketplace')->get($row->path);
            $this->assertStringNotContainsString('SECRET-MARKER', $stored);
            $this->assertStringNotContainsString('<?php', $stored);
            $this->assertNotFalse(imagecreatefromstring($stored), 'what was stored is a valid image');
        }
    }

    public function test_large_photos_are_scaled_down_and_oversized_or_unreadable_ones_refused(): void
    {
        $id = $this->listing();
        $row = MarketplaceListingImage::findOrFail($this->upload($id, $this->picture('big.png', 3000, 2000))->assertCreated()->json('data.id'));
        $this->assertSame(2048, $row->width);
        $this->assertSame(1365, $row->height);
        [$w, $h] = getimagesizefromstring(Storage::disk('marketplace')->get($row->path));
        $this->assertSame([2048, 1365], [$w, $h]);

        $this->upload($id, $this->picture('wide.png', 4200, 10))->assertStatus(422)->assertJsonValidationErrors('image');   // over the 4096 px input limit
    }

    public function test_only_real_jpeg_png_and_webp_images_are_accepted(): void
    {
        $id = $this->listing();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $html = UploadedFile::fake()->createWithContent('page.jpg', '<html><script>alert(1)</script></html>');
        $php = UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;');
        $pdf = UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n");
        $gif = UploadedFile::fake()->createWithContent('anim.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
        $fakeJpg = UploadedFile::fake()->createWithContent('real.jpg', 'definitely not an image');
        foreach ([$svg, $html, $php, $pdf, $gif, $fakeJpg] as $file) {
            $this->upload($id, $file)->assertStatus(422)->assertJsonValidationErrors('image');
        }
        // 6 MB
        $big = $this->picture('huge.png');
        file_put_contents($big->getRealPath(), str_repeat('A', 6 * 1024 * 1024), FILE_APPEND);
        clearstatcache(true);
        $this->upload($id, $big)->assertStatus(422)->assertJsonValidationErrors('image');
        $this->postJson(self::SELLER."/shops/{$this->shop}/listings/$id/images", [])->assertStatus(422)->assertJsonValidationErrors('image');
        $this->assertSame(0, MarketplaceListingImage::count());
        $this->assertSame([], Storage::disk('marketplace')->allFiles());

        $webp = UploadedFile::fake()->image('p.webp', 50, 50);
        $this->upload($id, $webp)->assertCreated()->assertJsonPath('data.mime_type', 'image/webp');
    }

    public function test_a_remote_url_is_never_fetched_or_accepted(): void
    {
        Http::fake();
        $id = $this->listing();
        $this->postJson(self::SELLER."/shops/{$this->shop}/listings/$id/images", ['image' => 'https://evil.example/x.png', 'image_url' => 'https://evil.example/y.png'])
            ->assertStatus(422)->assertJsonValidationErrors('image');
        $this->postJson(self::SELLER."/shops/{$this->shop}/listings/$id", [])->assertStatus(405);
        // nor can a listing carry a URL as its image
        $this->patchJson(self::SELLER."/shops/{$this->shop}/listings/$id", ['catalog_image_id' => 'https://evil.example/z.png'])->assertStatus(422)->assertJsonValidationErrors('catalog_image_id');
        $this->patchJson(self::SELLER."/shops/{$this->shop}/listings/$id", ['image_url' => 'https://evil.example/z.png'])->assertOk()->assertJsonPath('data.image.url', null);
        Http::assertNothingSent();
        $this->assertSame(0, MarketplaceListingImage::count());
    }

    public function test_the_photo_limit_duplicates_ordering_and_deletion(): void
    {
        $id = $this->listing();
        $ids = [];
        foreach (range(1, 6) as $n) {
            $ids[] = $this->upload($id, $this->picture('p'.$n.'.png', 20 + $n, 20))->assertCreated()->assertJsonPath('data.position', $n - 1)->json('data.id');
        }
        $this->upload($id, $this->picture('p7.png', 99, 20))->assertStatus(409)->assertJsonPath('code', 'image_limit_reached')->assertJsonPath('details.limit', 6);
        $this->assertCount(6, Storage::disk('marketplace')->allFiles());

        // the same picture again is the same photo, not a seventh
        $dup = $this->upload($id, $this->picture('again.png', 21, 20))->assertOk()->assertJsonPath('data.id', $ids[0]);
        $this->assertSame($ids[0], $dup->json('data.id'));
        $this->assertCount(6, Storage::disk('marketplace')->allFiles());

        // move the last photo to the front
        $this->patchJson(self::SELLER."/shops/{$this->shop}/listings/$id/images/{$ids[5]}", ['position' => 0, 'alt_text' => 'Cover'])->assertOk()->assertJsonPath('data.position', 0)->assertJsonPath('data.alt_text', 'Cover');
        $order = $this->getJson(self::SELLER."/shops/{$this->shop}/listings/$id")->json('data.images');
        $this->assertSame([$ids[5], $ids[0], $ids[1], $ids[2], $ids[3], $ids[4]], array_column($order, 'id'));
        $this->assertSame([0, 1, 2, 3, 4, 5], array_column($order, 'position'));
        $this->assertSame($ids[5], $this->getJson(self::SELLER."/shops/{$this->shop}/listings/$id")->json('data.image.url') ? $ids[5] : null);

        $path = MarketplaceListingImage::findOrFail($ids[0])->path;
        $this->deleteJson(self::SELLER."/shops/{$this->shop}/listings/$id/images/{$ids[0]}")->assertOk();
        Storage::disk('marketplace')->assertMissing($path);
        $order = $this->getJson(self::SELLER."/shops/{$this->shop}/listings/$id")->json('data.images');
        $this->assertSame([$ids[5], $ids[1], $ids[2], $ids[3], $ids[4]], array_column($order, 'id'));
        $this->assertSame([0, 1, 2, 3, 4], array_column($order, 'position'), 'positions are renumbered');
        $this->upload($id, $this->picture('p8.png', 77, 20))->assertCreated();   // room again
    }

    // ------------------------------------------------------------------ ownership and serving

    public function test_photos_are_scoped_to_their_listing_and_shop(): void
    {
        $a = $this->listing();
        $b = $this->listing($this->yam());
        $photo = $this->upload($a, $this->picture())->assertCreated()->json('data.id');

        // another listing of the SAME shop cannot address it
        $this->patchJson(self::SELLER."/shops/{$this->shop}/listings/$b/images/$photo", ['alt_text' => 'x'])->assertNotFound();
        $this->deleteJson(self::SELLER."/shops/{$this->shop}/listings/$b/images/$photo")->assertNotFound();
        $this->getJson(self::SELLER."/shops/{$this->shop}/listings/$b/images/$photo/file")->assertNotFound();

        // another seller cannot touch or read it, with or without the right ids
        $stranger = $this->seller('Stranger');
        $mine = $this->activeShop($stranger);
        $mineListing = $this->createId($mine, $this->yam());
        $this->patchJson(self::SELLER."/shops/$mine/listings/$mineListing/images/$photo", ['alt_text' => 'x'])->assertNotFound();
        $this->deleteJson(self::SELLER."/shops/{$this->shop}/listings/$a/images/$photo")->assertNotFound();
        $this->getJson(self::SELLER."/shops/{$this->shop}/listings/$a/images/$photo/file")->assertNotFound();
        $this->upload($a, $this->picture('hijack.png', 33, 33))->assertNotFound();
        $this->assertNotNull(MarketplaceListingImage::find($photo));
    }

    public function test_files_are_served_by_the_application_with_safe_headers_and_only_to_the_right_audience(): void
    {
        $id = $this->listing();
        $photo = $this->upload($id, $this->picture())->assertCreated()->json('data.id');
        $member = self::SELLER."/shops/{$this->shop}/listings/$id/images/$photo/file";
        $public = "/api/v1/public/marketplace/images/$photo";

        $r = $this->get($member)->assertOk();
        $r->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(Storage::disk('marketplace')->get(MarketplaceListingImage::find($photo)->path), $r->streamedContent());

        // a draft photo is not public
        $this->app['auth']->forgetGuards();
        $this->get($public)->assertNotFound();
        $this->get($member, ['Accept' => 'application/json'])->assertUnauthorized();

        // published: public, anonymous, cacheable
        $this->signInAs($this->user)->publish($this->shop, $id)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get($public)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'image/png');
        $listing = $this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertOk();
        $listing->assertJsonPath('data.images.0.url', $public)->assertJsonPath('data.image.url', $public)->assertJsonPath('data.image.source', 'seller');
        $this->assertStringNotContainsString('listings/'.$this->shop, $listing->getContent(), 'the storage path is never exposed');

        // paused / restricted / suspended shop: not served
        $this->signInAs($this->user)->postJson(self::SELLER."/shops/{$this->shop}/listings/$id/pause")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get($public)->assertNotFound();
        $this->signInAs($this->user)->publish($this->shop, $id)->assertOk();
        $this->signInAs($this->admin())->postJson(self::ADMIN."/listings/$id/restrict", ['reason' => 'Review'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get($public)->assertNotFound();
        // a platform admin can still review the photo
        $this->signInAs($this->admin())->get(self::ADMIN."/listings/$id/images/$photo/file")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(self::ADMIN."/listings/$id/images/".Str::uuid().'/file')->assertNotFound();
        $this->signInAs($this->seller('Plain'))->get(self::ADMIN."/listings/$id/images/$photo/file")->assertForbidden();
        $this->get('/api/v1/public/marketplace/images/not-a-uuid')->assertNotFound();
    }

    public function test_a_missing_stored_file_is_a_clean_404(): void
    {
        $id = $this->listing();
        $photo = $this->upload($id, $this->picture())->assertCreated()->json('data.id');
        Storage::disk('marketplace')->delete(MarketplaceListingImage::find($photo)->path);
        $this->get(self::SELLER."/shops/{$this->shop}/listings/$id/images/$photo/file")->assertNotFound();
    }

    // ------------------------------------------------------------------ the illustrative catalogue

    public function test_the_catalogue_structure_is_seeded_without_inventing_any_asset(): void
    {
        $codes = MarketplaceCatalogImage::where('is_kind_fallback', false)->pluck('code')->all();
        foreach (['chicken', 'goat', 'cattle', 'sheep', 'pig', 'catfish', 'yam', 'cassava', 'maize', 'rice', 'tomatoes', 'eggs', 'milk'] as $required) {
            $this->assertContains($required, $codes);
        }
        $this->assertSame(7, MarketplaceCatalogImage::where('is_kind_fallback', true)->count(), 'one fallback per product kind');
        $this->assertSame(0, MarketplaceCatalogImage::whereNotNull('asset_path')->count(), 'no asset, URL or path was invented');
        $this->assertSame(0, MarketplaceCatalogImage::where('is_illustrative', false)->count());
        // mapped to the EXISTING master data
        $this->assertSame(Species::where('code', 'chicken')->value('id'), MarketplaceCatalogImage::where('code', 'chicken')->value('species_id'));
        $this->assertSame(CropType::where('code', 'yam')->value('id'), MarketplaceCatalogImage::where('code', 'yam')->value('crop_type_id'));
        $this->assertNull(MarketplaceCatalogImage::where('code', 'rice')->value('species_id'));

        // re-running the provisioning is insert-only
        MarketplaceCatalogImage::where('code', 'goat')->update(['label' => 'Edited by an admin']);
        (new MarketplaceImageCatalogueSeeder)->run();
        $this->assertSame('Edited by an admin', MarketplaceCatalogImage::where('code', 'goat')->value('label'));
        $this->assertSame(1, MarketplaceCatalogImage::where('code', 'goat')->count());
    }

    public function test_rows_awaiting_an_asset_are_invisible_and_cannot_be_chosen(): void
    {
        $this->getJson(self::SELLER.'/image-library')->assertOk()->assertJsonCount(0, 'data');
        $unseeded = MarketplaceCatalogImage::where('code', 'chicken')->value('id');
        $id = $this->listing();
        $this->patchJson(self::SELLER."/shops/{$this->shop}/listings/$id", ['catalog_image_id' => $unseeded])->assertStatus(422)->assertJsonValidationErrors('catalog_image_id');
        $this->get('/api/v1/public/marketplace/catalogue-images/chicken')->assertNotFound();
        $this->get('/api/v1/public/marketplace/catalogue-images/no-such-image')->assertNotFound();
        $this->getJson(self::SELLER."/shops/{$this->shop}/listings/$id")->assertJsonPath('data.image.source', 'placeholder');
    }

    public function test_the_image_library_lists_only_seeded_active_entries_flagged_illustrative(): void
    {
        $this->seedAsset('chicken');
        $this->seedAsset('fallback-fish');
        $lib = $this->getJson(self::SELLER.'/image-library')->assertOk()->assertJsonCount(2, 'data');
        $chicken = collect($lib->json('data'))->firstWhere('code', 'chicken');
        $this->assertTrue($chicken['illustrative']);
        $this->assertFalse($chicken['kind_fallback']);
        $this->assertSame('/api/v1/public/marketplace/catalogue-images/chicken', $chicken['url']);
        $this->assertTrue(collect($lib->json('data'))->firstWhere('code', 'fallback-fish')['kind_fallback']);
        $this->getJson(self::SELLER.'/image-library?product_kind=fish')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::SELLER.'/image-library?product_kind=guns')->assertStatus(422);

        MarketplaceCatalogImage::where('code', 'chicken')->update(['is_active' => false]);
        app(MarketplaceImageService::class)->forgetCache();
        $this->getJson(self::SELLER.'/image-library')->assertJsonCount(1, 'data');
        $this->get('/api/v1/public/marketplace/catalogue-images/chicken')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::SELLER.'/image-library')->assertUnauthorized();
    }

    public function test_listing_images_resolve_in_a_fixed_order_and_catalogue_images_are_always_illustrative(): void
    {
        foreach (['chicken', 'goat', 'rice', 'yam', 'fallback-livestock', 'fallback-crop_produce'] as $code) {
            $this->seedAsset($code);
        }
        $files = count(Storage::disk('marketplace')->allFiles());
        $image = fn (string $id) => $this->getJson(self::SELLER."/shops/{$this->shop}/listings/$id")->assertOk()->json('data.image');

        // species match
        $chicken = $this->listing();
        $img = $image($chicken);
        $this->assertSame(['catalogue', 'catalogue', true, true], [$img['source'], $img['kind'], $img['illustrative'], $img['has_image']]);
        $this->assertSame('/api/v1/public/marketplace/catalogue-images/chicken', $img['url']);
        // crop match
        $this->assertSame('/api/v1/public/marketplace/catalogue-images/yam', $image($this->listing($this->yam()))['url']);
        // product-name match where master data has no entry (rice)
        $rice = $this->listing(['title' => 'Local rice', 'product_kind' => 'crop_produce', 'custom_product_name' => 'Rice', 'unit' => 'bag', 'unit_price' => '60000', 'available_quantity' => '10', 'package' => ['quantity' => '50', 'unit' => 'kg']]);
        $this->assertSame('/api/v1/public/marketplace/catalogue-images/rice', $image($rice)['url']);
        // category fallback: flagged as such, still illustrative
        $cattle = $this->listing($this->chicken(['title' => 'Bull', 'species_id' => Species::where('code', 'cattle')->value('id')]));
        $img = $image($cattle);
        $this->assertSame(['fallback', true], [$img['source'], $img['illustrative']]);
        $this->assertSame('/api/v1/public/marketplace/catalogue-images/fallback-livestock', $img['url']);
        // nothing at all: a placeholder INDICATOR, no url
        $milk = $this->listing(['title' => 'Fresh milk', 'product_kind' => 'milk', 'unit' => 'l', 'unit_price' => '900', 'available_quantity' => '30']);
        $img = $image($milk);
        $this->assertSame(['placeholder', null, false, 'milk'], [$img['source'], $img['url'], $img['has_image'], $img['placeholder_kind']]);

        // the seller's explicit choice beats the automatic match
        $goat = MarketplaceCatalogImage::where('code', 'goat')->value('id');
        $this->patchJson(self::SELLER."/shops/{$this->shop}/listings/$chicken", ['catalog_image_id' => $goat])->assertOk()->assertJsonPath('data.image.url', '/api/v1/public/marketplace/catalogue-images/goat')->assertJsonPath('data.image.source', 'catalogue');
        // and the seller's own photo beats everything
        $this->upload($chicken, $this->picture())->assertCreated();
        $img = $image($chicken);
        $this->assertSame(['seller', false], [$img['source'], $img['illustrative']]);
        $this->assertStringContainsString('/images/', $img['url']);

        // references, not copies: five listings, still only the seeded assets and the one photo on disk
        $this->assertSame($files + 1, count(Storage::disk('marketplace')->allFiles()));
        // a chosen image can be cleared again
        $this->patchJson(self::SELLER."/shops/{$this->shop}/listings/$cattle", ['catalog_image_id' => null])->assertOk();
    }

    public function test_the_public_catalogue_file_is_streamed_with_safe_headers(): void
    {
        $this->seedAsset('maize');
        $this->app['auth']->forgetGuards();
        $r = $this->get('/api/v1/public/marketplace/catalogue-images/maize')->assertOk();
        $r->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'image/png');
        $this->assertSame(file_get_contents($this->picture()->getRealPath()), $r->streamedContent());
    }

    public function test_an_image_less_listing_publishes_and_the_public_sees_a_placeholder_indicator(): void
    {
        $id = $this->live($this->shop, $this->tomatoes());
        $this->app['auth']->forgetGuards();
        $data = $this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertOk()->json('data');
        $this->assertSame(['placeholder', null, false, 'crop_produce', []], [$data['image']['source'], $data['image']['url'], $data['image']['has_image'], $data['image']['placeholder_kind'], $data['images']]);
    }

    public function test_the_storage_disk_is_configurable_for_a_future_s3_move(): void
    {
        $this->assertSame('marketplace', config('marketplace.images.disk'));
        $this->assertSame('local', config('filesystems.disks.marketplace.driver'));
        $this->assertSame('private', config('filesystems.disks.marketplace.visibility'));
        $this->assertArrayNotHasKey('url', config('filesystems.disks.marketplace'), 'the disk has no public URL');

        config(['marketplace.images.disk' => 'second']);
        config(['filesystems.disks.second' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/second'), 'visibility' => 'private']]);
        Storage::fake('second');
        $id = $this->listing();
        $row = MarketplaceListingImage::findOrFail($this->upload($id, $this->picture())->assertCreated()->json('data.id'));
        Storage::disk('second')->assertExists($row->path);
        Storage::disk('marketplace')->assertMissing($row->path);
        $this->get(self::SELLER."/shops/{$this->shop}/listings/$id/images/{$row->id}/file")->assertOk();
    }

    // ------------------------------------------------------------------ draft deletion cleanup

    public function test_deleting_a_draft_removes_its_own_files_but_never_other_listings_or_catalogue_assets(): void
    {
        $this->seedAsset('fallback-fish');
        $a = $this->listing();
        $b = $this->listing($this->yam());
        $mine = [];
        foreach ([1, 2] as $n) {
            $mine[] = MarketplaceListingImage::findOrFail($this->upload($a, $this->picture("a$n.png", 30 + $n, 20))->assertCreated()->json('data.id'));
        }
        $other = MarketplaceListingImage::findOrFail($this->upload($b, $this->picture('b.png', 55, 20))->assertCreated()->json('data.id'));
        $catalogue = MarketplaceCatalogImage::where('code', 'fallback-fish')->firstOrFail()->asset_path;

        $this->deleteJson(self::SELLER."/shops/{$this->shop}/listings/$a")->assertOk();

        foreach ($mine as $img) {
            Storage::disk('marketplace')->assertMissing($img->path);
            $this->assertDatabaseMissing('marketplace_listing_images', ['id' => $img->id]);
        }
        Storage::disk('marketplace')->assertExists($other->path);
        $this->assertDatabaseHas('marketplace_listing_images', ['id' => $other->id]);
        Storage::disk('marketplace')->assertExists($catalogue);
        $this->assertSoftDeleted('marketplace_listings', ['id' => $a]);
        $this->assertDatabaseHas('marketplace_listing_events', ['listing_id' => $a, 'action' => 'deleted']);
    }

    public function test_a_row_pointing_outside_the_listing_folder_is_never_deleted_from_storage(): void
    {
        $a = $this->listing();
        $b = $this->listing($this->yam());
        $photoB = MarketplaceListingImage::findOrFail($this->upload($b, $this->picture('b.png', 55, 20))->assertCreated()->json('data.id'));
        $row = MarketplaceListingImage::findOrFail($this->upload($a, $this->picture('a.png', 31, 20))->assertCreated()->json('data.id'));
        $row->forceFill(['path' => $photoB->path])->save();   // corrupted / shared path

        $this->deleteJson(self::SELLER."/shops/{$this->shop}/listings/$a")->assertOk();

        Storage::disk('marketplace')->assertExists($photoB->path);
    }

    public function test_a_storage_failure_after_commit_does_not_undo_or_fail_the_delete(): void
    {
        $a = $this->listing();
        $img = MarketplaceListingImage::findOrFail($this->upload($a, $this->picture())->assertCreated()->json('data.id'));
        $path = $img->path;
        Storage::shouldReceive('disk')->andThrow(new \RuntimeException('disk offline'));

        $this->deleteJson(self::SELLER."/shops/{$this->shop}/listings/$a")->assertOk();

        $this->assertSoftDeleted('marketplace_listings', ['id' => $a]);
        $this->assertDatabaseMissing('marketplace_listing_images', ['id' => $img->id]);
        $this->assertNotSame('', $path);
    }

    public function test_a_rejected_delete_keeps_the_photos(): void
    {
        $a = $this->listing();
        $img = MarketplaceListingImage::findOrFail($this->upload($a, $this->picture())->assertCreated()->json('data.id'));
        $this->postJson(self::SELLER."/shops/{$this->shop}/listings/$a/publish")->assertOk();

        $this->deleteJson(self::SELLER."/shops/{$this->shop}/listings/$a")->assertStatus(409);

        Storage::disk('marketplace')->assertExists($img->path);
        $this->assertDatabaseHas('marketplace_listing_images', ['id' => $img->id]);
    }
}
