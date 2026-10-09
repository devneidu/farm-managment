# V1 deployment and recovery runbook

Phase 21 hardens Phases 0–19. Phase 20 — **Deferred from V1 / post-launch enhancement** (WhatsApp and AI-assisted parsing); Phase 28 (Community) is deferred the same way. The only payment-provider integration is Paystack for Farmvest's own marketplace seller plans and promotions (Phase 26; see `docs/api/PHASE-26-MARKETPLACE-MONETISATION.md`). Impersonation and a frontend are not included.

## Release gates

- Run the final regression, Pint, OpenAPI export and `git diff --check` against the exact release revision. See [verification record](PHASE-21-VERIFICATION.md).
- Run `php artisan app:reconcile` against the intended launch database. Exit 0 is required. Never edit ledger balances to make it pass; investigate source records and use canonical reversals/corrections. Re-run after restoring a backup.
- Complete a database **and private-file** restore rehearsal with the production backup mechanism, record elapsed time, verify checksums/source links, and obtain operator sign-off. The repository includes a local MySQL rehearsal; that does not prove a hosting provider's backups work.
- Exercise the staging browser flow and concurrent workload below. Set capacity/error/latency targets for the actual host. No production capacity claim is implied by in-process tests.
- Verify SMTP, Google OAuth, HTTPS, cookies, worker, cron, alert routing and off-host backups. These depend on deployment credentials and infrastructure, not repository tests.

## Environment and hosting

Serve **only `public/`**. Deny dotfiles, `.env`, repository/source files, logs and all of `storage/app/private`. Keep writable shared storage outside immutable release directories. PHP 8.2+ with PDO MySQL, bcmath, fileinfo, mbstring, openssl, DOM/XML and ZipArchive; production queue timeouts need CLI PCNTL (Linux worker recommended). Use MySQL 8+ (reconciliation uses window functions).

| Setting | Production requirement |
| --- | --- |
| `APP_ENV`, `APP_DEBUG` | `production`, `false` |
| `APP_KEY` | Generate once securely; preserve with encrypted recovery material. Never regenerate during routine deploys. |
| `APP_URL`, `FRONTEND_URL` | Correct HTTPS API and SPA origins, no trailing slash |
| `SESSION_DRIVER` | `database`; revocation depends on the database session store |
| `SESSION_DOMAIN` | Shared parent domain for related SPA/API subdomains, e.g. `.example.com`; no untrusted apps on that domain |
| `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE` | `true`, `true`, `lax` |
| `SANCTUM_STATEFUL_DOMAINS` | Explicit SPA hosts with ports when needed, **without** schemes; remove localhost |
| `CORS_ALLOWED_ORIGINS` | Explicit HTTPS origins **with** schemes; no wildcard |
| `CORS_SUPPORTS_CREDENTIALS` | `true` |
| `DB_*` | Dedicated least-privilege runtime account, private network/TLS as appropriate; separate migration account; never root |
| `QUEUE_CONNECTION`, `CACHE_STORE` | `database`, `database`; persistent shared cache is needed for throttling, scheduler locks and worker restarts |
| `DB_QUEUE_RETRY_AFTER` | `360` or greater; strictly greater than every job/worker timeout (export: 300 seconds) |
| `QUEUE_FAILED_DRIVER` | `database-uuids` |
| `MAIL_*` | Real transactional SMTP/provider, verified sender; **never `log` in production** (OTP/invitation secrets would enter logs) |
| `FILESYSTEM_DISK`, `REPORT_EXPORT_DISK` | `local` private disk by default; any replacement must enforce private ACLs and authorized application downloads |
| `REPORT_EXPORT_RETENTION_DAYS` | `7` default; monitor pruning and disk capacity |
| `GOOGLE_CLIENT_ID` | Correct web application client audience; configure production origins in Google. Empty disables Google with 503. |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | `stack`, `daily`, `warning` as a baseline; ship logs to restricted central storage and alert on errors |

Behind a reverse proxy, explicitly configure Laravel trusted proxy addresses and forwarded headers in `bootstrap/app.php` for that topology before deployment; never trust arbitrary Internet-supplied forwarding headers. Without it client-IP limits and scheme detection can be incorrect. Terminate TLS at a trusted edge, redirect HTTP to HTTPS and set HSTS there after verifying HTTPS. API responses set `nosniff`, `DENY`, `no-referrer`, and private/no-store caching. Do not cache authenticated API responses at the CDN.

