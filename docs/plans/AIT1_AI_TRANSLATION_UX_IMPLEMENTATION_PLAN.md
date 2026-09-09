# AIT1 — AI Translation UX — Definitive Implementation Plan

**Status:** **FROZEN** — authoritative AIT1 specification
**Frozen:** 2026-09-09
**Freeze baseline / reconciled main:** `fad4f9da485d4fbfbd2a8e5434853f393698b516`
**Implementation branch:** `feature/ait1-ai-translation-ux`
**Version during AIT1:** **1.14.0** → **1.15.0** at release prep (WP11)
**`Migrator::TARGET`:** **10** (unchanged — no schema migration)
**`Settings::SCHEMA_VERSION`:** **3** (unchanged)
**ADR:** [ADR-0031](../adr/0031-user-initiated-ai-translation.md)
**Release:** milestone closure ≠ release closure — no merge/tag/release without separate authorization.

Full narrative plan, gap matrix and PO review corrections:
`/home/magpern/.claude/plans/you-are-working-on-wild-falcon.md`.

---

## 1. Goal

A normal WordPress administrator can translate one page — normal or Elementor —
with AI in one obvious action, and can bulk-translate many pages, without ever
seeing a job-type enum, an idempotency token, a segment key, or a "Run now"
button; work runs in the background; progress, failure and retry are legible;
and a person's translations are never overwritten by AI.

## 2. Baseline identity (reconciled)

| Item | Value |
|---|---|
| origin/main at freeze | `fad4f9da485d4fbfbd2a8e5434853f393698b516` |
| Version | 1.14.0 (→ 1.15.0 at WP11) |
| `Migrator::TARGET` | 10 (unchanged) |
| `Settings::SCHEMA_VERSION` | 3 (unchanged) |
| Existing AI stack | provider interface + OpenAI/DeepSeek/Null, `ResponseValidator`, `TranslationService`, `Store`, resumable Jobs pipeline, Site Translate, Workspace, TM, Glossary, Review, Publication — all reused |

## 3. Frozen decisions (non-negotiable — see ADR-0031)

1. **All user-initiated page/bulk AI translation goes through the existing Jobs
   pipeline.** No synchronous shortcut. `POST /workspace/{id}/translate` stays
   the explicit-segment path and is not broadened.
2. **`retranslate_machine`** is the one new job type (WP0 gate: job behaviour
   keys off `job_type` in `JobIdempotencyKey`, materialisation, and
   `evaluate_conflict()`; `resolution=machine` would overload `translate_missing`).
   No schema change.
3. **Mode vocabulary:** Translate missing / Retranslate stale / Retranslate AI
   translations. No "Force".
4. **Manual / reviewed / in-review translations are never overwritten** by a
   page or bulk AI action, in any mode. No override.
5. **`TranslatableSegmentEligibility`** is the single shared policy, used by the
   worker, job materialisation, and the synchronous `BatchOperationCoordinator`
   (which had no guard — the standalone bug fix).
6. **Autostart** is a caller opt-in (`autostart: true`), sent only by the new
   user-facing actions. Generic create-job stays create-without-wake.
7. **Automatic opaque idempotency** for user-facing actions; token resets on
   terminal job / post / language / mode / selection change.
8. **Legacy `Editor.php`** deep-links into the Workspace AI flow — no dead end.
9. **Shared `aiml-ui` design system** extracted to `assets/admin-ui/aiml-ui.css`
   (scoped `.aiml-ui`, no `@import`), applied to Languages, Workspace, Site
   Translate, Jobs, main Settings.
10. Execution status and review status are separate badges, never merged.

## 4. Work packages

| WP | Title | Gate |
|----|-------|------|
| WP0 | Architecture freeze + ADR-0031 + WP0 spike (job-type semantics, Editor handoff) | this doc + ADR merged |
| WP1 | `TranslatableSegmentEligibility` + worker/materialisation refactor + `BatchOperationCoordinator` manual-protection fix | unit + integration |
| WP2 | `retranslate_machine` end to end + `autostart` on Jobs & Site Translate REST + automatic idempotency | `PageAiTranslateJobTest` |
| WP2b | Extract `assets/admin-ui/aiml-ui.css`; `<StatusBadge>`; bounded `SettingsPage` alignment | Languages acceptance stays green |
| WP3 | Workspace "Translate with AI" CTA (modes, confirm, poll, refresh, disabled-when-unconfigured) + legacy `Editor.php` bridge | Jest + build |
| WP4 | Bulk modes through job types | `BatchAiTranslateModeTest` |
| WP5 | Simplified Site Translate (one create+start action, plain status, idempotency) | Jest |
| WP6 | Pages/Posts list-table "Translate with AI" bulk action (deep link only) | integration |
| WP7 | Plain-language Jobs/status labels; operational controls under `.aiml-ui-advanced` | Jest |
| WP8 | `src/Admin/PluginActionLinks.php` (Settings \| Overview + Documentation meta) | unit + integration |
| WP9 | `tests/Fixtures/FakeAIProvider.php` + self-contained DEV acceptance MU-plugin | — |
| WP10 | `acceptance/ai-translation-browser/` Playwright suite + `ElementorAiTranslationRoundtripTest` | Playwright |
| WP11 | Docs + version bump 1.15.0 + full regression + DEV deploy + acceptance + `_VALIDATION_LOG` + closure | all gates |

## 5. Acceptance

The 36-point acceptance list in the narrative plan
(`/home/magpern/.claude/plans/you-are-working-on-wild-falcon.md`, "FINAL
ACCEPTANCE DEFINITION") is the checklist. `AIT1_AI_TRANSLATION_UX_VALIDATION_LOG.md`
records per-WP gate results.
