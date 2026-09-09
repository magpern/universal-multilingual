# LANGUAGE ADMIN REDESIGN — Validation Log

Plan: [LANGUAGE_ADMIN_REDESIGN_IMPLEMENTATION_PLAN.md](LANGUAGE_ADMIN_REDESIGN_IMPLEMENTATION_PLAN.md)
Branch: `feature/language-admin-redesign` (from `main` `96b8d9977`, v1.12.0)
ADRs: [0028](../adr/0028-curated-locale-registry-and-language-admin.md),
[0029](../adr/0029-extended-language-url-code-grammar.md)

All gates via Docker (`CLAUDE.local.md`): `composer:2.8` install, `php:8.3-cli`
phpcs + unit, `aiml-test-runner` + `mariadb:11.4` on the internal `aiml-test`
network for integration.

## Commits

| WP | Commit | What |
|---|---|---|
| — | `bbebf66ca` | Plan freeze + ADR-0028 / ADR-0029 |
| WP1 | `b6cd2042e` | `LanguagesScreen` extracted from `SettingsPage` (move-only) |
| WP2 | `f1e53170b` | Curated offline locale registry (`LocaleMetadata`, `LanguageGroup`, `LanguageRegistry`, generator) |
| WP3 | `f24feab93` | Extended URL-code grammar + `DerivedLanguageMetadata` / `ExistingLanguage` |
| WP4 | `db1662ccc` | `aiml_duplicate_locale` + `UNIQUE KEY locale` + migration step 9 |
| WP5 | `c797e98db` | UMC-aligned admin CSS + `ComboboxControl` script + asset scoping |
| WP6 | `2f902955d` | Redesigned Add/Edit screen + trusted `handle_save()` |
| WP7 | `c79fc8a8a` | `acceptance/languages-admin-browser/` Playwright suite |
| WP8 | `e23bda8a7` | Docs |
| accept | (this commit) | Playwright executed on DEV (30/30), 3 spec checks corrected, deviation recorded |

## Gate results (per work package, at commit time)

| WP | phpcs | unit | integration |
|---|---|---|---|
| WP1 | green | 941 | 951 |
| WP2 | green | 957 (+16 `LanguageRegistryTest`) | guard/routing/language slice green |
| WP3 | green | 982 (+25) | 953 (full) |
| WP4 | green | 982 | 961 (full) |
| WP5 | green | 982 | 965 (full) |
| WP6 | green | 982 | 977 (full) |

Final full run at `2f902955d`: **phpcs green · unit 982 · integration 977**,
`node --check` clean on `languages-admin.js`, `playwright test --list` parses
the acceptance suite (30 tests / 15 checks × 2 viewports).

## Playwright acceptance execution (post-implementation, on DEV)

The branch was deployed to the DEV WordPress instance (bind mount, plugin
active) and the `admin_init` drift migration was run: schema advanced 8 → 9,
verified live — `code` is `VARCHAR(20)`, `UNIQUE KEY locale` present,
`aiml_locale_unique_blocked` absent.

`acceptance/languages-admin-browser` was then run against
`https://dev.biopentra.eu` with the browser in a pinned
`mcr.microsoft.com/playwright:v1.62.1-noble` container (the host has no browser
system libraries) and the test runner on the host (so `helpers/wp.ts` can call
`dev-wp`).

**Result: 30 / 30 passed** — all 21 checks across `desktop-1440` and
`narrow-390`. Report archived at
[`language-admin-redesign-evidence/playwright-report.json`](language-admin-redesign-evidence/playwright-report.json)
and [`playwright-run.txt`](language-admin-redesign-evidence/playwright-run.txt).

Three checks in the committed spec were corrected to match the shipped
behaviour (no production code changed):

- **Check 10** ("locale already present is rejected") drove a fully-used
  single-locale group, which the redesigned Add card deliberately drops from
  the selector. Rewritten to force the registered locale onto the Add form
  (tamper / race path) and assert the server's `aiml_duplicate_locale`.
- **Check 13** ("default `en_US` does not block `en_GB`") asserted the region
  option label contained "United States". The redesign labels the default's
  own locale `English (already added)` and disables it. Rewritten to assert
  the "(already added)" + `disabled` marker on `en_US` and that `en_GB` stays
  selectable and derives `en-gb`.
- **Checks 11 and 15-16** asserted a language *home* URL (`/de-de-formal/`,
  `/sv/`) resolves. That hits a **pre-existing** `redirect_canonical()`
  self-redirect loop that affects every non-default language home
  (`/de/`, `/sv/` included) and is **not introduced by this milestone** — the
  branch changes no routing production code (`git diff main...HEAD --
  src/Routing` is empty; `x-qm-redirects-redirect-trace:
  redirect_canonical()` on DEV). Rewritten to assert the three-segment prefix
  is routable — `/de-de-formal/<unknown>` and `/sv/<unknown>` return a clean
  404 (prefix stripped, inner path not found), no loop, no 500. The language
  home redirect loop is filed as a separate routing defect (see Deferred).

