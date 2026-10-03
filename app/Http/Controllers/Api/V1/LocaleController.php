<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Localization\LocaleCatalogue;
use App\Support\Api\ApiHttpException;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class LocaleController extends Controller
{
    /**
     * List supported languages
     *
     * Public. Every registered language with `available` (selectable and served) and `status` (`launched` or
     * `pending_terminology_review`). English is the only launched language; Hausa, Yoruba, Igbo and Nigerian Pidgin are registered
     * but unavailable until native-speaker terminology review. `number_input` is the same for every language: authoritative numbers and
     * money are dot-decimal strings, so clients should use a decimal keypad (`input_mode`) and send canonical values.
     *
     * @response array{data: array{default: string, fallback: string, locales: list<array{code: string, name: string, native_name: string, direction: string, available: bool, status: string, is_default: bool}>, number_input: array{decimal_separator: string, thousands_separator: string|null, input_mode: string}}, meta: object, message: string|null}
     */
    public function index(LocaleCatalogue $locales): JsonResponse
    {
        return ApiResponse::success([
            'default' => $locales->default(),
            'fallback' => $locales->fallback(),
            'locales' => $locales->describe(),
            'number_input' => config('localization.number_input'),
        ]);
    }

    /**
     * Get the UI text bundle for a language
     *
     * Public. Flat dot-keyed UI strings (navigation, form hints, task-state labels, accessibility phrases). Any key a language does not yet
     * translate is served in the fallback language and listed in `fallback_keys`, so no key is ever blank; `complete` is true when
     * nothing fell back. `version` changes whenever the content does (cache key). A language that is unknown or not yet available is
     * `404 locale_unavailable`. These are interface strings only: user-entered farm records, master-data codes and stored values are
     * never translated.
     *
     * @response array{data: array{locale: string, fallback_locale: string, direction: string, version: string, complete: bool, fallback_keys: list<string>, messages: array<string, string>}, meta: object, message: string|null}
     */
    public function bundle(string $locale, LocaleCatalogue $locales): JsonResponse
    {
        if (! $locales->isEnabled($locale)) {
            throw new ApiHttpException(404, 'locale_unavailable', 'That language is not available.');
        }

        return ApiResponse::success($locales->bundle($locale));
    }
}
