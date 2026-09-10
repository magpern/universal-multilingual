# ADR-0033 — Object-level (page-level) translation review

**Status:** Accepted (2026-09-10, RVQ1)
**Supersedes / relates to:** ADR-0015 (review workflow), builds on it — does not replace it.

## Context

The Review Queue (ADR-0015) is segment-centric: one row per segment, post id
repeated, technical field keys (`post_title`, `post_excerpt`,
`pwoocommerce:product:3602:attribute_name:strength`) dominant, approval
per-segment. On a complex Elementor page or a WooCommerce product a reviewer
easily loses track of which object a row belongs to and can miss a field, then
believe the page is done.

## Decision

Present the queue **object-first**: CONTENT OBJECT → LANGUAGE → FIELDS, and add
an object-level approval action, **without a second review engine, a new
coverage model, or a schema change.**

1. **Grouping.** `WorkspaceService::review_queue_grouped()` wraps the existing
   `Store::query_review_queue()` (same rows, same pagination) and attaches, per
   distinct object on the page: title, post type, an object noun
   (Page/Post/Product), reviewer-friendly field labels, and a completeness
   summary. Surfaced as an additive `objects[]` block on the existing
   `GET /workspace/review-queue` response; the flat `items[]` stays for
   compatibility.

2. **Field labels are server-authoritative.** `FieldLabelResolver` maps
   `field_key` / `segment_key` / post type (and an integration-supplied label
   when present) to a human string. The client renders that value with only a
   trivial generic fallback, so there is no parallel PHP/TS mapping to drift.
   The raw segment key stays available under a "Technical details" disclosure.

3. **Completeness is composed, not computed anew.**
   `ObjectReviewSummary::compose()` combines
   `Store::review_status_counts()` (approved / pending / rejected) with
   `TranslationStatusCalculator::for_post()` (total / missing / stale /
   translated). State: `incomplete` (any untranslated field — always wins) →
   `needs_attention` (a rejected field) → `ready` (pending, nothing missing) →
   `complete`. `is_fully_reviewed` is true only when nothing is pending,
   missing, or rejected.

4. **Object approval = safe-subset over the existing batch service.**
   `WorkspaceService::approve_object()` resolves the **complete** pending set
   for that object + language (`Store::pending_segment_keys()`, unpaginated)
   and feeds it through `ReviewBatchCoordinator::run_batch('approve')` in
   `BATCH_LIMIT`-sized chunks. This inherits ADR-0015's semantics exactly:
   non-atomic, per-item, QA evaluated at approval time. QA-blocked / conflicted
   segments land in `skipped` (with a field label); untranslated / not-submitted
   segments are never touched; audit, reviewer identity, timestamps, hooks and
   capability checks are unchanged. New route
   `POST /workspace/<id>/review/approve-object` (permission: `can_review`).

5. **The result is completeness-honest.** After object approval the UI shows a
   summary such as *"27 approved · 2 need attention · 3 untranslated — page not
   fully reviewed"*, and only drops the "not fully reviewed" clause when
   `is_fully_reviewed` is true. "Page approved" is never shown for a partial
   result.

## Consequences

- Per-segment and per-selection review are unchanged and still available inside
  each group card.
- The completeness summary is global to the object + language; the expandable
  field rows are limited to the current queue page (client requests a larger
  page for the grouped view). A future change could paginate per object.
- No new tables, columns, or indexes. `Migrator::TARGET` stays 10,
  `Settings::SCHEMA_VERSION` stays 3.