Post-acceptance regression gates (Docker): **phpcs green · unit 982** at this
commit. No PHP, schema, asset or `src/` file changed since `e23bda8a7` — only
`acceptance/**` specs, ADR-0028 and this log — so the integration suite (977
at `2f902955d`) is unaffected; the DEV migration to schema 9 was verified live
above.

## Decision coverage

| Decision | Where verified |
|---|---|
| 1 Registry is plugin-owned, offline, `language_code` | `LanguageRegistryTest::test_registry_loads_without_wordpress`, `..._every_language_code_is_two_or_three_lowercase_letters`, `..._every_locale_passes_the_production_locale_validator` |
| 2/3 Code derived at creation, immutable; Edit rules | `LanguagesAdminTest::test_curated_edit_keeps_identity_and_re_derives_display_fields`, `..._custom_edit_keeps_identity_but_allows_name_and_direction`, `..._the_screen_renders_the_add_and_edit_cards` |
| 5 Default excluded by exact locale only | `DerivedLanguageMetadataTest::test_adding_en_gb_when_default_is_en_us_succeeds`, `LanguagesAdminTest::test_re_adding_the_default_locale_is_rejected` |
| 6 Full normalized set | 206 locales / 162 groups; `LanguageRegistryTest` RTL + multi-locale + `en_US` + `de_DE_formal` assertions |
| 7 Locale uniqueness + blocked migration | `LanguageLocaleMigrationTest` (live schema, DB rejection, idempotency, blocked→resolve→complete cycle, notice gating) |
| 8 `ComboboxControl` selector | `LanguagesAdminAssetsTest` (deps `wp-element` + `wp-components`, scoped to screen); no custom combobox needed |
| 9/14 Explicit variants first-class, exact WP locale ids | `de_DE_formal` / `de_CH_informal` / `nl_NL_formal` in the registry; `ca_valencia` / `art_x*` dropped with a generator warning |
| 10 Neutral blocked-migration notice | `LanguageLocaleMigrationTest::test_blocked_notice_...` asserts no "delete" wording |
| 11 No version bump | header stays `1.12.0`; WP9 is separate |
| 13 Extended grammar | `LanguageValidationTest` `is_valid_code` provider (`ceb`, `de-de-formal`, `pt-pt-ao90` accepted); `RoutingTest::test_three_segment_code_resolves_and_strips` |

## Server-side trust boundary

`LanguagesAdminTest::test_curated_swedish_is_fully_derived_and_tampering_is_ignored`
and `..._curated_edit_keeps_identity_...` submit `code=hacked`, `name=Hacked`,
`direction=rtl`, `locale=zz_ZZ` and assert the stored row is the
registry-derived value.

## Recorded deviation from the sketched file layout

- **`LanguagesComponentRenderer` is not a separate class.** The plan sketched
  it mirroring Universal Multicurrency's `AdminComponentRenderer`; as built,
  the escaped-HTML component helpers are private methods on `LanguagesScreen`
  (its only consumer), covered by the screen's render tests. Deliberate — a
  standalone class adds indirection with no second caller. Also recorded in
  ADR-0028 ("Design-system reuse without coupling"). Extract when a second
  screen needs the same helpers.

## Deferred (for milestone acceptance)

- **Language-home `redirect_canonical()` loop (pre-existing routing defect,
  not this milestone).** `/<code>/` for any non-default language 301-loops to
  itself on DEV (`/de/`, `/sv/`, `/de-de-formal/` alike). The branch changes
  no routing code; `Router::filter_redirect_canonical()` (last touched on
  `main` 2026-08-15) allows a redirect whose target still carries the language
  prefix, and core's canonical redirect on a language front page targets that
  same prefixed URL. Needs its own bug / milestone. Does not affect the Add /
  Edit Language admin flow or three-segment prefix parsing (both verified).
- **Opportunistic `wp_get_available_translations()` enrichment.** Out of scope
  by decision; the registry is complete on its own.

## Migration matrix (WP4)

| Case | Result |
|---|---|
| Fresh install | `code` VARCHAR(20), `UNIQUE KEY locale`, `aiml_db_version = 9` |
| Clean 8 → 9 upgrade | ALTER applied, verified, version 9 |
| Dirty 8 upgrade (seeded duplicate) | no ALTER, stays 8, `aiml_locale_unique_blocked` set, notice hook registered |
| Duplicate resolved, re-run | advances to 9, option cleared |
| Simulated ALTER/verify failure | stays 8, logged, retried |
| Idempotent re-run from 8 | version 9, schema unchanged |
| Existing rows | byte-identical (full integration suite green) |
