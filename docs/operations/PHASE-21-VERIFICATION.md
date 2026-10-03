# Phase 21 audit and verification

Base: committed Phase 19 `f1e51e5`; initial working tree clean. Scope: Phases 0–19 only. No migration/package/public endpoint added. The user authorized committing all changes after verification.

## Confirmed findings and fixes

| Finding | Resolution |
| --- | --- |
| Unverified password account linked by Google retained attacker sessions/tokens | Revoke database sessions, API tokens and remember token when discarding the unproven password |
| Queued email and retained inbox alerts could disclose data after access changed | Re-check current membership/user/permission/preferences at delivery; filter inbox/unread/mark-read by current permission |
| Queued exports accepted suspended requesters | Re-check verified, unsuspended account during generation |
| 300-second export timeout exceeded 90-second database visibility | Default retry-after 360; production worker settings documented |
| Duplicate export deliveries could both render a processing row | Atomic queued→processing claim; current locking read for concurrent idempotency replay; processing duplicates do nothing |
| Worker failure or false storage results left incorrect export state | Failure callback updates state; check private writes; preserve expired-file paths on deletion failure for retry |
| API responses lacked consistent browser/cache protection; PDFs unthrottled | API security/no-store headers; invoice PDF shares download limiter |
| Failed queues/backlog lacked explicit operational log events | Payload-free job-failure/backlog logs, scheduled monitor and 30-day failed-job retention |
| Withdrawal test recomputed wall clock; Sales fixtures depended on UTC/Lagos execution hour | Freeze withdrawal clock and anchor sales fixtures to a fixed UTC noon; exact assertions retained |
| CLI 128M insufficient for whole OpenAPI inference | PHPUnit tooling memory set to 1G; production memory unchanged |
| Two PD001 annotations redundant | Remove one annotation each in PurchaseResource and FinanceTransactionResource; no warning suppression |
| Enqueue failure could persist an export/notification that idempotent retries skipped | Domain record/audit and shared-database queue insertion are transactional |
| No operator reconciliation entry point | Read-only `app:reconcile`, exact decimal/source/reversal checks and corruption tests |

## Review coverage and limits

Auth uses stateful Sanctum sessions/CSRF, session regeneration and database revocation. OTPs/reset authorizations are hashed, purpose-bound, expiring, attempt-limited and one-use; Google JWT verifies signature/issuer/audience/expiry. Explicit CORS origins and secure same-site deployment remain mandatory. No normal farm role grants platform authority; support role cannot write. Suspended accounts are denied by middleware and background checks added here. No auth architecture replacement.

Farm-context resolution starts from active memberships, never body ownership. Controllers pass validated Form Request payloads; services scope foreign IDs and enforce permissions. Existing malicious-ID tests cover team/settings, cycles, records/attachments, inventory/lots, health, breeding, work, finance/purchases, sales/invoices/payments, reports/exports, notifications, audit and platform isolation. Model guards are not a substitute for service authorization; raw SQL is reserved for migrations/diagnostics/tests, not public mutation paths.

Population, inventory, operational records, payments, finance and breeding effects retain authoritative writers, farm/cycle row locks, transactions, source unique constraints and append-only reversals. Reconciliation tests use deliberately corrupted SQL fixtures to demonstrate detection and leave healthy rows unchanged. True simultaneous multi-process ledger contention is not proved by sequential PHPUnit tests.

Private attachments use server MIME plus extension/size checks, hash deduplication, generated paths and parent/farm-scoped retrieval. Export retrieval checks requester, farm, permissions, entitlement and expiry; filenames are sanitized, spreadsheet formula text escaped. Invoice PDFs are authorized render-on-demand (not public stored files). Production disk/proxy ACLs and off-host recovery require deployment verification.

Migration review: UUID domain identities, tenant composite FKs, source/idempotency/reversal uniqueness and existing farm/time indexes retained. Phase 1 identity conversion refuses populated legacy bigint users. Later down paths intentionally remove linked effects and are destructive; structural reversibility is not business-data recovery. No speculative indexes or new schema. Fresh migration/restore verification must target only `farm_management_test`; development database is not migrated or wiped by this task.

Performance: paginated resource/admin lists and eager-load/aggregate patterns retained; dashboard constant-query regression retained. Full history population/stock checks and in-memory reports/exports are documented capacity limits. No cache added without measured need. Auth, OTP/reset, Google, invitations, record uploads, report runs/exports/downloads and platform writes retain named rate limits and standard 429 envelopes; PDF reads now throttled too.

Liveness stays lightweight. Runbook covers request IDs, audit safe fields, log access, queue lag/failures, scheduler, TLS/proxy/cookies/CORS, Google/mail configuration, backups/PITR/retention, recovery checks, deploy/rollback and staging smoke/load gates. No secrets committed. No production infrastructure or external provider credentials are available in this task.

## Files and schema

