# Phase 19 - Localization & accessibility (API contract)

English is the only launched language. Hausa (`ha`), Yoruba (`yo`), Igbo (`ig`) and Nigerian Pidgin (`pcm`) are
registered but **unavailable** until native-speaker terminology review (`status: pending_terminology_review`).
Nigeria-first defaults are unchanged (NGN, Africa/Lagos, English) and onboarding asks for none of them.

## Endpoints

| Method | URL | Auth | Purpose |
|---|---|---|---|
| GET | `/api/v1/locales` | public, throttled | Registry: `default`, `fallback`, `locales[]` (`code`, `name`, `native_name`, `direction`, `available`, `status`, `is_default`), `number_input` |
| GET | `/api/v1/translations/{locale}` | public, throttled | Flat dot-keyed UI strings for an available language. `404 locale_unavailable` for unknown/unavailable |
| GET | `/api/v1/me/preferences` | verified user | `locale` (explicit choice or `null`), `effective_locale`, `default_locale`, `fallback_locale`, `farm_locale`, `available_locales` |
| PATCH | `/api/v1/me/preferences` | verified user | Body `{ "locale": "en" \| null }`. Unavailable/unknown code -> `422` on `locale` |

`locale` is also returned (additively) on `GET /account` and `data.user.locale` of the auth-state payload.

### Translation bundle

`{ locale, fallback_locale, direction, version, complete, fallback_keys[], messages{ "common.save": "Save", ... } }`

* The bundle always contains every English key. A key a language has not translated (or left blank) is served in
  English and listed in `fallback_keys`; `complete` is `true` only when nothing fell back.
* `version` changes when content changes (use as a cache key).
* Keys are stable identifiers; a language pack only supplies values. Add a pack by creating `lang/{code}/ui.php`
  and setting `enabled => true` in `config/localization.php` after terminology review.

### Which language the API uses for a request

1. the signed-in user's saved `locale` (if still available), else
2. the best available language in `Accept-Language` (q-weighted, `en-NG` matches `en`), else
3. the platform default (`en`).

Responses carry `Content-Language` and `Vary: Accept-Language`. Today every API message is English, so this
matters only for text served from translation keys; the machine `code` of every error/status is language-independent.

## What locale never changes

* Stored values, enum codes/slugs, master-data codes, reference codes.
* Money: exact decimal strings (`"3000.50"`), NGN. No localised separators in API fields.
* Quantities: canonical units; display units remain the farm preference (`/measurements/unit-preferences`).
* Timestamps: ISO-8601 UTC (`...Z`); date-sensitive features keep using the farm timezone (Africa/Lagos).
* User-entered farm records (names, notes) are never translated.

## Frontend responsibilities (not implemented in the API)

* Contrast, focus order/visibility, keyboard and screen-reader support, touch targets, reduced motion.
* Render state from machine codes plus text (`task_state.*`, `a11y.status_prefix` etc.), never colour alone.
* Use `number_input.input_mode` (`decimal`) for numeric fields; send dot-decimal strings
  (`"12,5"` is rejected with `invalid_quantity`, never reinterpreted).
* Link validation errors (`errors.<field>`) to inputs and use `form.error_summary` / `a11y.error_prefix`.
* Format money/dates/numbers for display on the client from the canonical API values.
