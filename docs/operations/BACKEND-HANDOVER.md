# Farmvest V1 backend handover

Concise hand-off for the frontend developer and the operator. Detail lives in the documents linked below; where this page and the code differ, the code and `docs/api/API-CONTRACT.md` win.

## 1. What V1 contains

| Area | Status |
|---|---|
| Phases 0-19 (auth, farms, team, plans, master data, production, records, inventory, health, breeding, work, crops, finance, sales/invoices, dashboard, reports, notifications, platform admin, localization) | Implemented |
| Phase 21 (hardening, launch runbook) | Implemented; infrastructure gates still open (section 6) |
| Phases 22-27 (marketplace: shops, listings, offers/purchase intents, deals and contact exchange, seller plans and promotions, reports and moderation) | Implemented |
| **Phase 20** (WhatsApp, AI-assisted parsing) | **Deferred**, post-launch. No routes exist. |
| **Phase 28** (Community: posts, comments, likes, discussions) | **Deferred**, post-launch. No routes exist. |

Marketplace money rules: buyers and sellers settle **outside** Farmvest. Farmvest only charges *sellers* for its own services (plans, promotions) through Paystack. There is no commission, escrow, wallet, payout, stock deduction or buyer-seller payment.

## 2. Where to look

| Need | Document |
|---|---|
| Frontend contract (auth, CSRF, farm context, envelopes, errors, money, pagination) | `docs/api/API-CONTRACT.md` |
| How to use it from the React app, per feature (sections 1-28 + Phase 27) | `docs/api/FRONTEND-INTEGRATION.md`, `docs/api/FRONTEND-WORKFLOWS.md` |
| Per-phase endpoint contracts | `docs/api/PHASE-*.md` (marketplace: 22-27) |
| Generated OpenAPI | `docs/api/openapi.json` (also `/docs/api` when `APP_ENV=local`) |
| Postman collection, environment, flows, coverage | `docs/postman/` (`WORKFLOWS.md`, `COVERAGE.md`) |
| Deploy, worker, scheduler, backup, rollback | `docs/operations/LAUNCH.md` |
| History and open items | `WORKLOG.md` |

## 3. Using the API in 60 seconds

- Base URL `/api/v1`. Auth is the **Sanctum SPA cookie session**: call `GET /auth/csrf-cookie`, send `Origin` (a stateful domain) and `X-XSRF-TOKEN` on writes. There are no bearer tokens.
- Farm-owned endpoints use the caller's active farm; `X-Farm-Id` (optional) selects another farm the user belongs to. Marketplace endpoints need only a signed-in, verified user (a seller may have no farm).
- Responses use `{data, meta, message}`; errors `{message, code, request_id, errors?}`. Money is a decimal string in naira.
- A new user: register -> verify the emailed OTP -> `GET /auth/me` returns `next_action` (verify email, onboard, ...). Route on it.

## 4. Postman

Import `docs/postman/Farm-Management-API.postman_collection.json` and `Farm-Management-Local.postman_environment.json`, set `base_url`, `frontend_origin`, `password`, `admin_email`, `admin_password`. Run **Initialise CSRF cookie** first; CSRF headers and ids are then automatic.

- Folders 00-28 are the reference (one request per route, bodies and rules). Folder 99 holds **20 executable flows** (Flows 1-19 and S). Run them in the order listed in `WORKFLOWS.md`.
- Run flows against a **disposable database only**. They register users, move stock and money, and are not idempotent (use a fresh database for each run).
- Newman: run all flows in one process (so the cookie session persists), keep `--working-dir docs/postman` (photo upload), and supply the emailed OTP in `otp_code` (read it from `storage/logs/laravel.log` with `MAIL_MAILER=log`). Flow 11 needs a queue worker; Flow 12 needs `php artisan notifications:generate`. Registration is limited to 10/hour per IP: clear the cache between flows.
- Last result (fresh disposable MySQL database, all 20 flows in one Newman process): **457 requests, 1042 assertions, 0 failures**. Details, what could not be executed and the harness notes: `docs/postman/COVERAGE.md`, "Phase 29 addendum".

## 5. Configuration the operator must set

| Setting | Purpose |
|---|---|
| `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL` and `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_*` | Cookie auth across the SPA and API |
| `MAIL_*` (a real transactional provider) | OTP, invitations, password reset. `log` mailer is development only |
| `GOOGLE_CLIENT_ID` | Google sign-in |
| Queue worker + scheduler (`php artisan schedule:run` each minute) | Exports, notifications, task generation, lapsed subscriptions, offer/confirmation expiry, `marketplace:reconcile-payments` (every 10 min). Plan and promotion expiry are read from dates, no job |
| `PAYSTACK_SECRET_KEY`, `MARKETPLACE_PAYMENT_CALLBACK_URL`, Paystack dashboard webhook -> `POST /api/v1/public/marketplace/payments/paystack/webhook` | Seller plans and promotions. Without the key, checkout answers `503 payments_unavailable` and nothing is recorded |
| Feature flags `marketplace_seller_plans`, `marketplace_promotions` (both **off**) | Switch on only after plans/packages are priced by a platform admin and Paystack is verified |
| Platform admin: `php artisan platform:grant-admin <email>` | First administrator |

## 6. Pre-launch follow-ups (not done)

1. **Real Paystack test-mode checkout and webhook verification.** HTTP to Paystack is only exercised against fakes. Run one subscription and one promotion with a test key, confirm the signed webhook and `POST .../payments/{reference}/verify`.
2. **Administrative resolution of paid-but-not-applied payments.** A confirmed payment that cannot be applied is kept as `needs_attention` and listed to admins (`GET /platform-admin/marketplace/service-payments`), but there is no apply/refund action yet.
3. Confirm that the local test database held no important data overwritten during development.
4. The infrastructure gates in `LAUNCH.md` (restore rehearsal with the real backup mechanism, SMTP/OAuth/HTTPS/cookies, representative concurrent load, off-host backups) and the process-concurrency scenarios that are skipped in some environments.

## 7. Known limits

- No refunds, proration, auto-renewal, saved cards, receipts or payment notifications for seller plans and promotions.
- A paid plan always has a listing limit (no "unlimited" option in V1); promoted listings lead only page 1 of `newest`/`relevance`.
- Postman saved example responses exist for folders 00-26; folders 27-28 carry executable flows instead. Paystack-dependent steps cannot run without credentials (see `COVERAGE.md`).
- OpenAPI types a few older responses differently from reality (`COVERAGE.md` section 5); trust the contract and the Postman examples.
