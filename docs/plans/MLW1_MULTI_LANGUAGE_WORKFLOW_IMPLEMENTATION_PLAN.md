<!--
  STATUS: FROZEN — IMPLEMENTATION AUTHORIZED (MLW1a)
  Frozen: 2026-09-10
  Authoritative architectural contract: docs/adr/0034-multi-language-workflow.md
  Baseline: main @ v1.17.0, Migrator::TARGET = 10, Settings::SCHEMA_VERSION = 3
  Scope of this freeze: MLW1a work packages WP0–WP8 only.
  MLW1b (automatic AI mode) remains a separate, future, NOT-authorized milestone;
  its outline is retained here for context only.
  All plan-review corrections C1–C5 and PO decisions 1–3 below are FROZEN and
  may not be renegotiated during implementation.
-->

# MLW1 — Multi-Language Workflow & Automatic AI Translation

**Status:** Plan — architecture inspection complete. Implementation NOT started. Awaiting approval.

**Repo:** `/opt/biopentra/dev/universal-multilingual`, `main` @ **v1.17.0**, `Migrator::TARGET` 10, `Settings::SCHEMA_VERSION` 3. The DEV checkout is bind-mounted into live DEV — implement in the separate clone `/tmp/claude-1000/-opt-biopentra/a77ababa-f08b-450e-b159-e6056d64e38e/scratchpad/ait1`. Docker-only tooling (no host PHP; host Node 22 for the workspace bundle). PROD not authorized. No live paid provider key beyond the existing DEV OpenAI config.

---

## Context

The translation workflow is language-by-language: pick one page, pick one language, load, translate, repeat for every language. With 10 languages × hundreds of pages this is unworkable and it is easy to forget a language for a page. This milestone makes the operator work with **objects × languages** instead of one language at a time, makes forgotten languages impossible to miss, and adds an optional fully-automatic AI pipeline that translates + QA-checks + submits safe results for review. It never changes language publication state and never calls `PublicationService` (see decision 3 for the exact published-language contract).

**Product principle:** for every page/product, the operator sees every target language and its state in one place; for bulk work they select objects **and** languages once.

### Decisions taken (from PO Q&A during planning)

