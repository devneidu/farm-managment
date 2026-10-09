# Phase 27 — Marketplace trust, safety and administration

All paths below are relative to `/api/v1`. Authentication is the existing Sanctum SPA session (credentials, Origin and CSRF on writes). Account must be active and email verified. No farm context or onboarding is required. Platform reads require `platform_admins` role `admin` or `support`; writes require `admin` and the existing platform write throttle. Farm ownership confers no platform permission.

## Rules and transitions

* Listings still publish immediately for approved active shops. There is no listing approval queue. Existing `restrict` and `lift-restriction` endpoints remain authoritative.
* Reports are complaints, not findings. Filing, review, dismissal and resolution without an explicitly selected enforcement action never suspend a shop, restrict a listing, change an offer/deal, or change financial records.
* Reports follow `open -> in_review -> dismissed|resolved`. Every administrative transition requires a reason. Terminal reports cannot reopen or change outcome; invalid transitions return `409 invalid_report_state`.
* Active duplicate key is reporter + target + issue (`reason`). `open` and `in_review` both occupy the active slot. Repeating an active complaint returns the original case with `200`; a new case returns `201`. Different issues can coexist. A closed case permits a later new complaint with a different UUID/reference, preserving earlier history.
* Existing deal report rows, UUIDs, references, descriptions, reporter identities and deal state snapshots are retained. Deal reports remain available through their existing participant endpoints and `/platform-admin/marketplace/deal-reports`, now supporting all four statuses.
* Report identity and original complaint fields are immutable. History is append-only. Only platform readers see reporter identity, handler and history. Reporters see their own complaint and outcome; other participants get `404`. Farm audit endpoints must not expose report filing: report audits have `farm_id=null`, including migrated historical deal-report audits.
* Shop suspension hides every listing, freezes seller content changes and blocks new business/paid checkout. It does not cancel existing deals. Their completion, cancellation and reporting permissions remain unchanged. Promotions cannot bypass visibility checks; existing paid periods continue without extension, refund or automatic cancellation.

## Participant APIs

| Method | Path | Inputs | Success |
|---|---|---|---|
| POST | `/marketplace/reports/{target}/{slug}` | `target=shop|listing`; public target slug; body below | 201 new / 200 duplicate |
| GET | `/marketplace/my/reports` | `type=content|deal` (default content), `status`, `page`, `per_page` | 200 own cases |
| GET | `/marketplace/my/reports/{type}/{report}` | `type=content|deal`; report UUID | 200 own case |

Content intake only accepts currently public targets; missing/hidden targets return `404`. Deal participants can still report their own deals in any deal state through `/marketplace/my/deals/{deal}/report` or `/marketplace/shops/{shop}/deals/{deal}/report`, subject to existing buyer identity / shop `deal.respond` checks.

Content body: `reason` is required, one of `suspected_fraud`, `misrepresented_product`, `prohibited_content`, `abusive_behaviour`, `spam`, `other`. `description` is required, 3–2000 characters. Deal categories and optional description retain the Phase 25 contract. All content and deal intake share `marketplace-report`: 20 requests per hour per user, including duplicate attempts (`429`).

Example:

```http
POST /api/v1/marketplace/reports/listing/fresh-catfish
Content-Type: application/json

{"reason":"misrepresented_product","description":"The advertised product differs from the supplied product."}
```

```json
{"data":{"id":"<uuidv7>","reference":"MRP-2026-00001","type":"content","target":{"type":"listing","id":"<listing-uuid>"},"reason":"misrepresented_product","description":"The advertised product differs from the supplied product.","status":"open","outcome_reason":null,"closed_at":null,"created_at":"2026-10-08T14:00:00+01:00"},"meta":{},"message":null}
```

Reporter responses never contain `reporter_id`, `handled_by` or `history`. Closed cases add `outcome_reason` and `closed_at`. The reason supplied when closing is reporter-visible; put no confidential investigative notes or personal contact data in it.

## Platform APIs

| Method | Path | Inputs | Success |
|---|---|---|---|
| GET | `/platform-admin/marketplace/summary` | none | 200 status totals |
| GET | `/platform-admin/marketplace/offers` | `offer_status`, `shop_id`, `listing_id`, `q`, pagination | 200 offers |
| GET | `/platform-admin/marketplace/offers/{offer}` | offer UUID | 200 terms/history, no contact |
| GET | `/platform-admin/marketplace/reports` | `type` (default content), `status`, `reason`, `target_type`, `target_id`, `q`, pagination | 200 queue |
| GET | `/platform-admin/marketplace/reports/{type}/{report}` | type + report UUID | 200 complaint/history |
| POST | `/platform-admin/marketplace/reports/{type}/{report}/transition` | body below | 200 updated complaint/history |