SPA flow: credentialed `GET /api/v1/auth/csrf-cookie`, then send the decoded `XSRF-TOKEN` value as `X-XSRF-TOKEN` on every mutation. Keep credentialed requests enabled. CORS is not authorization. Farm owners receive no platform authority; grant it deliberately with `platform:grant-admin`, choosing support for read-only access. Docs UI/JSON stay restricted in production; share the generated static OpenAPI file with the frontend team.

## Deploy

1. Build from the reviewed lockfile: `composer install --no-dev --prefer-dist --optimize-autoloader`. Run dependency/security checks in CI before promotion. Never install exploratory packages on the server.
2. Confirm a restorable pre-deploy backup and record revision/config version. Put the app into maintenance (`php artisan down`) when a migration requires it; drain/stop workers before incompatible changes.
3. Link the existing shared `.env`, private storage, logs and framework storage; confirm web/worker ownership. Do not link private storage into `public/`.
4. Review pending SQL with `php artisan migrate --pretend`; apply `php artisan migrate --force`. **Never `migrate:fresh`, `refresh`, `db:wipe` or historical rollback in production.** Phase 21 adds no schema migration. Historical phase rollbacks deliberately discard domain rows and effects, so are not data-preserving recovery.
5. Run `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`. Ensure cache creation succeeds before switching traffic.
6. Run `php artisan app:reconcile` in a low-traffic/maintenance window. It locks one farm at a time using the same lock as canonical writers, checks population/crop baselines, stock history/reversals, purchase/sale/health/breeding links, finance reversals/duplicate sources, payment links and invoice totals/balances. Output contains diagnostic UUIDs/counts only. Optional `--farm=<uuid>` narrows investigation. It never repairs data. Very large farms can hold the write lock while history is read.
7. Restart workers with `php artisan queue:restart`; the process supervisor must start replacements. Restart PHP/opcache as required. `php artisan up`, then smoke-test before wider traffic.

## Worker, scheduler and diagnostics

Supervise this long-running command (service account; correct working directory and environment):

```sh
php artisan queue:work database --queue=default --sleep=3 --tries=3 --backoff=30 --timeout=300 --memory=256 --max-time=3600
```

Job-specific settings take precedence: exports one attempt/300 seconds (deterministic failures are recorded on `report_exports`); notification generation two attempts/60 seconds; alert email three attempts/60 seconds/30-second backoff. Set supervisor stop grace to at least 360 seconds; keep visibility/retry-after above timeout. `--memory` governs recycling between jobs, not a per-job PHP allocation ceiling. Size PHP worker memory from measured exports; do not blindly copy the 1G tooling budget. Database queue storage must use the application database. Do not introduce Redis for V1.

Run one scheduler per deployment, every minute:

```cron
* * * * * cd /srv/farm/current && php artisan schedule:run >> /var/log/farm-scheduler.log 2>&1
```

Inspect `php artisan schedule:list`. Schedule times are **UTC** (farm-local date calculations remain inside services): subscriptions hourly; notification fan-out hourly; recurring tasks 00:30; expired exports 02:00; failed-job pruning 02:30 (30 days); queue depth monitoring every five minutes (100 waiting/reserved jobs). `withoutOverlapping` needs the persistent database cache. No scheduled task creates operational facts. If multiple app nodes exist, designate a single cron runner; overlapping locks alone are not a permanent distributed scheduling lease.

Monitor externally: `/api/v1/health` and `/up` are lightweight liveness, not dependency readiness. Check DB connectivity, writable private disk, queue depth/oldest queued age, worker process heartbeat, scheduler heartbeat, free disk, and failed/stuck report rows separately. Alert when no worker processes jobs, when `processing` exports exceed 15 minutes, or when `queued` exports grow old. `queue.backlog` and `queue.job_failed` structured log events expose class/id/connection, never serialized payloads. Route warning/error logs to an operator; the application does not configure an external paging service for you.

Failed private-file deletion makes pruning fail while retaining the path for retry; the expired timestamp continues to deny downloads. Alert on scheduler failures and retry after restoring storage access. Inspect `php artisan queue:failed`; after fixing the cause retry an individual notification job with `queue:retry <uuid>`. SMTP is at-least-once: a transport timeout after acceptance can produce duplicate mail. In-app notifications deduplicate. Failed exports require a **new request/key**, rather than retrying their failed business state. Worker timeouts mark exports failed; a hard-killed worker is resolved by subsequent redelivery/max-attempt failure. Investigate stale processing rows when workers cannot recover. Do not delete diagnostic failed jobs before investigation; daily pruning retains 30 days.

