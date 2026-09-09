# AIT1 — AI Translation UX — Validation Log

**Branch:** `feature/ait1-ai-translation-ux`
**Baseline:** `fad4f9da485d4fbfbd2a8e5434853f393698b516` (v1.14.0)
**Tooling:** Docker only — `composer:2.8`, `php:8.3-cli` (phpcs/unit),
`aiml-test-runner` + `mariadb:11.4` on internal net `aiml-test` (integration),
host Node 22 + `wp-scripts` (workspace build + Jest).

## Commits

| WP | Commit | Summary |
|----|--------|---------|
| WP0 | `0a5f60294` | Freeze plan + ADR-0031 |
| — | `d4bda2414` | ADR-0031 AS-unavailable wording fix |
| WP1 | `e7abc05bd` | Shared `TranslatableSegmentEligibility` + manual-overwrite fix + `retranslate_machine` + idempotency symmetry |
| WP2 | `9f2c0c968` | `autostart` on Jobs + Site Translate REST; `PageAiTranslateJobTest` |
| WP2b+WP3 | `c2a30922e` | `assets/admin-ui/aiml-ui.css` + `<StatusBadge>` + Settings alignment; `PageAiTranslate` CTA; legacy `Editor.php` bridge |
| WP4–WP9 | `c1a4d949a` | Bulk modes through job types; Pages/Posts bulk action; `PluginActionLinks`; `FakeAIProvider` |

## Gate results

| Gate | Baseline | Head | Command |
|------|----------|------|---------|
| PHP unit | 1047 pass / 2 skip | **1063 pass / 2 skip** | `phpunit -c phpunit.xml.dist` |
| PHP integration | 1027 pass / 3 skip | **1046 pass / 3 skip** | `phpunit -c phpunit-integration.xml.dist` |
| PHPCS (errors + warnings) | clean | **clean** | `vendor/bin/phpcs` |
| Workspace Jest | 108 pass / 2 pre-existing fail | **110 pass** (pre-existing `jobs-url` drift also fixed) | `wp-scripts test-unit-js` |
| Workspace build | ok | **ok** | `wp-scripts build` |
| `tsc --noEmit` | 2 pre-existing errors (OperationsPanel, SiteTranslatePanel) | **same 2, no new** | `tsc --noEmit` |

New tests: `TranslatableSegmentEligibilityTest` (unit), `PluginActionLinksTest`
(unit), `ProviderFrameworkTest::test_is_ai_configured_*` (unit),
`AitSharedEligibilityTest`, `PageAiTranslateJobTest`, `BatchAiTranslateModeTest`,
`AitAdminEntrypointsTest` (integration); `PluginGuardTest` P2 job-type boundary
updated for AIT1.

## Outstanding (needs the interactive DEV environment)

- **WP7** partial: plain-language labels + `<StatusBadge>` done; wrapping the
  raw "Create job" dialog controls (job-type enum, token, segment keys) in
  `.aiml-ui-advanced` is not yet done.
- **WP10** — `acceptance/ai-translation-browser/` Playwright suite +
  `ElementorAiTranslationRoundtripTest` not yet written; needs the fake-provider
  MU-plugin + a deployed branch on `dev.biopentra.eu`.
- **WP11** — DEV deployment, runtime acceptance run, final closure. Version left
  at **1.14.0**; **v1.15.0** proposed for the eventual release (milestone
  closure ≠ release closure; no merge/tag/release authorized).
