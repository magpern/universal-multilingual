# ADR-0031 — User-initiated AI translation (page + bulk)

**Status:** Accepted

## Context

Universal Multilingual already ships everything needed to translate content with
AI: a provider-agnostic interface with OpenAI + DeepSeek + a null object
(ADR-0010), a strict `ResponseValidator`, `TranslationService` as the single
translate path, `Store` as the single writer, deterministic segment identity,
stale detection, Translation Memory, Glossary, Review Workflow (ADR-0015), the
controlled publication gate (ADR-0020), and a resumable Action-Scheduler-backed
job pipeline (ADR-0011). A v1 RC translated real pages against live OpenAI end to
end.

What was missing was **product surface**. An administrator could not translate a
page with one obvious action; bulk translation lived in a technical panel; jobs
never started on their own; and one path — the synchronous workspace "Translate
selected" — could overwrite a human's translation because it never consulted the
same protection rules the background worker uses.

AIT1 closes that gap without a second translation architecture.

## Decision

### 1. All user-initiated AI translation goes through Jobs

The new page-level **"Translate with AI"** action and the bulk **"Translate
selected with AI"** action both create existing background translation jobs. There
is no synchronous shortcut for "small" pages. One orchestration model — create →
autostart → Action Scheduler → `BackgroundTranslationWorker` →
`BackgroundTranslationItemProcessor` → `TranslationService` → `Store` — serves 3
segments, 300 segments, Gutenberg, Elementor, retries, and a closed browser
identically.

`POST /aiml/v1/workspace/{post_id}/translate` keeps its existing meaning: an
explicit-segment-key synchronous translate for the segment grid's "Translate
selected". It is **not** broadened into the page-level API.

### 2. `retranslate_machine` is a first-class job type

Investigation (WP0) found that job behaviour keys off `job_type` in three places
that only have `$job->job_type` available: `JobIdempotencyKey`,
`BackgroundTranslationJobService` create-time materialisation, and
`BackgroundTranslationItemProcessor::evaluate_conflict()` via
`JobTypes::allows_retranslate()`. Overloading `translate_missing` with a
`resolution` argument would make it mean two contradictory things and would not
reach the worker's overwrite gate.

So AIT1 adds exactly one job type — `retranslate_machine` — which resolves every
eligible `machine_translated` segment for an object regardless of stale state.
`JobTypes::allows_retranslate('retranslate_machine') === true`. No schema change:
`job_type` is already a stored string; `Migrator::TARGET` stays 10.

The three user-facing modes map to job types:

| Mode (UI)                     | Job type              |
|-------------------------------|-----------------------|
| Translate missing             | `translate_missing` (page) / `bulk_translate` (bulk) |
| Retranslate stale             | `retranslate_stale`   |
| Retranslate AI translations   | `retranslate_machine` |

There is no "Force". Manual, reviewed and in-review translations are **never**
overwritten by a page or bulk AI action, in any mode. No override.

### 3. One shared eligibility policy

`src/Workspace/TranslatableSegmentEligibility` is the single place that decides
whether an AI action may write a segment, in mode `missing` / `stale` / `machine`.
It is consulted by:

- `BackgroundTranslationJobService` create-time materialisation (which keys to
  put in `aiml_job_items`),
- `BackgroundTranslationItemProcessor::evaluate_conflict()` (the per-item
  overwrite gate at execution time), and
- `BatchOperationCoordinator::translate_batch()` — the synchronous
  "Translate selected" path, which previously had **no** manual-translation
  guard. Protected segments are now skipped and reported as `skipped`, never
  overwritten.

`is_protected()` returns true for `status ∈ {manually_edited, reviewed}` or
`review_status ∈ {pending, approved, rejected}` — identical to the worker's
prior inline checks, now shared so they can never drift.

### 4. Autostart is an explicit caller opt-in

`BackgroundTranslationJobService::create_job()` still never wakes Action
Scheduler (ADR-0011 §"no wake in J2"). The Jobs REST create and the Site Translate
batch create accept `autostart: true`, sent only by the new user-facing actions.
When set and `scheduler->health()['available']`, the controller enqueues the job
(or runs the batch) in the same request. CLI, the raw "Create job" dialog, and
any REST caller that does not pass the flag keep create-then-run. If Action
Scheduler is unavailable the job is still created and the existing health message
is surfaced — the UI never claims it started.

### 5. Automatic idempotency for user-facing actions

The page CTA and the bulk action generate their own opaque `client_token`
(`crypto.randomUUID()`), never shown to the operator. The token is reused only
while the submission outcome is uncertain or the job/batch is non-terminal; it is
regenerated when the job/batch reaches a terminal state or when the post, target
language, mode, or selection changes. Result: a double-click or a
timeout-and-retry collapses to one job; a deliberate second run after completion
is new work. `JobIdempotencyKey` already folds `job_type` and `client_token` into
its digest.

### 6. Legacy `Editor.php` is a bridge, not a dead end

The legacy "Translate" screen gains a primary **"Translate with AI"** button that
deep-links to the Translator Workspace with the post and target language
preselected and the AI flow armed, plus an "Open in Translator Workspace" link.
No job is created from `Editor.php` itself — one orchestration path.

### 7. Execution state and review state stay separate

The shared `<StatusBadge>` renders job execution state (`queued` → "Queued",
`running` → "Translating", `completed` → "Completed",
`completed_with_errors` → "Completed with skips", `failed` → "Failed") and
translation review state ("Needs review" / "Reviewed") as two distinct badges.
They are never merged into one axis.

### 8. Shared admin design system

The `aiml-ui-*` design system is extracted from
`assets/languages-admin/languages-admin.css` into `assets/admin-ui/aiml-ui.css`
(scoped `.aiml-ui`, loaded by `wp_enqueue_style` dependency ordering, no CSS
`@import`) and consumed by Languages, the Translator Workspace, Site Translate,
Jobs, and the main Settings page. This is presentation only; no screen's
behaviour changes.

## Consequences

- One code path to reason about for "translate with AI", at any scale.
- The synchronous overwrite bug is fixed for every caller at once.
- `retranslate_machine` is the only new job type; `Migrator::TARGET` unchanged.
- Generic Jobs API semantics are preserved for automation; only the product
  buttons autostart.
- No new provider, queue, job store, translation pipeline, or dashboard.
- Elementor stays plugin-controlled: AI translates allowlisted control values
  only; `_elementor_data` structure and IDs are never written (ADR-0016).

See also: ADR-0010 (provider interface), ADR-0011 (resumable job pipeline),
ADR-0015 (review workflow), ADR-0016 (Elementor identity), ADR-0020 (publication
gate), ADR-0005 / 0007 (segment storage, hash semantics).