1. **Automatic mode = translate → QA → auto-submit safe results for review.** After a `translate_missing` job completes for an (object, language), the QA-clean, submittable segments are auto-submitted (`review_status → pending`) through the existing review service. QA-failed / stale / job-failed / missing-required object-languages are **not** submitted — they surface as **Needs attention**. No review/publish columns written by hand, no schema change.
2. **Ship split.** **MLW1a** (this plan's WP breakdown): multi-language load + language tabs + `ObjectLanguageStatus` summary + Review Queue regrouped by object + "Approve all ready languages" + N×M Site Translate matrix + pre-run operation count. **MLW1b** (separate release, outlined here): fully automatic AI mode + failure aggregate + scale UX.
3. **Published-language honesty (frozen contract).** MLW1 **never** changes a language's publication state and **never** calls `PublicationService` to publish content. However, a translation written into a language that is already `STATUS_PUBLISHED` **may become visible to visitors immediately** under the site's existing rendering/publication policy (with the default `segment_publication_gate_enabled = false`, any non-empty machine translation renders). Therefore the published-language **pre-run warning + explicit acknowledgement is mandatory** for any bulk or automatic operation targeting one or more published languages — the request is rejected without `acknowledge_published: true`. Preview-status languages stay editor-only per existing routing rules and need no warning. Do **not** state "nothing is ever published publicly" anywhere in the plan, ADR, UI copy, or acceptance assertions.

### Corrections applied from plan review (authoritative — freeze conditions)

- **C1 — Review Queue pagination is OBJECT-level, not segment-level.** The read model paginates distinct **objects** that have review rows via a proper grouped query:
  ```sql
  SELECT source_id, MIN(review_submitted_at) AS first_submitted
    FROM {translations}
   WHERE source_type = %s AND review_status IN (…) [AND language_id IN (…)]
   GROUP BY source_id
   ORDER BY first_submitted ASC, source_id ASC   -- deterministic tie-break
   LIMIT %d OFFSET %d
  ```
  `total` = `SELECT COUNT(DISTINCT source_id) …` (object count, never segment count). Prepared SQL, following the `Store::query_review_queue()` conventions. For each object on the page, load **all** its review rows across **all** selected languages. One object's languages are never split across pages; `source_id ASC` secondary sort keeps objects stable when timestamps tie. `Store::query_review_queue()` (segment-paginated) stays for legacy flat callers but is not the grouped basis.
- **C2 — `ObjectLanguageStatus` is server-authoritative.** PHP owns the entire state-decision algorithm and returns canonical `state` + counts. TypeScript never re-derives state — it only maps `state` → label / badge variant / tone and provides a trivial fallback when `state` is absent. No parallel decision logic in TS.
- **C3 — Multi-object translate authorization.** WP REST cannot "fall back" after a `permission_callback` denies. Project convention (`WorkspaceController::can_batch_review`) already reads the request body inside `permission_callback`, so **all body-dependent authorization happens there**: the `permission_callback` for `POST /workspace/objects/translate` returns true iff `current_user_can(JobsCapabilities::MANAGE_JOBS)` **OR** `current_user_can('edit_post', $id)` is true for **every** id in `object_ids[]` (403 `aiml_forbidden` naming the first failing id otherwise). **Invariant: no job creation until every requested object is authorized** — the handler never constructs the cross-product or mutates anything before `permission_callback` has passed. Same rule anywhere `objects[]` is accepted.
- **C4 — "Approve all ready languages" preserves the >50-segment guarantee.** It processes **every** eligible pending segment for **every** selected (object × language) in bounded `ReviewBatchCoordinator::BATCH_LIMIT` (50) chunks until exhausted — the v1.17.0 `approve_object()` behaviour, applied per language. No silent first-50 truncation. Explicit regression test: an object with >50 pending segments in each of two languages → all approved.
- **C5 — Frozen `ready` definition** (see the model section): a target language counts toward `ready_count` ("N / M languages ready") when its translation is **complete and clean** — `missing == 0`, `stale == 0`, `has_qa_errors == false`, and `state ∈ {translated, preview, pending_review, reviewed, published}`. `M` = the object's eligible configured target languages. `not_translated`, `missing_fields`, `translating`, `needs_attention` are never "ready". "Approve all ready languages" acts on the ready subset that additionally has `pending > 0`.

### Non-goals (explicit)

No automatic public publishing, no automatic language-status promotion, no new provider architecture, no new routing architecture, no schema redesign, no SEO work, no translation-promotion changes.

---

## Architecture findings (inspection results)

### F1 — Jobs / batch model is already language-agnostic; callers are not

- `aiml_jobs` row carries exactly **one** `language_id` (scalar column). `lock_key` = `"{source_type}:{source_id}:{language_id}"` and `idempotency_key` both include `language_id`, so N distinct (object, language) jobs never collide. `UNIQUE KEY active_lock_key` enforces one *active* job per object+language.
- `BackgroundTranslationBatchCoordinator::create_bulk_resilient($posts, $language_id, $shared, $batch_id)` already reads **per-post `language_id`** (`$post_args['language_id'] ?? $language_id`, line ~144) and creates one independent child job per element. `batch_id` is a **non-unique tag** (`KEY batch_id`), nothing couples children to one language. Class docblock: *"Creates independent child jobs sharing batch_id — no parent aggregate."*
- **Blockers to N×M today:** (a) `SiteTranslateBatchService::create_jobs()` takes a scalar `$language_id` and its partial-retry dedupe keys on **`source_id` only** (`$existing_source_ids[$job->source_id]`) — would treat a page done in language A as "done" for language B; (b) `JobsController::create_bulk_jobs()` doesn't read per-post `language_id` (the coordinator would); (c) `SiteTranslateController` payload is `post_ids[]` + `language_id` (singular); (d) `JobBounds::MAX_POSTS_PER_BULK = 50` per create call → chunk N×M into ≤50-job calls under one `batch_id` (SiteTranslateBatchService already threads one `batch_id` across chunks).
- `BackgroundTranslationJobRepository::query({status, language_id, batch_id, page, per_page})` and `list_recent_by_object($source_type, $source_id, $language_id, $limit)` and `find_active_by_lock_key($lock_key)` already exist — a multi-language batch can be filtered/grouped by language with **no repo change**. `batch_progress()` aggregates by summing child counters and has no language dimension — a per-language progress view groups children client-side or via a thin helper.
- **No cost/token pre-estimation exists.** `BackgroundTranslationBudgetPolicy::preflight()` is a deliberate no-op ("token cost cannot be truthfully estimated from segment count"). Budget is runtime-only on integer counters. → Pre-run UX shows **operation counts only** (objects × languages × missing-segments), never a fabricated cost.
- Job completion fires `do_action('aiml_translation_job_audit', 'translation_job_completed', $payload)` (`BackgroundTranslationJobAuditEvents::COMPLETED`) — the hook MLW1b's follow-up step keys off.

### F2 — There is NO per-object "Preview" state

- **"Preview" is a LANGUAGE property**: `Languages::STATUS_PREVIEW`. `LanguageResolver::is_routable($lang, $viewer_can_preview)` returns `true` for `published`, and for `preview` only when `$viewer_can_preview` — which `Router`/`Switcher` set from `current_user_can(Plugin::CAPABILITY)`. So a preview language's `/xx/` renders **for capability holders only**, invisible to the public. Transition guard: `disabled → preview → published` (`Languages::can_transition`).
- **Per-segment publish axis** (`Store::PUBLISH_UNPUBLISHED|PUBLISH_PUBLISHED`, `PublicationService`, ADR-0020) only ever targets **public** visibility and only matters when `segment_publication_gate_enabled` (default **false**). Default install: `Store::is_publicly_overlay_eligible()` returns true for any non-empty non-ignored/non-missing segment — no publish call needed, no viewer awareness.
- `PublicationService::maybe_auto_publish()` targets `PUBLISH_PUBLISHED` (public), gated by `auto_publication_mode` (default `manual` = no-op) + `PublicationPolicy`. **There is no `maybe_auto_preview`.** `PreviewService::preview_url()` is a pure URL builder.
- `ReviewWorkflowService::approve()` writes only `review_status`; it does **not** touch publish or call `PublicationService`. No `aiml_review_approved` action, no auto-advance.
- **Consequence for automatic mode:** "safe translation moves to Preview" needs **no persisted transition**. When target languages are in `preview` status (a one-time admin action, out of MLW1 scope), a written machine translation renders on `/xx/` for editors only. When a target language is already `published`, the same write may render for the public immediately under the existing gate policy (see decision 3) — MLW1 does not cause this, it just does not prevent it, and the mandatory acknowledgement makes it explicit. "Preview" / "Needs attention" are **computed** per-(object, language) states.
- The pipeline's hard gate on a machine translation is `ResponseValidator` (structural: placeholder/HTML/URL/number/empty → 422 → job item FAILED). The softer `QAEngine` (SharedDetector/Variable/Punctuation) is attached as `meta['qa']` and enforced on manual save (`qa_block_on_error`) and on approve (`assert_qa_passes_for_approval`). Automatic-mode "QA pass" = ResponseValidator passed (already enforced) **and** no `meta['qa']` errors on the object's segments (read-time check).

### F3 — No single read model covers all per-(object, language) states

| State | Available from |
|---|---|
| not translated / missing | `TranslationStatusCalculator::for_segments()` `missing_count`; `SiteTranslateCoverageService` `missing` |
| translating | **Jobs only** (`BackgroundTranslationJobRepository`, keyed by source_type/source_id/language_id) — never joined to a status model |
| translated | `TranslationStatusCalculator` `translated_count`+`reviewed_count` |
| pending review / approved / rejected | `Store::review_status_counts($source_type, $source_id, $language_id)` → `{not_submitted, pending, approved, rejected}` |
| stale | both calculators |
| published (segments live) | **only** `SiteTranslateCoverageService::coverage_for_post()` `published`/`unpublished` |
| preview / language-not-public | `Languages` registry `status` (already sent to JS as `LanguageOption.status`) |
| needs attention | derived |

- `ObjectReviewSummary::compose(Store::review_status_counts(), TranslationStatusCalculator::for_segments())` (added v1.17.0) is the **intended thin composer** and already yields `total/untranslated/translated/pending/approved/rejected/stale` + a 4-value `state` (`incomplete`/`needs_attention`/`ready`/`complete`), scoped per object+language, wired through `WorkspaceService::object_review_summary()` and the `/review-queue` `objects[]` payload. It is **missing** the publish/preview axis and "translating".

### F4 — Frontend state

- `App.tsx` keys the editor to `(postId, languageCode)` via a `useCallback` dep array (`loadSegments`). Changing `languageCode` today **resets `postId` to null** (`LanguageSelect onChange`, line 1117). Every editor mutation early-returns `if (!postId || !languageCode)`.
- `viewMode: 'editor'|'queue'|'jobs'|'operations'|'site-translate'`; the top tab bar is hand-rolled `role="tablist"` markup (not a component). `aiml-ui.css` has `.aiml-ui-subnav` pill styles and **all** needed badge modifiers: `--preview`, `--translating` (animated), `--published`, `--stale`, `--complete`, `--needs-review`, `--queued`, `--failed`, `--missing`. `StatusBadge.tsx` is the single badge component. **No React Tab/Tabs component** (`@wordpress/components` `TabPanel` is available but unused).
- `window.aimlTranslatorWorkspace.languages` = `TranslatorWorkspace::language_bootstrap()` — already **excludes default + `STATUS_DISABLED`**; each entry `{language_id, code, name, native_name, status}`. This is exactly "all configured target languages".
- `SiteTranslatePanel` = strictly "pick 1 language → N object checkboxes"; persists selected ids in `?aiml_st_ids=`.
- `ReviewQueuePanel` / `ReviewObjectGroup` (v1.17.0): one group per (object, language); `groupQueueByObject()` returns `response.objects` verbatim.
- Workspace-eligible types: `AdmittedPostTypes::WORKSPACE_TYPES = ['post','page','product','nav_menu_item']` (SiteTranslate WP_Query uses `['page','post','product']`).
- Jest: pure utils only, `src/utils/*.test.ts` next to source, no component tests. PHP: `tests/integration/` + `WorkspaceTestHelpers` trait (`review_queue_request()`, `batch_review_request()`, `create_translator/reviewer()`, `add_language()`).

### F5 — Schema impact: **NONE**

All three inspection threads independently confirm no table/column/index change is required. `Migrator::TARGET` stays 10, `Settings::SCHEMA_VERSION` stays 3. `PluginGuardTest` asserts both.

---

## UX model

**Load:** `Content: [ page ]` + `Languages:` checklist (`[x] All languages` + one row per target language, default all checked) → `Load`. Deselecting narrows the tab set. Single-language work = check one.

**After load — one object, language tabs:**
```
Shop
[ Swedish ✓ ] [ German ● Translating ] [ Danish ⚠ 3 missing ] [ + Polish ]     2 / 3 languages ready
```
Clicking a tab swaps the language *without* changing the object or reloading the page; segments for a tab are fetched lazily on first open and cached in component state. Each tab shows an `aiml-ui-badge` for its `ObjectLanguageStatus.state` (icon + text, never colour alone). The tab strip wraps; ≥ ~8 languages collapse the overflow into a "More ▾" menu (only if 10-language testing shows a problem).

**Page-level actions (one primary + a menu, not a wall of buttons):**
- Primary: **`Translate <current language> with AI`** (current tab).
- Menu: `Translate all selected languages with AI` · `Approve <current language>` · `Approve all ready languages`.

**Object completeness (reused everywhere):** `About — Page · Swedish Preview · German Preview · Danish Missing · Polish Pending review · 2 / 4 languages ready`.

**Site Translate matrix:** object checkboxes (existing) + **language checklist** (`[x] All languages`). Pre-run summary strip: *"4 pages × 3 languages = 12 translations"* → `Start AI translation` (with the published-language warning when applicable). Selected languages persist in `?aiml_st_ids` + a new `?aiml_st_langs` for the session.

**Review Queue:** one card per **object**, language tabs/accordion inside:
```
Hexarelin — Product #3602
[ Swedish 5 pending ] [ German Complete ] [ Danish 2 pending · 1 untranslated ]
4 target languages · 1 complete · 1 pending review · 1 incomplete · 1 not translated
[ Approve all ready languages ]   [ Open in Workspace ]   [ Edit product ]
```

---

## Object-language status model

**New: `src/Workspace/ObjectLanguageStatus.php`** — a pure value object + composer, the single source of truth for "object × language" state. Composes existing read models only (no new query truth):

```
ObjectLanguageStatus::compose(
    ObjectReviewSummary $review,          // review_status_counts + TranslationStatusCalculator
    array $coverage,                      // SiteTranslateCoverageService::coverage_for_post() -> published/unpublished
    bool  $has_active_job,                // BackgroundTranslationJobRepository::find_active_by_lock_key()
    string $language_status,              // Languages->status  (preview|published)
    bool  $has_qa_errors                  // meta['qa'] error scan over the object's segments
): self
```

`state()` priority — **PHP only** (pure, server-authoritative; unit-tested in PHP). TypeScript never re-implements this; `utils/object-language-status.ts` is presentation-only (`stateLabel`, `stateBadgeVariant`, `stateTone`, absent-value fallback):
1. `has_active_job` → **`translating`**
2. `review.untranslated > 0` and `review.total === review.untranslated` → **`not_translated`**
3. `review.untranslated > 0` → **`missing_fields`**
4. `has_qa_errors` OR `review.rejected > 0` OR `review.stale > 0` → **`needs_attention`**
5. `review.pending > 0` → **`pending_review`**
6. `review.approved === review.total` and (`published` counts) → **`published`** / else **`reviewed`**
7. language `published` and renderable → **`published`**; language `preview` and renderable → **`preview`**
8. else **`translated`**

`to_array()` carries every count + canonical `state` + `language_code`/`language_name`/`language_status`.

**Frozen aggregate — `ObjectLanguagesSummary`** rolls up N `ObjectLanguageStatus` for one object:
- `target_count` (**M**) = the object's eligible **configured target languages** (from `Languages` registry, excluding default + `STATUS_DISABLED`, i.e. `TranslatorWorkspace::language_bootstrap()`). Not the "selected" subset — a language the operator never selected still counts against M so it cannot be forgotten.
- `ready_count` (**N**) = languages where `missing == 0 && stale == 0 && has_qa_errors == false && state ∈ {translated, preview, pending_review, reviewed, published}`.
- `not_translated_count` = `state == not_translated`; `incomplete_count` = `state ∈ {missing_fields, needs_attention}`; `translating_count` = `state == translating`.
- `forgotten` (bool) = any target language with `state == not_translated`.
- `"N / M languages ready"` uses exactly `ready_count` / `target_count`.

**New endpoint:** `GET /aiml/v1/workspace/{post_id}/languages` (perm `can_edit_post`) → `{ post_id, post_title, post_type, object_noun, languages: [ObjectLanguageStatus::to_array()], summary: ObjectLanguagesSummary }` — all fields, including `state`, computed server-side. Reused verbatim by the workspace tab strip, Review Queue cards, Site Translate rows, and the post picker.

**Performance:** `ObjectReviewSummary::compose()` runs `assemble_for_post()` once per language, so this endpoint is ~M assembles for one object — fine for a per-object detail view. The Site Translate matrix and the Review Queue page must **not** call it per row in a loop; they use a batched path (`review_rows_for_objects()` + `SiteTranslateCoverageService::coverage_for_ids()` per language + one `review_status_counts()` scan) that assembles each (object, language) at most once per request.

---

## Review → preview contract (for MLW1b, recorded now)

There is no per-object promotion. Automatic mode's flow per (object, language):
1. `translate_missing` job (existing pipeline; `ResponseValidator` gates each write).
2. On `translation_job_completed`, a bounded follow-up Action Scheduler action (`aiml_auto_translate_followup`, one per child job) runs:
   - `ensure_slug_candidate()` (v1.16.0 — title-driven, never overwrites a manual slug).
   - Scan the object's segments for `meta['qa']` errors and stale/missing-required.
   - If clean → `ReviewBatchCoordinator::run_batch($post, $language_id, 'submit', $submittable_keys)` (existing service; `review_status → pending`). This is the "moved to Preview / into the review worklist" step.
   - If not clean → leave as-is; the computed `ObjectLanguageStatus.state` is `needs_attention`.
3. Nothing calls `PublicationService`. Nothing changes language status.

The follow-up coordinator identifies "automatic" batches via a bounded `aiml_automatic_batches` option (list of `{batch_id, created_at}`, pruned to N most recent) — no schema.

---

## Multi-language Jobs / batch approach

- **New `POST /aiml/v1/workspace/objects/translate`** — body `{ object_ids[], language_ids[], job_type ('missing'|'stale'|'machine'), automatic: bool, client_token, acknowledge_published: bool }`.
  - **Authorization (C3):** the `permission_callback` reads the request body (project convention — cf. `WorkspaceController::can_batch_review`) and returns true iff `current_user_can(JobsCapabilities::MANAGE_JOBS)` **OR** `current_user_can('edit_post', $id)` for **every** id in `object_ids[]` — else `403 aiml_forbidden` naming the first failing id. Invariant: **no cross-product construction and no job creation until `permission_callback` has authorized every requested object.**
  - Builds the `$posts` list as the **cross-product** `{source_type:'post', source_id, language_id}` for every (object × language) with resolvable work, chunks into ≤`MAX_POSTS_PER_BULK` calls to `BackgroundTranslationBatchCoordinator::create_bulk_resilient()` under **one `batch_id`**, autostarts. Returns `{ batch_id, planned: {objects, languages, operations, skipped_no_work}, autostarted }`.
- **`SiteTranslateBatchService::create_jobs()`** → accept `int[] $language_ids` (keep the scalar overload working); dedupe partial-retry on **`(source_id, language_id)`** not `source_id`; emit one scope entry per (post, language) carrying its own `language_id` (the coordinator already honours it).
- **`SiteTranslateController`** `/objects`, `/coverage`, `/jobs` → accept `language_ids[]` (fall back to `language_id`); `/coverage` loops `coverage_for_ids()` per language.
- **`JobsController::create_bulk_jobs()`** → read per-post `language_id` from `posts[]` when present (one-line change; the coordinator already supports it) so the generic API can also express N×M.
- Progress: extend `batch_progress()` consumer (client) to group child jobs by `language_id` for the per-language progress view; add a thin `BackgroundTranslationBatchCoordinator::batch_progress_by_language($batch_id)` if the grouping is non-trivial.
- Idempotency: the cross-product endpoint mints one `client_token`; each child job's `idempotency_key` already includes `language_id`, so a double-submit collapses per (object, language) and a deliberate re-run after terminal state is new work (existing `JobIdempotencyKey` behaviour).

---

## Automatic-mode safety contract (MLW1b)

An (object, language) is auto-submitted for review **only if all** hold:
- its `translate_missing` job reached `completed` (not failed / not paused / not budget-exceeded),
- no job item is `FAILED`,
- `SegmentAssembler` shows **0** missing eligible segments for that (object, language),
- **0** `meta['qa']` errors across its segments,
- **0** stale segments,
- no `manually_edited` / `reviewed` / in-review segment was touched (the pipeline already skips these — `TranslatableSegmentEligibility::is_protected`),
- localized-URL state is at least "candidate present" (via `ensure_slug_candidate`; a slug failure that is not `aiml_slug_manual_locked` blocks).

Any failure → **not submitted**, `ObjectLanguageStatus.state = needs_attention`, listed in the run's failure aggregate. MLW1b never changes language publication state and never calls `PublicationService`; per decision 3, a translation written into an already-`published` target language may still become publicly visible under the site's existing gate policy, which is why the pre-run confirmation names every `published` target language and requires `acknowledge_published`.

---

## Screens / components affected

**PHP (all additive — no schema):**
- **new** `src/Workspace/ObjectLanguageStatus.php` (+ `ObjectLanguagesSummary` in the same file or sibling) — pure composer over `ObjectReviewSummary`, `SiteTranslateCoverageService`, jobs probe, `Languages`.
- **new** `src/Workspace/MultiLanguageTranslationCoordinator.php` — builds the (object × language) cross-product, chunks to `BackgroundTranslationBatchCoordinator`, records the batch. (MLW1b adds `AutomaticTranslationFollowup` hooked to `translation_job_completed`.)
- `src/Workspace/WorkspaceService.php` — `object_languages_summary(WP_Post, int[] $language_ids)`; new `approve_object_languages(WP_Post, int[] $language_ids)` that calls the existing v1.17.0 `approve_object()` **once per language** and aggregates. Each `approve_object()` already resolves the **full** pending set via `Store::pending_segment_keys()` and approves it in `ReviewBatchCoordinator::BATCH_LIMIT` (50) chunks — so the >50-segment guarantee (**C4**) is preserved per language automatically. `approve_object_languages()` only approves languages whose `ObjectLanguageStatus` is not `needs_attention` and that have `pending > 0`; it returns a per-language `{approved_count, skipped[], summary}` plus an object-level `ObjectLanguagesSummary` and an explicit `not_fully_reviewed: bool`.
- `src/Rest/WorkspaceController.php` — `GET /{post_id}/languages`; `POST /objects/translate` (multi-object auth per **C3**); `POST /{post_id}/review/approve-object` gains optional `languages[]`; `get_review_queue` gains `languages[]`.
- **Review Queue object-level pagination (C1).** New `src/Workspace/WorkspaceService.php::review_queue_by_object()` replaces `review_queue_grouped()` as the grouped source. New Store methods:
  - `Store::query_review_object_ids(array $args): {object_ids: int[], total: int, page: int, per_page: int}` — the grouped `SELECT source_id, MIN(review_submitted_at) AS first_submitted ... GROUP BY source_id ORDER BY first_submitted ASC, source_id ASC LIMIT %d OFFSET %d` from C1; `total` from `COUNT(DISTINCT source_id)`. Prepared SQL, `query_review_queue()` conventions.
  - `Store::review_rows_for_objects(int[] $source_ids, int[] $language_ids, string $review_status): object[]` — **all** matching hydrated rows for those objects across all selected languages, ordered `source_id, language_id, review_submitted_at, translation_id` (no row-level pagination).
  Then `review_queue_by_object()` builds one group per object with `languages: [{language_id, language_code, language_name, summary: ObjectLanguageStatus::to_array(), items: [...]}]` + `ObjectLanguagesSummary`.
- `src/SiteTranslate/SiteTranslateBatchService.php`, `src/SiteTranslate/SiteTranslateCoverageService.php`, `src/Rest/SiteTranslateController.php` — `language_ids[]`, `(source_id, language_id)` dedupe, per-language coverage.
- `src/Jobs/JobsController.php` — per-post `language_id` in `create_bulk_jobs()`.
- Release-time: `universal-multilingual.php`, `readme.txt`, `CHANGELOG.md`, `README.md`, `tests/integration/PluginGuardTest.php`, `docs/releases/vX.Y.Z.md`, `docs/adr/0034-multi-language-workflow.md`.

**React (`assets/translator-workspace/src/`):**
- **new** `components/LanguageChecklist.tsx` (multi-select, "All languages" master), `components/LanguageTabs.tsx` (accessible `role="tablist"`, wrap + "More", keyboard, aria-selected, badge per tab), `components/ObjectLanguagesBar.tsx` (the "N / M languages ready" strip).
- **new** `utils/object-language-status.ts` — **presentation only** (`stateLabel(state)`, `stateBadgeVariant(state)`, `stateTone(state)`, absent-value fallback); never re-derives `state` — that is server-authoritative (**C2**). `+ .test.ts` covers the label/variant maps only. `utils/multi-language-selection.ts` (all/none/toggle over the language list, operation-count) `+ .test.ts`.
- `App.tsx` — decouple `languageCode` change from `postId`; hold `selectedLanguageCodes: string[]` + `activeLanguageCode`; per-language segment cache `Map<code, {rows,status}>`; render `LanguageTabs` in the editor; wire the page-action menu.
- `components/SiteTranslatePanel.tsx` — language checklist, pre-run count strip, published-language warning, `language_ids[]` API calls.
- `components/ReviewQueuePanel.tsx` + `ReviewObjectGroup.tsx` — one card per object, nested `LanguageTabs`, "Approve all ready languages".
- `api/workspace-api.ts` / `api/site-translate-api.ts` — `fetchObjectLanguages()`, `translateObjects()`, `approveObjectLanguages()`, `language_ids[]` params.
- `types/view-models.ts` — `ObjectLanguageStatus`, `ObjectLanguagesSummary`, restructured `ReviewObjectGroup`.
- `style.css` / reuse `aiml-ui.css` badges + `.aiml-ui-subnav`; add `.aiml-language-tabs`, `.aiml-object-languages-bar`.
- Rebuild `assets/translator-workspace/build/`.

---

## Work packages — MLW1a

Lifecycle per WP: one commit, Tier-0 gates (phpcs, unit incl. `PluginGuardTest`, integration, jest, workspace build), then DEV deploy of the branch + acceptance, then closure doc. Release only after PO acceptance.

- **WP0** — Freeze this plan + draft `docs/adr/0034-multi-language-workflow.md`. ADR records: object×language model; `ObjectLanguageStatus` server-authoritative with frozen `state` + `ready_count` definitions (C2, C5); Review Queue object-level pagination via the grouped query (C1); multi-object translate authorization in a body-reading `permission_callback` (C3); "Approve all ready languages" bounded-chunk >50 guarantee (C4); **published-language honesty contract** (decision 3 — MLW1 never changes language status / never calls `PublicationService`, but writes into published languages may render publicly under the existing gate, so acknowledgement is mandatory); automatic mode = translate→QA→auto-submit-for-review; no per-object preview state; no schema change.
- **WP1** — `ObjectLanguageStatus` + `ObjectLanguagesSummary` (pure PHP composer, server-authoritative) + `WorkspaceService::object_languages_summary()` + `GET /{post_id}/languages` + presentation-only `utils/object-language-status.ts`. **PHP** unit tests for every `state()` branch + frozen `ready_count` / `forgotten` (C5); jest tests only the label/variant maps.
- **WP2** — `LanguageChecklist` + `LanguageTabs` + `ObjectLanguagesBar` components; `utils/multi-language-selection.ts`; jest. `aiml-ui` badge/subnav reuse; a11y (keyboard, aria, 2-lang and 12-lang layouts).
- **WP3** — `App.tsx` multi-language load + tab switching without object reload + per-language segment cache; page-action primary + menu (`Translate current` / `Translate all selected`). Jest for the selection/cache reducers; rebuild bundle.
- **WP4** — `MultiLanguageTranslationCoordinator` + `POST /workspace/objects/translate` (body-reading `permission_callback` per **C3**; cross-product, chunked to `create_bulk_resilient`, one `batch_id`, autostart, planned-count response; published-language `acknowledge_published` gate per decision 3) + `JobsController::create_bulk_jobs()` per-post `language_id`. Integration tests: N×M job creation, **C3 non-MANAGE_JOBS allow + deny (zero jobs on deny)**, per-(object,language) idempotency, no duplicate on rapid resubmit, bounded chunking for 100×5, `acknowledge_published` required for published targets.
- **WP5** — `Store::query_review_object_ids()` (grouped `GROUP BY source_id`, `ORDER BY first_submitted ASC, source_id ASC`, `COUNT(DISTINCT source_id)` total) + `Store::review_rows_for_objects()` + `WorkspaceService::review_queue_by_object()` (object-level pagination, **C1**) + `approve_object_languages()` + `approve-object` `languages[]` + `get_review_queue` `languages[]`. Integration tests: **`total` = object count; deterministic stable pagination with tied timestamps; no object on two pages; no object's languages split (C1)**; approve-all-ready across languages; **object with >50 pending segments in each of two languages → all approved (C4)**; one `needs_attention` language ⇒ `not_fully_reviewed`.
- **WP6** — `ReviewQueuePanel` / `ReviewObjectGroup` restructure to object-first with `LanguageTabs`; jest; rebuild.
- **WP7** — Site Translate N×M: `SiteTranslateBatchService` `language_ids[]` + `(source_id, language_id)` dedupe; `SiteTranslateController` + coverage per language; `SiteTranslatePanel` language checklist + pre-run "N × M = P" strip + published-language warning. Integration + jest.
- **WP8** — Docs (user manual multi-language section, ADR-0034, CHANGELOG Unreleased), full regression, deploy branch to DEV, run acceptance A–G + I(scale, fake provider), MLW1a validation log + closure. Propose version (see below).

## Work packages — MLW1b (outline, separate release)

- **WPb1** — `AutomaticTranslationFollowup` hooked to `translation_job_completed`; `aiml_auto_translate_followup` bounded Action Scheduler action per child job; `aiml_automatic_batches` option registry (pruned).
- **WPb2** — Automatic-mode safety predicate (the F2/contract checklist) as a pure, unit-tested class; QA-clean + missing-free + stale-free + slug-ok gate; auto-submit via `ReviewBatchCoordinator` 'submit'.
- **WPb3** — `POST /workspace/objects/translate` `automatic:true` path; failure aggregate endpoint (`GET /workspace/objects/translate/{batch_id}/report` → `{requested, submitted_for_review, needs_attention, failed, skipped_protected}`); reuse `retry-failed`.
- **WPb4** — Automatic-mode UX: confirmation dialog with operation count + mandatory published-language warning + acknowledgement checkbox + accurate copy ("MLW1 does not publish anything itself; translations added to already-published languages follow the site's existing rendering policy"); per-language progress; final aggregate with `Review issues` / `Retry failed`.
- **WPb5** — Docs + acceptance H (full automatic), I (failure), J (100×5 scale, fake provider) + closure.

---

## Test coverage (maps to the spec's 26 items)

**Unit — PHP:** `ObjectLanguageStatus.state` every branch; frozen `ready_count` / `target_count` / `forgotten` (C5); N×M operation count; cross-product skip-no-work. **Unit — jest:** default selector = all eligible targets + source excluded + individual deselect (`multi-language-selection.ts`); `object-language-status.ts` label/variant/tone maps only (no state derivation — C2).

**Integration (PHP):**
- `/{post_id}/languages` returns all target-language states with server-computed `state`; `translate current language` affects only that language.
- `POST /objects/translate` creates work for every selected target; **non-MANAGE_JOBS path**: an editor with `edit_post` on *all* requested objects is allowed and jobs are created; an editor missing `edit_post` on *one* requested object gets 403 and **zero jobs are created (C3)**; MANAGE_JOBS holder is allowed regardless.
- No duplicate job on rapid resubmit (idempotency per (object, language)); 100 objects × 5 languages ⇒ bounded chunked creation (≤50 jobs/call, one `batch_id`).
- **Review Queue pagination (C1):** `total` equals the distinct-object count (not segment count); with `per_page` forcing multiple pages and tied `review_submitted_at`, paginating all pages yields **every object exactly once, no object on two pages, deterministic order** (`first_submitted ASC, source_id ASC`); every returned object carries **all** its languages (none split across pages).
- `approve current language` scoped to that language; `approve all ready languages` approves every ready language and skips `needs_attention` ones; **an object with >50 pending segments in each of two languages ⇒ all pending in both approved (C4)**; one `needs_attention` language ⇒ object reported `not_fully_reviewed`.
- Site Translate N objects × M languages ⇒ correct job count and per-(object,language) dedupe on partial retry.
- `PluginGuardTest` unchanged schema assertions still pass.

**MLW1b:** automatic mode auto-submits safe (object,language) for review; QA-blocked ⇒ not submitted, `needs_attention`; failed job ⇒ not submitted; untranslated ⇒ not "complete"; automatic mode never writes `publish_status`, never calls `PublicationService`, never changes language status; bulk/automatic request targeting a `published` language is rejected without `acknowledge_published`; manual/reviewed segments untouched; slug generation still honours manual-protection; retry only retries FAILED items.

---

## DEV acceptance (branch deployed to `dev.biopentra.eu`; fake/OpenAI provider, no PROD)

Script: `acceptance/multi-language-workflow-check.php` (wp eval-file, like `acceptance/object-level-review-check.php`).

- **A** — One page, 3 languages: load → tabs `Swedish | German | Danish`; switch tabs → same page, language-specific translations, no reload.
- **B** — `Translate all 3 languages with AI` on an untranslated page → all three jobs start + complete, no manual switching.
- **C** — German tab → `Translate current language` → Swedish + Danish untouched.
- **D** — Leave Danish untranslated → object summary says `2 / 3 languages ready · Danish missing`.
- **E** — Submit Swedish + German + Danish for review → **one** About card with per-language tabs/statuses, not three groups.
- **F** — `Approve all ready languages` → all clean pending languages approve; introduce one blocked language → `About — page NOT fully reviewed`.
- **G** — Site Translate: 5 pages × 3 languages → strip says `15 translations`; start → 15 (object,language) child jobs under one batch.
- **I (scale)** — seed 100 pages × 5 languages via fake provider → bounded job creation (≤50/call), readable per-language progress, no browser lockup.
- **(MLW1b)** H — automatic mode: safe (object,language) auto-submitted for review; problems stay `needs_attention`; no language status change and no `PublicationService` call (assert via audit log / DB); a run targeting a `published` language is rejected without `acknowledge_published` and shows the warning; failure-scenario aggregate is honest.

---

## Data / schema impact

**None.** No table, column, or index change. `Migrator::TARGET` 10, `Settings::SCHEMA_VERSION` 3 (both guarded by `PluginGuardTest`). One bounded new option (`aiml_automatic_batches`) in MLW1b only.

---

## Recommended release version

- **MLW1a → v1.18.0** (minor: additive, user-visible workflow; no schema).
- **MLW1b → v1.19.0** (minor: automatic AI pipeline; one bounded option; no schema).

Tag/release each only after its DEV acceptance + PO sign-off.

---

## Verification (tooling)

```
# unit + phpcs (clone)
docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpcs -q
docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit -c phpunit.xml.dist --no-coverage

# integration (aiml-test-db + network already up on this host)
docker run --rm --network aiml-test -v "$PWD":/app -w /app \
  -e WP_DB_HOST=aiml-test-db -e WP_DB_NAME=wordpress_test -e WP_DB_USER=root -e WP_DB_PASS=root \
  -e WP_CORE_DIR=/app/tests/tmp/wordpress aiml-test-runner \
  vendor/bin/phpunit -c phpunit-integration.xml.dist --no-coverage \
  --filter 'ObjectLanguage|MultiLanguage|ReviewQueue|SiteTranslate|PluginGuard'

# frontend (host Node 22, in assets/translator-workspace)
npx tsc --noEmit          # expect only the 2 pre-existing OperationsPanel/SiteTranslatePanel errors
npx wp-scripts lint-js <changed files>   # new files must be clean
npx wp-scripts test-unit-js
npx wp-scripts build      # commit assets/translator-workspace/build/

# DEV acceptance
cd /opt/biopentra/apps/wordpress && docker compose --profile tools run --rm -T wpcli \
  wp eval-file wp-content/plugins/universal-multilingual/acceptance/multi-language-workflow-check.php
```
