# MLW1a — Multi-language ("object × language") workflow — Closure

**Status:** Implemented, DEV-accepted. Release **proposed** (v1.18.0) — awaiting
PO functional sign-off. NOT merged, NOT tagged, NOT released, PROD untouched.
**Date:** 2026-09-10
**Branch:** `feature/mlw1a-multi-language-workflow`
**Plan:** [`MLW1_MULTI_LANGUAGE_WORKFLOW_IMPLEMENTATION_PLAN.md`](./MLW1_MULTI_LANGUAGE_WORKFLOW_IMPLEMENTATION_PLAN.md)
(FROZEN)
**ADR:** [ADR-0034](../adr/0034-multi-language-workflow.md) (Accepted)

## What shipped (WP0–WP8)

| WP | Commit subject |
|----|----------------|
| WP0 | `docs(mlw1a): freeze MLW1 plan + accept ADR-0034` |
| WP1 | `feat(mlw1a): object×language status model + server-authoritative endpoint` |
| WP2 | `feat(mlw1a): LanguageChecklist + LanguageTabs + ObjectLanguagesBar` |
| WP3 | `feat(mlw1a): Workspace multi-language load, tabs and per-language cache` |
| WP4 | `feat(mlw1a): N×M translation coordinator + POST /workspace/objects/translate` |
| WP5 | `feat(mlw1a): object-level Review Queue pagination + multi-language approval` |
| WP6 | `feat(mlw1a): object-first Review Queue UI with per-language tabs` |
| WP7 | `feat(mlw1a): Site Translate N objects × M languages matrix` |
| WP8 | `docs(mlw1a): CHANGELOG + user manual` · `test(mlw1a): DEV acceptance + closure` |
| review | `fix(mlw1a): Workspace "translate all selected languages" + Review Queue all-language tabs` (PR #69 review) |

## Frozen architecture decisions honoured

- **object × language** is the primary workflow model (D1).
- `ObjectLanguageStatus` + `ObjectLanguagesSummary` are **server-authoritative**
  (D2/C2) — PHP owns every canonical `state`; TypeScript renders it verbatim
  (`utils/object-language-status.ts` is presentation only). Verified: the DEV
  acceptance asserts every returned `state` is one of the nine canonical values
  and the client has no state-derivation logic (only label/variant/tone maps +
  an unknown-state fallback, jest-covered).
- Frozen `state()` ladder + `ready_count` / `target_count` (M = **every**
  eligible configured target language, not the selected subset) / `forgotten`
  definitions (D3/C5). Verified in `ObjectLanguageStatusTest` (17 unit tests,
  every branch) and DEV acceptance D.
- Review Queue pagination is **object-level** (D4/C1): grouped
  `GROUP BY source_id`, `COUNT(DISTINCT source_id)` total, deterministic
  `MIN(review_submitted_at) ASC, source_id ASC`; one object's languages never
  split across pages. Verified in `ReviewQueueObjectPaginationTest` +
  DEV acceptance E.
- Multi-object translate authorization (D5/C3): the body-reading
  `permission_callback` allows only `MANAGE_JOBS` **or** `edit_post` on **every**
  requested object; one failing object → 403, **zero jobs**, no cross-product
  built. Verified in `MultiLanguageTranslateRestTest` + DEV acceptance J
  (published gate rejects with zero jobs).
- "Approve all ready languages" keeps the **>50-pending-segment guarantee**
  (D6/C4): each ready language's full pending set is approved in bounded
  `ReviewBatchCoordinator::BATCH_LIMIT` chunks; needs-attention languages are
  reported as skipped, never approved. Verified in
  `ReviewQueueObjectPaginationTest` (55 real block segments × 2 languages) +
  DEV acceptance H (all pending in both languages approved, per-language count
  > 50) and F (blocked language skipped, `not_fully_reviewed`).
- **Published-language honesty** (D8 / PO decision 3): MLW1a never changes a
  language's publication status and never calls `PublicationService`. Any bulk /
  N×M run targeting a `published` language is rejected server-side without
  `acknowledge_published: true`, naming the languages. Verified in
  `MultiLanguageTranslateRestTest`, `SiteTranslateMatrixRestTest` +
  DEV acceptance J (rejected without ack; succeeds with ack; language status
  unchanged before/after).
- **No schema change** (D9): `Migrator::TARGET` 10, `Settings::SCHEMA_VERSION`
  3 — both still guarded by `PluginGuardTest` (unchanged, green). DEV
  `aiml_db_version` = 10, settings `schema_version` = 3 after acceptance.
- **MLW1b** automatic mode remains a separate future milestone and is
  explicitly rejected (`400`) by `POST /workspace/objects/translate` in MLW1a.

## Regression (full repository gates — clone, Docker)

| Gate | Result |
|------|--------|
| PHPCS (incl. warnings) | **clean** |
| PHP unit | **1103 tests**, 2 skipped (pre-existing) |
| PHP integration (full suite) | **1088 tests**, 4 skipped (pre-existing), exit 0 |
| `PluginGuardTest` (schema + prepared-SQL invariants) | green |
| Jest | **143 tests** |
| `tsc --noEmit` | only the 2 known pre-existing errors (`OperationsPanel.tsx:1451`, `SiteTranslatePanel.tsx` postType `onChange`) |
| `wp-scripts lint-js` (new/changed files) | clean; `App.tsx` unchanged from its 77-error pre-existing baseline (repo does not gate lint on `App.tsx`) |
| `wp-scripts build` | bundle rebuilt and committed |
| `bin/build-zip.sh` + `bin/audit-zip.sh` | **PASS** (701 entries, no tests/docs/dev sources) |
| `composer quality:validate` + `quality-verify-baseline` | PASS |

## DEV acceptance (`acceptance/multi-language-workflow-check.php`)

Run on `https://dev.biopentra.eu`, branch `feature/mlw1a-multi-language-workflow`
deployed to the bind-mounted DEV checkout, WordPress restarted.

**RESULT: 52 passed, 0 failed** (48 at first DEV pass; +4 after the PR-review
fix added the all-language-tab assertions to scenario E). Fixtures created and
torn down cleanly; DEV returned to its 3 real languages (`en`, `sv`, `de`),
`aiml_db_version` 10, `schema_version` 3, public listeners exactly
`2222/80/443`.

| Scenario | Verified |
|---|---|
| **A** — one page, 3 languages | `GET /{id}/languages` returns one server-computed status per eligible target; every `state` canonical; clean-pending → `pending_review`. |
| **B** — translate all selected languages | 3 (object,language) jobs under one `batch_id`, one per selected language, autostart requested. |
| **C** — translate current language only | translating German leaves the Swedish + Danish translation hashes byte-identical; only the German pair's job is created. |
| **D** — forgotten language | `target_count` = every configured target (M, not the selected subset); `forgotten: true`; Danish in `forgotten_languages`; Danish `state = not_translated`. |
| **E** — Review Queue one card | exactly one `object_groups` card for the page; **every** eligible target language nested under it as a first-class tab — a language with zero review rows still has a tab carrying its server `state` (`not_translated`) and empty `items[]`, and the card summary flags it as forgotten; `object_total` is an object count. |
| **F** — approve all ready languages | Swedish + German approved; Danish (a rejected row → `needs_attention`) reported as skipped, not approved; object `not_fully_reviewed`; Swedish pending rows cleared. |
| **G** — Site Translate 5 × 3 | pre-run `operations` = 15; 15 child jobs, one batch, one job per (object, language) pair. |
| **H** — >50 in two languages | 55 real block segments seeded pending in Swedish + German (57 pending each incl. title/excerpt); "Approve all ready languages" leaves **0** pending in both; per-language `approved_count` > 50 — no first-50 truncation. |
| **I** — scale 100 × 5 | pre-run count 500; 500 child jobs, one batch lineage; `chunk_count` ≥ 10 (≤ 50 per create call); creation < 60 s; no duplicate (object, language) work. Worker not run (AI provider forced to `null`) — **zero paid provider calls**. |
| **J** — published-language safety | published target rejected without acknowledgement (`400 aiml_acknowledge_published_required`, names the language, **zero jobs**); succeeds with `acknowledge_published: true`; the language's publication status is unchanged before/after; no `PublicationService` path is reachable from the endpoint. |

## Limitations / deferred

- **Browser + assistive-technology QA is not executed.** The language-tab strip
  keyboard model (`nextTabIndex`) and the selection/cache reducers are jest-unit
  covered and the components reuse the shipped `aiml-ui` badge/subnav system,
  but a real VoiceOver/NVDA + keyboard smoke of `LanguageTabs`,
  `LanguageChecklist` and the Review Queue card is recommended before release.
- **End-to-end translation completion (acceptance B/C "jobs complete") was not
  run against a real provider** to avoid paid calls; job *creation*, autostart,
  chunking, idempotency and per-language isolation are verified. A tiny
  real-provider smoke (1 page × 3 languages) is recommended during PO
  acceptance.
- Multi-language **progress UI** (per-language batch progress view) is minimal
  in MLW1a — the Jobs view shows the shared batch; a per-language breakdown is
  MLW1b scope.
- The legacy per-(object,language) Review Queue payload (`objects[]`) and
  `WorkspaceService::review_queue_grouped()` are retained for compatibility;
  a follow-up removes them once nothing consumes them.

## Recommended release

**v1.18.0** (minor — additive, user-visible workflow, no schema). Tag / release
only after PO functional sign-off. MLW1b (automatic AI mode) → v1.19.0, separate.
