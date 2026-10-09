# Marketplace image asset runbook (Phase 23)

The illustrative image catalogue is seeded as **structure only**. No image file, URL or third-party asset ships with the code. Until an asset is attached to a catalogue row, that row is *awaiting asset*: it is not offered in `GET /marketplace/image-library`, is not resolved onto listings and its file route answers `404`. Listings without any image show a placeholder indicator (`image.source = "placeholder"`), which is valid.

## 1. What is needed

One illustration per row of `marketplace_catalog_images` (`code` is the stable key):

| Kind | Codes |
|---|---|
| livestock | `chicken`, `goat`, `cattle`, `sheep`, `pig`, `rabbit`, `fallback-livestock` |
| fish | `fish`, `catfish`, `fallback-fish` |
| crop_produce | `yam`, `cassava`, `maize`, `vegetables`, `fruits`, `rice`, `tomatoes`, `fallback-crop_produce` |
| eggs / milk | `eggs`, `milk`, `fallback-eggs`, `fallback-milk` |
| feed / other | `fallback-feed`, `fallback-other` |

Minimum useful set: the 13 examples in the product brief (chicken, goat, cattle, sheep, pig, catfish, yam, cassava, maize, rice, tomatoes, eggs, milk) and the seven `fallback-*` rows (the category fallback every listing of that kind falls back to).

## 2. Asset requirements

* **Rights:** only images the business owns, commissioned, or that carry a licence allowing commercial redistribution without attribution problems (for example CC0 / public domain, or a purchased licence). Record the licence name in `licence` and, if the licence requires it, the credit in `attribution`. **Never** use scraped or stock-site images without a licence.
* **Nature:** *illustrations* (the API labels them `illustrative: true`); do not present them as photos of the seller's goods.
* **Format:** WebP (preferred) or PNG or JPEG; 1200 x 900 px (4:3), ≤ 300 KB; flat background; no text or watermarks.
* **Accessibility:** a clear `alt_text` per row (the seed provides "Illustration of <label>").

## 3. Attaching an asset

1. Put the file on the marketplace disk under `catalogue/` using the row code as file name, for example `catalogue/chicken.webp` (local disk: `storage/app/private/marketplace/catalogue/chicken.webp`; S3: the same key in the configured bucket).
2. Update the row (insert-only seeds never overwrite it afterwards):

   ```php
   MarketplaceCatalogImage::where('code', 'chicken')->update([
       'asset_path' => 'catalogue/chicken.webp', 'mime_type' => 'image/webp',
       'licence' => 'CC0 1.0', 'attribution' => null,
   ]);
   ```
3. Verify: `GET /api/v1/public/marketplace/catalogue-images/chicken` returns the image with `X-Content-Type-Options: nosniff`, and `GET /api/v1/marketplace/image-library` now lists `chicken`. A listing for a chicken species (or named "Chicken") now resolves to it automatically.
4. To withdraw an image, set `is_active = false` (it disappears at once; listings fall back down the resolution order).

## 4. Moving to S3

Set `MARKETPLACE_DISK` to an S3 disk defined in `config/filesystems.php` (private bucket, no public ACL) and copy `catalogue/` and `listings/` to the same keys. All reads go through the application, so no URL changes for clients.

## 5. Checklist before enabling

- [ ] licence recorded for every attached asset
- [ ] file served correctly through the catalogue route
- [ ] fallback row exists for each product kind that has listings
- [ ] no asset path points outside `catalogue/`