Pagination: `page>=1`, `per_page=1..50` (default 20). Lists order newest first with UUID tie-breaker. `q` is an escaped reference search, max 100 characters. Report statuses: `open`, `in_review`, `resolved`, `dismissed`. `target_type=shop|listing` applies to content cases; `target_id` filters content target UUID or deal UUID. Offer statuses: `pending`, `accepted`, `rejected`, `expired`, `voided`; elapsed pending offers count/read as expired. Invalid inputs return `422 validation_failed`. Existing shop/listing/deal lists, filters and details remain available; shops expose owners and approval/verification information rather than introducing a second seller identity system.

Summary example (entity status maps include states that have rows; report maps always contain all four states):

```json
{"data":{"shops":{"active":2,"suspended":1},"sellers":3,"listings":{"published":4,"restricted":1},"offers":{"pending":1,"expired":2},"deals":{"accepted":1},"reports":{"content":{"open":1,"in_review":1,"resolved":0,"dismissed":0},"deal":{"open":0,"in_review":0,"resolved":1,"dismissed":0},"total":{"open":1,"in_review":1,"resolved":1,"dismissed":0}}},"meta":{},"message":null}
```

Review request:

```json
{"status":"in_review","reason":"Investigating the product description and seller response."}
```

Resolve without enforcement:

```json
{"status":"resolved","reason":"The parties clarified the advertised terms."}
```

Explicit enforcement with resolution:

```json
{"status":"resolved","reason":"The listing contains prohibited content.","enforcement_action":"restrict_listing","enforcement_id":"<reported-listing-uuid>"}
```

`status` required: `in_review|dismissed|resolved`. `reason` required, 3–500 characters. Optional `enforcement_action=suspend_shop|restrict_listing` is allowed only with `resolved`; requires UUID `enforcement_id`. A target without an action returns `422 invalid_enforcement`. The target must be the reported listing/shop, its shop, or the listing/shop of the reported deal; unrelated targets return `422 unrelated_enforcement_target`. A shop report cannot restrict an arbitrarily selected listing. Enforcement calls the existing moderation services in the same transaction as outcome/history/audit. Existing moderation conflicts roll back the whole resolution (for example already-suspended shop); admins can resolve without another action when enforcement was already performed separately.

Admin responses add `reporter_id`, `handled_by` and chronological `history`: `id`, `actor_id`, `from_status`, `to_status`, `reason`, `enforcement_type`, `enforcement_id`, `enforcement_action`, `created_at`. Intake events have no administrative reason. Legacy deal reports get their original filing event on migration. No internal history appears in participant responses.

## Existing endpoint changes

* `POST /platform-admin/marketplace/shops/{shop}/reinstate` now requires `{"reason":"Compliance review completed."}` (3–500 characters). Same state rule: previously approved -> active, otherwise pending_review. Reason is audited.
* `POST /platform-admin/marketplace/listings/{listing}/lift-restriction` now requires the same reason body. Restricted -> paused; seller must publish again and pass the existing active-shop/allowance rules. Reason is in listing history and audit.
* Deal report intake now deduplicates active issues rather than permanently blocking every later report of the same target. Existing `reason` categories and contact/completion/cancellation semantics are unchanged.

## Errors and frontend handling

Common errors retain `{message, code, request_id, errors?|details?}`: `401 unauthenticated`, `403 account_suspended|email_verification_required|platform_admin_required|platform_write_forbidden|forbidden`, `404` for unknown or unauthorized resources, `409 invalid_report_state|invalid_shop_state|invalid_listing_state`, `422 validation_failed|invalid_enforcement|unrelated_enforcement_target`, `429` throttled. Example validation:

```json
{"message":"The given data was invalid.","code":"validation_failed","request_id":"<request-id>","errors":{"reason":["The reason field is required."]}}
```

Lists use `{data:[],meta:{current_page,per_page,last_page,total},message:null}`; detail/writes use `{data:{...},meta:{},message:null}`. Support screens are read-only. Show an explicit enforcement selector and target confirmation; never infer enforcement from category or terminal status. Refresh summary counts after transitions. Keep reporter outcomes in the reporter's private area; never show report counts/badges on public shops/listings or another participant's deal.

## Limits

No account-wide marketplace ban, evidence uploads, assignment queue beyond the last handling administrator, private notes, notifications, appeals/reopening, automated sanctions/fraud scoring, reputation, payment adjudication or refunds. Content intake requires a public target; affected deal parties retain their report endpoint after a suspension. No destructive moderation endpoint. Phase 28 is not included.
