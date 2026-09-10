# ADR-0034 — Multi-language ("object × language") translation workflow

**Status:** Accepted (2026-09-10, MLW1a)
**Relates to:** ADR-0015 (review workflow), ADR-0020 (segment publication gate),
ADR-0031 (user-initiated AI translation), ADR-0033 (object-level review). Builds
on all of them — replaces none.
**Authoritative plan:** `docs/plans/MLW1_MULTI_LANGUAGE_WORKFLOW_IMPLEMENTATION_PLAN.md`
(FROZEN 2026-09-10).

## Context

The translation workflow is one page × one language at a time: load, translate,
repeat for every language. With ~10 configured languages and hundreds of
objects this does not scale and it is easy to forget a language for a page.
The jobs/batch layer is already language-agnostic (one `language_id` per
`aiml_jobs` row; `lock_key` / `idempotency_key` include `language_id`;
`BackgroundTranslationBatchCoordinator::create_bulk_resilient()` already reads a
per-post `language_id`), but every caller above it is scalar-language.

MLW1a makes the operator work with **objects × languages**: load one object with
many languages, language tabs in Workspace, one server-authoritative
object-language completeness model, a Review Queue regrouped by object with all
languages together, "Approve all ready languages", and an N×M Site Translate
matrix — with no schema change and no new publication behaviour.

## Decision

### D1 — `object × language` is the primary workflow model

Every workspace/queue/site-translate surface is keyed by *(object, language)*.
The operator selects objects **and** languages once; single-language work is the
degenerate "one language checked" case.

### D2 — `ObjectLanguageStatus` is server-authoritative (correction C2)

`src/Workspace/ObjectLanguageStatus.php` is a pure value object + composer over
**existing** truth sources only — `ObjectReviewSummary`
(`Store::review_status_counts()` + `TranslationStatusCalculator`),
`SiteTranslateCoverageService` coverage, an active-job probe
(`BackgroundTranslationJobRepository::find_active_by_lock_key()`), the
`Languages` registry status, and a `meta['qa']` error scan. It introduces **no
new query truth**.

PHP owns the entire state-decision algorithm and returns the canonical `state`
plus every count. **TypeScript never re-derives `state`** — `utils/object-language-status.ts`
is presentation-only (`stateLabel`, `stateBadgeVariant`, `stateTone`, and a
trivial fallback when `state` is absent).

**Canonical states** (frozen): `translating`, `not_translated`,
`missing_fields`, `needs_attention`, `pending_review`, `reviewed`, `preview`,
`published`, `translated`.

**`state()` priority** (frozen — PHP only, pure, unit-tested per branch):

1. `has_active_job` → `translating`
2. `review.untranslated > 0` and `review.total === review.untranslated` → `not_translated`
3. `review.untranslated > 0` → `missing_fields`
4. `has_qa_errors` OR `review.rejected > 0` OR `review.stale > 0` → `needs_attention`
5. `review.pending > 0` → `pending_review`
6. `review.approved === review.total` and published segment counts present → `published`, else `reviewed`
7. language `published` and renderable → `published`; language `preview` and renderable → `preview`
8. else → `translated`

### D3 — `ObjectLanguagesSummary` aggregate + frozen `ready_count` (C5)

`ObjectLanguagesSummary` rolls up N `ObjectLanguageStatus` for one object:

- **`target_count` (M)** = the object's eligible **configured target
  languages** — the `Languages` registry minus the default/source language and
  minus `STATUS_DISABLED` languages (i.e. `TranslatorWorkspace::language_bootstrap()`).
  **Not** the operator-selected subset: a language the operator never selected
  still counts against M so it cannot be forgotten.
- **`ready_count` (N)** = languages where
  `missing == 0 && stale == 0 && has_qa_errors == false` and
  `state ∈ {translated, preview, pending_review, reviewed, published}`.
- **`forgotten`** (bool) = any target language with `state == not_translated`.
- `not_translated_count`, `incomplete_count` (`state ∈ {missing_fields,
  needs_attention}`), `translating_count`.
- `"N / M languages ready"` uses exactly `ready_count / target_count`.

"Approve all ready languages" acts on the ready subset that additionally has
`pending > 0`.

### D4 — Review Queue pagination is OBJECT-level (correction C1)

The grouped read model paginates **distinct objects** that have review rows:

```sql
SELECT source_id, MIN(review_submitted_at) AS first_submitted
  FROM {translations}
 WHERE source_type = %s AND review_status IN (…) [AND language_id IN (…)]
 GROUP BY source_id
 ORDER BY first_submitted ASC, source_id ASC
 LIMIT %d OFFSET %d
```

`total` = `COUNT(DISTINCT source_id)` (object count — never segment count).
Prepared SQL, following `Store::query_review_queue()` conventions. For every
object on the page, **all** its review rows across **all** selected languages
are loaded (`Store::review_rows_for_objects()`, no row-level pagination). One
object's languages are never split across pages; `source_id ASC` is the
deterministic tie-break when `first_submitted` ties. The segment-paginated
`Store::query_review_queue()` stays for legacy flat callers but is not the
grouped basis.