31 changed/new files; no migrations, tables, packages, permissions, entitlements or public endpoints added.

- New operational code: `app/Console/Commands/ReconcileLedgers.php`, `app/Services/Operations/LedgerReconciler.php`.
- New verification: `tests/Feature/Launch/LaunchHardeningTest.php`, `tests/Operations/verify-backup-restore.php`.
- Auth/privacy: `GoogleAuthService`, `AssignRequestId`, `NotificationInbox`, `NotificationGenerator`, `NotificationService`, `FarmAlertNotification`.
- Queue/storage: `ExportService`, both job classes, `AppServiceProvider`, `config/queue.php`, `routes/console.php`; invoice PDF limiter in `routes/api/v1.php`.
- Tooling/tests: two finance/purchase resources, `phpunit.xml`, Google/Health/Sales tests. OpenAPI additionally corrects two nullable `locale` response properties through ordinary inference.
- Docs: project/API READMEs, OpenAPI, `.env.example`, implementation master plan, WORKLOG and these two operations documents.

## Execution record

All results below were obtained on 2026-10-03. Final application/test changes were complete before the full regression started; only documentation was finalized afterward.

| Verification | Result |
| --- | --- |
| Final complete suite | **823 tests, 11,483 assertions; 0 failures, 0 errors; OK**. 356.312 seconds; peak **226 MB**. Includes auth/security/authorization, cross-farm isolation, every domain, dashboard query bound, jobs, notifications, scheduler assertions, API/OpenAPI and localization. |
| Final command | `php -d memory_limit=1G -d xdebug.mode=off vendor/phpunit/phpunit/phpunit` (direct PHPUnit, not an Artisan subprocess). Local log: `storage/framework/testing/phase21-full-suite.log` (ignored diagnostic artifact). |
| Changed-area targeted regression | Launch + Exports + Notifications + GoogleAuth + Health: **84 tests / 1216 assertions**, all passed. |
| Latest launch/export coverage after final storage and replay fixes | **32 tests / 393 assertions**, all passed. Includes 13 new launch tests. |
| Sales after fixed UTC clock | **28 tests / 781 assertions**, all passed. |
| Database queue | Real database push/reservation/release/retry through Laravel Worker: transient failure then successful consumption; no failed job/duplicate notification. Timeout failure callback and export enqueue rollback/storage failure/cleanup retry tested separately. This is in-process Worker verification, not a supervised production process test. |
| Local load fixture in final suite | 30 reads (10 dashboard/tasks/inventory batches), 30 seeded tasks; batch p50 **190.29 ms**, observed p95 **246.84 ms**. Sequential/in-process; no host-capacity guarantee. Dashboard's existing constant-query regression also passed. |
| MySQL backup/restore | Guarded **farm_management_test only**: **77 tables / 331 rows** matched pre-dump fingerprints; private-file fixture checksum matched; restored ledger reconciliation passed; **156.04 seconds** including fresh migration/setup. Linked population, operational, stock and finance fixtures. Development DB untouched. |
| Migration/schema | Entire fresh migration sequence succeeded during restore rehearsal and test bootstrap. Historical down paths reviewed as destructive; no Phase 21 migration or dev migrate required. Full populated historical rollback was not repeated in this phase. |
| OpenAPI | Regenerated after final code: **183 paths, 232 API route entries, zero missing operations, zero warnings**. 49 reachable response schemas scanned: no password/secret_hash/request_hash/remember_token/provider_user_id/file_path properties. The intentional short-lived reset authorization response remains documented. |
| Dependency audit | `composer audit --locked --no-dev --no-interaction`: **No security vulnerability advisories found**. No package installed/updated. |
| Pint | `--test` on **all changed/new PHP files**, passed. |
| Diff | `git diff --check`, passed; final diff reviewed, including newly added files. |
| Scheduler | `schedule:list` confirms all six schedules, including queue monitoring and failed-job pruning. |

Memory conclusion: the full API inference/test process exceeds a 128M CLI budget (final peak 226 MB with Xdebug off); this is development tooling usage. PHPUnit now sets 1G, and the documented export command supplies 1G explicitly. No production architecture or application memory limit was changed. Exact withdrawal assertions remain; sales fixtures now have a stable UTC/Lagos date basis.

Early test-development failures were fixture mistakes (role enum, reversal response status, feed label and Worker binding), corrected without weakening assertions. Final whole-tree regression is entirely green. The user subsequently authorized committing all changes.

## Acceptance decision

Local code/test/restore checks passed as recorded above. The verified changes are included in the user-requested Phase 21 commit; full deployment acceptance is still open. Production-like concurrent load, real SPA/SMTP/Google smoke tests, production database reconciliation and production backup-provider restore remain deployment gates. Until those are recorded, **not every Phase 21 acceptance criterion is met and unconditional production readiness is not claimed**. Phase 20 is intentionally deferred, not an incomplete V1 phase.
