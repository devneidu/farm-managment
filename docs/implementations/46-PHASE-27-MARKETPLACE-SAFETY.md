# Phase 27 — Marketplace trust, safety and administration

Contract: [Phase 27 API](../api/PHASE-27-MARKETPLACE-SAFETY.md). Continues committed Phase 26 (`9f7da99`, merged at `0970912`).

Approved decisions: immediate publication; `open -> in_review -> dismissed|resolved`; confidential reporting with throttling and duplicate protection; existing shop suspension rather than account-wide bans; no automatic paid-period pause, extension, refund or cancellation. Reports never imply enforcement.

Reuse `MarketplaceModerationService`, `MarketplaceListingModerationService`, `MarketplaceDealLifecycle`, existing platform roles/middleware, `PlatformAudit`, `AuditLogger`, reference allocation and resources. Existing deal report rows stay in `marketplace_deal_reports`. Only content reports and shared append-only report events are new tables. Active uniqueness includes reporter, target, category and nullable `open_slot`; closure clears the slot. Existing complaints gain workflow columns and original filing events. Original complaint fields cannot change or be deleted through the models.

`MarketplaceReportService` owns intake/triage and explicit linked enforcement (same transaction); `MarketplaceSafetyDirectory` owns oversight read queries. Admins can resolve without sanction. Selecting an enforcement action invokes existing moderation and validates the report's target relationship. Outcomes never mutate a deal. Reasons are mandatory for handling and for reinstatement/lifting. Contact remains behind its existing separate audited endpoint.

Report audits have no farm scope, protecting confidentiality from farm audit readers; migration moves legacy filing audits without deleting them. Summary exposes all four report statuses, by content/deal and combined, with effective offer expiry. Existing seller oversight is the owner/verification information on shops.

Migration is additive except replacing permanent deal report uniqueness and widening report status. Rollback refuses legacy uniqueness restoration if multiple historical reports exist for one reporter/target, or if new content complaints/administrative history exist; it must not discard cases to fit the old schema. No production/dev fresh/refresh migration is authorized by this phase. Phase 28 is not started.
