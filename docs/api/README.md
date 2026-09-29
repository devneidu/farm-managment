# API Documentation

OpenAPI 3.1 docs are generated from the Laravel code by [dedoc/scramble](https://scramble.dedoc.co)
(routes, FormRequests, return types and PHPDoc on controllers), so they stay coupled to the implementation.

| What | Where |
|---|---|
| Browser docs (Stoplight Elements) | `/docs/api` (e.g. `http://localhost/docs/api`) |
| Live OpenAPI JSON | `/docs/api.json` |
| Committed spec for the frontend developer | `docs/api/openapi.json` |

Docs routes are available only when `APP_ENV=local` (Scramble's default gate). To expose them elsewhere,
define the `viewApiDocs` gate in a service provider.

## Export / refresh the spec

```bash
php artisan scramble:export --path=docs/api/openapi.json
```

Re-run and commit `docs/api/openapi.json` whenever an endpoint changes.

## Postman

Postman → Import → select `docs/api/openapi.json` (or paste the `/docs/api.json` URL). The server base URL is
`{APP_URL}/api/v1`; paths in the spec are relative to it (e.g. `/health`).

## Response conventions

Success (`2xx`): `{"data": ..., "meta": {}, "message": null}`

Errors: `{"message": "...", "code": "...", "request_id": "...", "errors": {...}}`
(`errors` only for `422`). Every response carries an `X-Request-Id` header, echoed as `request_id` in errors.

| Status | `code` |
|---|---|
| 401 | `unauthenticated` |
| 403 | `forbidden` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 409 | `conflict` |
| 422 | `validation_failed` |
| 429 | `too_many_requests` |
| 500 | `server_error` |

## Documenting an endpoint

Use PHPDoc on the controller method (first line = summary), FormRequests for the request body, and
`@response` / `@unauthenticated` tags where inference is not enough. See `HealthController` for the pattern.
