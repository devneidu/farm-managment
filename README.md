# Farm Management API

Laravel 12 / PHP 8.2+ / MySQL backend for the separate Farm Management frontend. Application endpoints use `/api/v1`; domain identities are UUIDv7. Farm defaults: Nigeria, NGN, Africa/Lagos, English. Stored timestamps are UTC.

- [Frontend API contract and setup](docs/api/README.md)
- [Generated OpenAPI](docs/api/openapi.json)
- [Production deployment, queues, backups and recovery](docs/operations/LAUNCH.md)
- [Phase 21 audit and verification](docs/operations/PHASE-21-VERIFICATION.md)
- [Implementation plan](docs/implementations/10-IMPLEMENTATION-MASTER-PLAN.md)
- [Agent handoff](WORKLOG.md)

Phases 0–19 are implemented. Phase 21 launch hardening is the final V1 backend phase; deployment gates remain explicit in the verification record. **Phase 20 — Deferred from V1 / post-launch enhancement:** WhatsApp and AI-assisted parsing.

Local development: install the locked Composer dependencies, copy `.env.example` to `.env`, set a local MySQL database, generate APP_KEY once, and run `php artisan migrate`. Never point tests at development or production: `phpunit.xml` forces `farm_management_test`. Keep API and SPA cookie/CORS settings aligned with the API guide.

Verification:

```sh
php -d memory_limit=1G vendor/phpunit/phpunit/phpunit
php -d memory_limit=1G artisan scramble:export --path=docs/api/openapi.json
php artisan app:reconcile
```

Scramble's whole-API type inference needs more than the typical 128M CLI limit; PHPUnit sets a tooling-only 1G budget. This is not a production memory requirement. Xdebug can be disabled for faster local runs. Use the deployment runbook before exposing any environment publicly; do not use the local log mailer for production secrets.
