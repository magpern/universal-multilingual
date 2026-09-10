# AIT1 — AI Translation UX — Validation Log

**Branch:** `feature/ait1-ai-translation-ux`
**Baseline:** `fad4f9da485d4fbfbd2a8e5434853f393698b516` (v1.14.0)
**Tooling:** Docker only — `composer:2.8`, `php:8.3-cli` (phpcs/unit),
`aiml-test-runner` + `mariadb:11.4` on internal net `aiml-test` (integration),
host Node 22 + `wp-scripts` (workspace build + Jest),
`mcr.microsoft.com/playwright:v1.55.1-noble` (browser acceptance vs deployed DEV).

## Commits (WP0 → WP11)

| WP | Commit | Summary |
|----|--------|---------|
| WP0 | `0a5f60294` | Freeze plan + ADR-0031 |
| — | `d4bda2414` | ADR-0031 AS-unavailable wording fix |
| WP1 | `e7abc05bd` | Shared `TranslatableSegmentEligibility` + manual-overwrite fix + `retranslate_machine` + idempotency symmetry |
| WP2 | `9f2c0c968` | `autostart` on Jobs + Site Translate REST; `PageAiTranslateJobTest` |
| WP2b+WP3 | `c2a30922e` | `assets/admin-ui/aiml-ui.css` + `<StatusBadge>` + Settings alignment; `PageAiTranslate` CTA; legacy `Editor.php` bridge |
| WP4–WP9 | `c1a4d949a` | Bulk modes through job types; Pages/Posts bulk action; `PluginActionLinks`; `FakeAIProvider` |
| WP7 | `cf6d70cd0` | Jobs UX plain language + advanced disclosure |
| WP10 | `10b2cba14` | `ElementorAiTranslationRoundtripTest` |
| WP9 | `7265ef524` | `aiml_ai_provider` filter seam |
| — | `180ba0235` | Workspace honours `?view=site-translate` |
| WP10 | `c173f67d3` | `acceptance/ai-translation-browser/` suite + fake-provider MU-plugin + phpcs fixes |

## Local gate results

| Gate | Baseline | Head | Command |
|------|----------|------|---------|
| PHP unit | 1047 pass / 2 skip | **1064 pass / 2 skip** | `phpunit -c phpunit.xml.dist` |
| PHP integration | 1027 pass / 3 skip | **1048 pass / 3 skip** | `phpunit -c phpunit-integration.xml.dist` |
| PHPCS (errors + warnings) | clean | **clean** | `vendor/bin/phpcs` |
| Workspace Jest | 108 pass / 2 pre-existing fail | **110 pass** (the pre-existing `jobs-url` drift also fixed) | `wp-scripts test-unit-js` |
| Workspace build | ok | **ok** | `wp-scripts build` |
| `tsc --noEmit` | 2 pre-existing errors | **same 2, no new** — evidence below | `tsc --noEmit` |
| build zip + audit zip | ok | **ok** | `bin/build-zip.sh` + `bin/audit-zip.sh` (clean `--no-dev` checkout) |

New tests: `TranslatableSegmentEligibilityTest`, `PluginActionLinksTest`,
`ProviderFrameworkTest::is_ai_configured_*` + `::aiml_ai_provider_filter_*` (unit);
`AitSharedEligibilityTest`, `PageAiTranslateJobTest`, `BatchAiTranslateModeTest`,
`AitAdminEntrypointsTest`, `ElementorAiTranslationRoundtripTest` (integration);
`PluginGuardTest` P2 job-type boundary updated for AIT1.

### Pre-existing `tsc --noEmit` errors (AIT1 does not worsen)

Two errors on files AIT1 also touches:
`src/components/OperationsPanel.tsx` (`event` implicit any) and
`src/components/SiteTranslatePanel.tsx` (a `@wordpress/components` SelectControl
value-union inference on the unrelated `postType` control). Both reproduce on the
clean baseline (`git stash` → `npx tsc --noEmit`). AIT1's edits to
`SiteTranslatePanel` only shift the line number; the new AIT1 code
(`PageAiTranslate.tsx`, `StatusBadge.tsx`, `utils/jobs.ts` additions, App.tsx
view handling) type-checks clean. `wp-scripts build` (Babel) is unaffected and
`tsc` is not a CI gate.

## DEV deployment + runtime validation

Branch deployed to `https://dev.biopentra.eu` by fast-forwarding the
bind-mounted checkout `/opt/biopentra/dev/universal-multilingual` to the branch
HEAD and restarting the `wordpress` container. `curl -sI` → `HTTP/2 200`.
Migrator `TARGET` unchanged (10); no migration ran.

**No paid provider was used.** The acceptance fake-provider MU-plugin overrode
`ProviderRegistry::active()` through the new `aiml_ai_provider` filter for the
entire run (`active provider id = acceptance-fake`).