Request IDs accompany API responses and log context/audits. Retain request IDs when reporting incidents. Configure the organization's exception reporter using Laravel's existing reporting hooks; validate with a controlled staging exception. Restrict logs/failed-job tables: framework exception traces can contain operational details. Never enable body/credential logging at the proxy, APM or mail layer.

## Backup and restore

Set an approved RPO/RTO before launch. Baseline expectation: encrypted daily full MySQL backups, binlog/PITR if losing a day's writes is unacceptable, daily private-file snapshots, 30 daily copies plus 12 monthly copies subject to the organization's retention policy. Keep at least one off-host copy, separate credentials and access logs; monitor completion and integrity. Include `storage/app/private` (records and generated exports), database, schema/migrations, release lockfile and protected configuration/key recovery material. Exports may be regenerated, but attachments cannot. If exports are excluded intentionally, restored export rows whose files are missing return 410 and must be requested again.

Use a consistent transaction dump (`mysqldump --single-transaction --no-tablespaces ...`), coordinating a file snapshot/maintenance window so database attachment references and file contents agree. Supply credentials through a protected option file or secret manager, never shell history. Encrypt the resulting archive and manifest/checksums.

Restore drill: isolate networking/mail/queues, verify target identity, restore matching database and private files to a disposable environment, restore the correct APP_KEY, compare every table count/checksum and private-file checksum, run migrations only when required by the matching release, run `app:reconcile`, test authorized attachment/export downloads and denied cross-farm access, run smoke E2E, record elapsed restore time and data-loss window. Only then consider a controlled production restore with an approved cutover. Test at least monthly and after backup/storage changes.

Repository rehearsal (destroys **only** `farm_management_test`; stop PHPUnit first):

```sh
php tests/Operations/verify-backup-restore.php /path/to/mysqldump /path/to/mysql
```

The script forces and verifies that exact database, migrates a fresh schema, creates linked synthetic domain data, dumps/restores via the MySQL clients, compares all table fingerprints, checks a private-file fixture and re-runs reconciliation. It never reads/dumps the development database. It does not certify a remote backup service or production-scale RTO.

## Smoke and load acceptance

On staging with the deployed SPA origin: CSRF cookie → registration → OTP email → verify → farm setup → create livestock cycle → record mortality → verify population/dashboard → stock-in → sale/invoice/payment → export → worker completion → authorized download. Repeat a write with its idempotency key and confirm one effect. Check invalid CSRF (419), missing session (401), foreign farm/IDs (403/404), removed/suspended user denial, ordinary owner denial from platform admin, logout/session expiry, invoice PDF, CORS rejection, SMTP delivery and Google login/linking. Confirm English translation/preferences and request IDs. Use a second user/farm to verify isolation. Remove synthetic staging data only through an approved environment reset, never by modifying production ledgers.

Before opening production traffic, use representative large-farm fixtures on a production-like host and test concurrent dashboard, tasks/calendar, inventory, finance/sales and admin lists plus background exports. Record p50/p95, throughput, 5xx, memory, DB time/locks and queue lag; choose a release threshold based on expected customers. Repository tests preserve the dashboard constant-query property and repeated paginated reads, but are sequential/in-process. Reports assemble result sets before response pagination and PDF rendering is memory-sensitive; ledger history checks scale with accumulated events. These are explicit capacity gates, not grounds for speculative caching/indexes. If measured capacity fails, block launch and optimize the measured path.

## Rollback and deferrals

For this schema-free phase, revert the application release/config together, drain workers and restart with the previous release, then recheck auth/queues. Preserve database/private files. Keep the corrected queue visibility greater than timeout even if reverting code. Reverting security fixes reopens the listed findings, so prefer a forward fix. For schema changes in future, prefer compatible roll-forward; historical `down()` paths delete data. Restore a matched backup only under an explicit incident recovery decision, acknowledging writes since backup will be lost.

Phase 20 is deferred and is not a launch blocker. Paid plan commercial values need operator review; checkout/payment providers, validated additional languages, streamed massive exports, advanced caching, distributed scheduler topology and new product workflows remain post-launch work unless measured deployment needs make an operational change necessary.