### D5 — Multi-object translate authorization (correction C3)

WP REST cannot re-authorize after a `permission_callback` denies. Project
convention (`WorkspaceController::can_batch_review`) already reads the request
body inside `permission_callback`, so all body-dependent authorization happens
there. `POST /aiml/v1/workspace/objects/translate` `permission_callback`
returns true iff:

- `current_user_can(JobsCapabilities::MANAGE_JOBS)`, **OR**
- `current_user_can('edit_post', $id)` is true for **every** id in
  `object_ids[]`.

Otherwise `403 aiml_forbidden`, naming the first failing id. **Invariant: no
cross-product construction and no job creation, mutation, or side effect until
`permission_callback` has authorized every requested object.** If any object
fails, the entire request is rejected and zero jobs are created. Same rule
anywhere an `objects[]` / `object_ids[]` list is accepted.

### D6 — "Approve all ready languages" preserves the >50-segment guarantee (C4)

`WorkspaceService::approve_object_languages(WP_Post, int[] $language_ids)` calls
the v1.17.0 `approve_object()` **once per ready language with `pending > 0`**.
Each `approve_object()` already resolves the **full** pending set
(`Store::pending_segment_keys()`, unpaginated) and approves it in
`ReviewBatchCoordinator::BATCH_LIMIT` (50) chunks until exhausted — so an object
with >50 pending segments in each of two languages has **all** pending segments
in **both** approved. No silent first-50 truncation. `needs_attention`
languages are skipped, never silently approved. Returns per-language
`{approved_count, skipped[], summary}`, an object-level `ObjectLanguagesSummary`,
and an explicit `not_fully_reviewed: bool`.

### D7 — N×M translation coordinator

`src/Workspace/MultiLanguageTranslationCoordinator.php` builds the
*(object × language)* cross-product `{source_type:'post', source_id,
language_id}` for every pair with resolvable work, chunks into
`≤ JobBounds::MAX_POSTS_PER_BULK` (50) calls to
`BackgroundTranslationBatchCoordinator::create_bulk_resilient()` under **one
`batch_id`**, and autostarts. All chunks from one user action share that one
`batch_id`. Idempotency reuses the existing client-token / `idempotency_key`
(which already includes `language_id`) architecture: a rapid duplicate submit
collapses per *(object, language)*; a deliberate re-run after terminal state is
new work. `JobsController::create_bulk_jobs()` is extended to honour a per-post
`language_id` when present. MLW1a uses `automatic: false` only.

### D8 — Published-language honesty (frozen contract — PO decision 3)

MLW1 **never** changes a language's publication status and **never** calls
`PublicationService` to publish content. However, a translation written into a
language that is already `Languages::STATUS_PUBLISHED` **may become visible to
visitors immediately** under the site's existing rendering/publication policy
(with the default `segment_publication_gate_enabled = false`, any non-empty
machine translation renders). Therefore any bulk or multi-language operation
targeting one or more `published` languages **requires explicit
acknowledgement**: the request is rejected (`400`, naming the published
languages) without `acknowledge_published: true`, and the UI shows a clear
pre-run warning. Preview-status languages stay editor-only per existing routing
and need no warning. The plan, ADR, UI copy, and acceptance assertions must not
claim "nothing is ever published publicly".

### D9 — No schema change

All inspection threads confirm no table/column/index change. `Migrator::TARGET`
stays **10**; `Settings::SCHEMA_VERSION` stays **3** (both guarded by
`PluginGuardTest`). No new provider, routing, storage, or review-lifecycle
architecture.

### D10 — MLW1a / MLW1b release split

**MLW1a** (this ADR, authorized): multi-language load + language tabs +
`ObjectLanguageStatus` / `ObjectLanguagesSummary` + object-level Review Queue +
"Approve all ready languages" + N×M Site Translate matrix + pre-run operation
count. **Recommended version: v1.18.0** (minor — additive, user-visible, no
schema).

**MLW1b** (separate future milestone, NOT authorized here): fully automatic
AI mode (translate → QA → auto-submit safe results for review), failure
aggregate, scale UX. Recommended version v1.19.0. No per-object "preview"
transition is persisted; "automatic mode" never changes language status and
never calls `PublicationService`.

## Consequences

- One additive PHP composition layer (`ObjectLanguageStatus` /
  `ObjectLanguagesSummary`) becomes the single source of truth for object ×
  language state, consumed verbatim by the workspace tab strip, Review Queue
  cards, Site Translate rows, and the post picker.
- The Review Queue read model changes shape (object-first pagination); the
  legacy flat `items[]` path is retained for compatibility.
- Operators must acknowledge published-language writes for every bulk / N×M
  operation — one extra confirmation, deliberately.
- No migration, no rollback risk from schema; the branch is DEV-deployable and
  revertible by checkout.
