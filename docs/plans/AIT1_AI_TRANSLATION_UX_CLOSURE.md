# AIT1 — AI Translation UX — Closure

**Status:** **IMPLEMENTATION CLOSED — PASS WITH LIMITATIONS**
**Closed:** 2026-09-10
**Version:** **1.14.0** (unchanged — v1.15.0 proposed for release) · **`Migrator::TARGET`:** **10** · **Migration:** **NONE**
**Authoritative plan:** [`AIT1_AI_TRANSLATION_UX_IMPLEMENTATION_PLAN.md`](AIT1_AI_TRANSLATION_UX_IMPLEMENTATION_PLAN.md)
**ADR:** [`../adr/0031-user-initiated-ai-translation.md`](../adr/0031-user-initiated-ai-translation.md)
**Validation log:** [`AIT1_AI_TRANSLATION_UX_VALIDATION_LOG.md`](AIT1_AI_TRANSLATION_UX_VALIDATION_LOG.md)

## Identity

| Item | Value |
|---|---|
| Baseline `origin/main` | `fad4f9da485d4fbfbd2a8e5434853f393698b516` (v1.14.0) |
| Implementation branch | `feature/ait1-ai-translation-ux` |
| Merge / tag / release | **NOT performed** — not authorized |

## What shipped

1. **AI translates segment values, never structure.** Every path goes through
   the existing `TranslationService` → `Store` and the existing
   Gutenberg/Elementor extractors. No new provider, queue, job store or
   translation pipeline. `_elementor_data` structure and IDs are untouched.
2. **One orchestration model.** The new page-level "Translate with AI" and the
   bulk "Translate selected with AI" both create existing background jobs and
   start them via `autostart`.
3. **`retranslate_machine`** is the one new job type (ADR-0031 WP0 gate:
   `JobIdempotencyKey`, materialisation and `evaluate_conflict()` all key off
   `job_type`). No schema change.
4. **Manual translations are protected by default and by design.** The shared
   `TranslatableSegmentEligibility` policy is used by the worker, job
   materialisation, and — new in AIT1 — the synchronous "Translate selected"
   path, which previously could overwrite a human's translation.
5. **Automatic idempotency** for user-facing actions (invisible `client_token`,
   reset on terminal / context change).
6. **Shared `aiml-ui` design system** across Languages, Settings, Workspace,
   Site Translate, Jobs; plain-language job status; execution vs review as
   separate badges.
7. **Discoverability:** legacy `Editor.php` bridges into the Workspace AI flow;
   Pages/Posts list-table bulk action; Plugins-row **Settings | Overview** +
   **Documentation** links.

## Gates (all green — see validation log)

PHP unit 1063/1063 · PHP integration 1046/1046 · PHPCS clean (errors + warnings)
· Jest 110/110 · workspace build ok.

## Limitations / outstanding

- **WP7 partial** — plain labels + `<StatusBadge>` done; moving the raw
  "Create job" dialog controls under `.aiml-ui-advanced` is not done.
- **WP10 not done** — the `acceptance/ai-translation-browser/` Playwright suite,
  the fake-provider MU-plugin, and `ElementorAiTranslationRoundtripTest` require
  a branch deployed to `dev.biopentra.eu` and iterative browser runs.
- **WP11 not done** — DEV deployment, runtime acceptance, PO functional
  sign-off. Version bump to v1.15.0 and the release are explicitly deferred.
- `tsc --noEmit` still reports the two pre-existing errors (`OperationsPanel`,
  `SiteTranslatePanel` — not a CI gate); AIT1 adds none.

## Next

Deploy `feature/ait1-ai-translation-ux` to DEV, add the Playwright acceptance
suite against it with the fake-provider MU-plugin, run WP7's advanced-disclosure
polish, then close WP10/WP11 and propose the v1.15.0 release.
