# AIT1 — AI Translation UX — Closure

**Status:** **CLOSED — PASS WITH LIMITATIONS**
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
| DEV | branch deployed to `dev.biopentra.eu` for acceptance; still checked out there |

## What shipped

1. **AI translates segment values, never structure.** Every path goes through
   `TranslationService` → `Store` and the existing extractors. No new provider,
   queue, job store or translation pipeline. `_elementor_data` structure and IDs
   are untouched (proven byte-identical, integration + on DEV).
2. **One orchestration model.** Page-level "Translate with AI" and bulk
   "Translate selected with AI" both create existing background jobs and start
   them via `autostart`. No sync shortcut.
3. **`retranslate_machine`** is the one new job type (ADR-0031 WP0 gate).
   No schema change.
4. **Manual translations protected by default and by design** — one shared
   `TranslatableSegmentEligibility` policy for the worker, job materialisation,
   and the synchronous "Translate selected" path (which previously could
   overwrite a human's translation).
5. **Automatic idempotency** for user-facing actions (invisible `client_token`).
6. **Shared `aiml-ui` design system** across Languages, Settings, Workspace,
   Site Translate, Jobs; plain-language job status; execution vs review as
   separate badges.
7. **Discoverability:** legacy `Editor.php` bridges into the Workspace AI flow;
   Pages/Posts list-table bulk action; Plugins-row **Settings | Overview** +
   **Documentation** links.

## Gates (green — see validation log)

PHP unit **1064/1064** · PHP integration **1048/1048** · PHPCS **clean**
(errors + warnings) · Jest **110/110** · workspace build **ok** ·
build zip + audit zip **PASS**.

## DEV acceptance

- WP-CLI REST runtime validation on `dev.biopentra.eu` with the fake provider:
  **9/9 PASS** (autostart flow, translation persistence, double-submit
  idempotency, manual-translation protection under `retranslate_machine`,
  Elementor `_elementor_data` byte-identity, allowlist respected).
- `acceptance/ai-translation-browser/` Playwright vs DEV: **11 of 12 scenarios
  PASS** (single page auto-start + populate + no Run now, manual kept, Elementor,
  120-segment page, legacy `Editor.php` bridge, bulk create+start, failure
  surfaced, no-provider disabled CTA + Configure link, Pages bulk action,
  Plugins-row links, shared `aiml-ui`). `11b` (retry-to-completion from the Jobs
  view) written but not executed. Three `net::ERR_NETWORK_CHANGED` transients
  across the run, all green on retry — a DEV edge/network condition, not AIT1.
- **No paid provider was called.** The self-contained fake-provider MU-plugin was
  installed only for the run and **removed + verified on teardown**
  (`has_filter('aiml_ai_provider') === false`, fake class not loaded,
  `home_url('/')` → 200). The throwaway acceptance admin user was deleted.

## Limitations / outstanding

- Existing browser suites (`languages-admin-browser`, `f10-browser`, `jobs`,
  `a3-elementor`) were not re-run — they execute on the host (shell out to
  `dev-wp`, need a host `npx playwright install`), not in the Playwright
  container. Their `src/` contracts are covered by the green PHP integration
  suite (`PluginGuardTest`, `Tsc5*` Elementor, `JobsRestTest`, …).
- `11b` browser retry-to-completion scenario.
- `tsc --noEmit` still reports the two pre-existing errors (`OperationsPanel`,
  `SiteTranslatePanel` postType) — reproduced on the clean baseline; AIT1 adds
  none; not a CI gate.
- WP11 release actions (version bump to v1.15.0, tag, GitHub release, bucket
  publish) — deferred; not authorized.

## Next

Host-side re-run of the four existing browser suites; `11b`; PO functional
sign-off; then the v1.15.0 release.
