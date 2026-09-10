# RVQ1 — Object-level Review Queue — Closure

**Status:** Implemented, DEV-accepted. Release **proposed** (v1.17.0) — awaiting PO sign-off.
**Date:** 2026-09-10
**Branch:** `feature/object-level-review` (3 feature commits + 1 acceptance-script commit)
**ADR:** [ADR-0033](../adr/0033-object-level-review.md)
**Release notes:** [`docs/releases/v1.17.0.md`](../releases/v1.17.0.md)

## Old workflow

Review Queue = flat list of segments. Post id repeated, page/product title not
prominent, technical keys (`post_title`, `pwoocommerce:product:3602:attribute_name:strength`)
dominant, approval per segment, no "reviewed this whole page" action, no
completeness signal. On a complex product/Elementor page a reviewer could miss
a field and believe the page was done.

## New workflow

Object-first: **CONTENT OBJECT → LANGUAGE → FIELDS.**

- Pending translations are grouped into cards by object + language. Card header:
  title, type noun (Page/Post/Product), language, state chip
  (Ready to approve / Needs attention / Incomplete / Review complete), and a
  completeness summary line.
- Field rows use human labels (server `FieldLabelResolver`); the raw segment key
  moves under a **Technical details** disclosure.
- **Approve page / Approve product** approves every currently eligible pending
  segment for that object + language in one action.
- Per-segment Approve/Reject and per-selection batch approval still work inside
  each card.

## Object-level approval semantics

Safe-subset, non-atomic — identical to per-segment review because it *is*
per-segment review underneath:

- `Store::pending_segment_keys()` resolves the **complete** pending set (no
  pagination cap).
- `WorkspaceService::approve_object()` feeds it through
  `ReviewBatchCoordinator::run_batch('approve')` in `BATCH_LIMIT` (50)-sized
  chunks, so an object with more than one batch of pending fields is fully
  processed (verified: 55 pending → 55 approved on DEV).
- QA is evaluated at approval time; QA-blocked / conflicted segments land in
  `skipped[]` (with a human `field_label`) and stay pending.
- Untranslated / not-submitted segments are never touched.
- Audit events (`ReviewAuditEvents::BATCH_COMPLETED`), reviewer identity,
  timestamps, hooks and capability checks (`aiml_review_translations` +
  `edit_post`) are unchanged.

## Completeness calculation source

Composed, not new: `ObjectReviewSummary::compose()` merges
`Store::review_status_counts()` (approved / pending / rejected, already scoped by
object + language) with `TranslationStatusCalculator::for_post()` (total /
missing / stale / translated). State ordering: **incomplete** (any untranslated
field — always wins) → **needs_attention** (a rejected field) → **ready**
(pending, nothing missing) → **complete**. `is_fully_reviewed` is true only when
pending = missing = rejected = 0.

## Blocker behaviour

Safe-subset (chosen; also the only behaviour consistent with ADR-0015's existing
non-atomic batch contract). The clean pending subset is approved; blocked
segments are returned explicitly, never hidden. The result reads e.g.
*"27 approved · 2 need attention · 3 untranslated — page not fully reviewed"* and
only drops the trailing clause when `is_fully_reviewed`.

## Field-label mapping

Server-authoritative. `FieldLabelResolver::label(field_key, segment_key,
post_type, assembled_label)`:
- integration-supplied label wins when present (WooCommerce etc.);
- core: `post_title`→Title, `post_excerpt`→Short description (product)/Excerpt,
  `post_content`→Description (product)/Content;
- `…:attribute_name:x` → "Attribute: X";
- `b:`→Content block, `e:`→Elementor content;
- else humanized key.
Sent in the review-queue payload as `field_label`. The client
(`utils/field-labels.ts`) renders that value with only a trivial generic
fallback — no parallel mapping.

## Files changed

**PHP**
- new `src/Workspace/FieldLabelResolver.php`
- new `src/Workspace/Review/ObjectReviewSummary.php`
- `src/Translation/Store.php` — `pending_segment_keys()`
- `src/Workspace/WorkspaceService.php` — `review_queue_grouped()`,
  `approve_object()`, `object_review_summary()`, `assembled_label_map()`
- `src/Rest/WorkspaceController.php` — grouped `objects[]` in the queue response,
  `approve_object` handler + route, `get_review_queue` page/per_page defaults
- `src/Rest/ViewModel/ReviewQueueItemViewModel.php` +
  `ReviewQueueItemSerializer.php` — `field_label` / `post_title` / `post_type`

**React** (`assets/translator-workspace/src/`)
- new `components/ReviewObjectGroup.tsx`
- new `utils/field-labels.ts`, `utils/object-review.ts`
- `utils/review-queue.ts` — `groupQueueByObject()`
- rewritten `components/ReviewQueuePanel.tsx`; `components/ReviewQueueRow.tsx`
- `api/workspace-api.ts` — `approveObject()`; `types/view-models.ts`
- `style.css`; rebuilt `build/`

**Docs / tests** — ADR-0033, release notes, CHANGELOG, readme.txt, README,
PluginGuardTest version, this closure.

## Tests added

- unit: `FieldLabelResolverTest` (9), `Review/ObjectReviewSummaryTest` (5)
- integration: `ObjectLevelReviewTest` (6) — grouping + labels; approve-object
  approves every pending segment across >1 batch; other object / other language
  untouched; QA-blocked reported not approved; untranslated keeps incomplete
- jest: `utils/field-labels.test.ts` (4), `utils/object-review.test.ts` (4),
  `utils/review-queue.test.ts` (+2 for grouping)

## Gates

PHPCS clean · PHP unit **1086** · PHP integration **1069** · Jest **120** ·
tsc: no new errors (2 pre-existing on `main`, unrelated) · workspace build ok.

## DEV acceptance (2026-09-10, `dev.biopentra.eu`, branch deployed)

`acceptance/object-level-review-check.php` — **21/21 PASS**:
- A/B: product renders as its own group; title, "Product" noun, "sv", pending
  count 3; labels Title / Short description / Description.
- C: single-field approve touches only that field.
- E/F: object approve approves the clean subset; QA-blocked `post_content`
  reported in `skipped` with a label and stays pending; summary
  `is_fully_reviewed = false`.
- E: page approve leaves the object `incomplete` with `untranslated > 0` (body
  never translated).
- G: unrelated object unaffected.
- **>50: 55 pending seeded → `approved_count` 55, `summary.pending` 0** (mandated
  case: the full eligible set is processed, not just the first batch).

## Schema / storage impact

**None.** `Migrator::TARGET` 10, `Settings::SCHEMA_VERSION` 3. Additive REST
only. No new review engine, no new coverage model.

## Recommended version bump

**v1.17.0** (minor — additive, user-visible, no schema change). Tag/release on
PO sign-off.