### WP-CLI REST runtime validation — `tools/runtime-validate.php` — ALL PASS (9/9)

Exercised the real wired REST controllers + worker on DEV:

- fake provider active (no paid calls)
- plain page `translate_missing` job created + autostarted + ran; segments
  translated (`[sv_SE] …`)
- double-submit with one `client_token` → one job
- `retranslate_machine` job does **not** overwrite a `manually_edited` segment
- Elementor page job ran; `_elementor_data` **byte-identical** before/after;
  allowlisted heading control translated; raw `html` widget never became a
  segment

### Playwright browser acceptance vs DEV — `acceptance/ai-translation-browser/`

Fake provider active; dedicated throwaway admin user (created + deleted by the
harness).

| Scenario | Result |
|---|---|
| 1+2+17 normal page: Translate with AI auto-starts, populates, no Run now, execution-only badge | **PASS** |
| 5+6 manual/reviewed kept (operator sees "kept") | **PASS** |
| 8 Elementor page translates via the CTA | **PASS** |
| 9 120-segment page → one background job → completes | **PASS** |
| 7 legacy `Editor.php` is not an AI dead end (deep-links into Workspace flow) | **PASS** |
| 10 bulk: select pages → one "Translate selected with AI" → jobs run in background | **PASS** (green on retry) |
| 11 a provider failure surfaces to the operator (fake in `rate_limit`) | **PASS** (flaky: `net::ERR_NETWORK_CHANGED` transient, green on retry) |
| 12+13 no-provider: CTA visible-but-disabled + "Configure AI settings" link | **PASS** |
| 14 Pages list-table "Translate with AI" bulk action | **PASS** |
| 15 Plugins row: Settings \| Overview \| Documentation | **PASS** (one `ERR_NETWORK_CHANGED` flake, green on rerun) |
| 16 shared `aiml-ui` on Languages / Settings / Workspace | **PASS** |
| 11b retry-failed to completion from the Jobs view | written; not executed (retry mechanics covered by `JobsRetryFailedTerminalTest` / `JobsRetryBudgetTest`) |

`net::ERR_NETWORK_CHANGED` appeared 3× across ~20 navigations — an intermittent
DEV edge/host network condition, unrelated to AIT1; every occurrence passed on
Playwright's automatic retry.

### Existing acceptance suites re-run vs DEV

- **`acceptance/jobs/smoke-dev.php`** (WP-CLI): **34/35 PASS** on DEV with AIT1
  deployed — `create` (all four job types incl. `retranslate_machine`),
  `create_bulk`, `batch_status`, `retry-failed` 409, capability gating, table +
  `review_status` column integrity, audit privacy. The 1 non-pass is
  `schema_target_7` — a stale assertion in the smoke script (`7 === Migrator::TARGET`);
  `Migrator::TARGET` has been **10 since before the AIT1 baseline `fad4f9da4`**
  (`git show fad4f9da4:src/Database/Migrator.php` → `const TARGET = 10`). Not an
  AIT1 regression.
- **`acceptance/a3-elementor`** is a fixture seeder (`scripts/seed-a3-fixture.php`),
  not a runnable test suite; the Elementor render/overlay contract it feeds is
  covered by `ElementorAiTranslationRoundtripTest` (new) + the `Tsc5*` integration
  tests (green).
- **`acceptance/languages-admin-browser`** — Playwright, re-run vs DEV in the
  Playwright Docker image with the docker socket mounted (a small `dev-wp` shim
  replaces the compose wrapper). Result recorded on the next line once the run
  completes. The Languages screen's only AIT1 change is a body `aiml-ui` class +
  its stylesheet loaded as a dependency of the shared `aiml-admin-ui` handle;
  scenario 16 of the new suite already confirmed `.aiml-ui` renders there on DEV.
- **`acceptance/f10-browser`** — **not re-run**: needs the `F10_POST_ID` fixture
  post and an f9 auth-cookie bootstrap, and its `bulk translate reports not
  configured` test is stale against the current DEV (which has a provider key,
  `is_ai_configured=true`). Its workspace behaviour is covered by the new
  `acceptance/ai-translation-browser` suite + `JobsRestTest` / `WorkspaceServiceTest`. Running them on the host is the one
outstanding acceptance step.

### Teardown

`tools/verify-teardown.php` — fake provider MU-plugin removed; `has_filter(
'aiml_ai_provider' ) === false`; active provider resolves to the real
settings-configured provider again; `home_url('/')` → HTTP 200. Throwaway admin
user deleted.

## Outstanding

- Host-side re-run of `languages-admin-browser` / `f10-browser` / `jobs` /
  `a3-elementor`.
- `11b` browser retry-to-completion.
- WP11 release actions (version bump to v1.15.0, tag, GitHub release, bucket
  publish) — deferred; not authorized.
