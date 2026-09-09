# LANGUAGE ADMIN REDESIGN — Implementation Plan (IMPLEMENTED)

**Status:** IMPLEMENTED on `feature/language-admin-redesign` — awaiting milestone
acceptance. No release (WP9) performed. Validation:
[LANGUAGE_ADMIN_REDESIGN_VALIDATION_LOG.md](LANGUAGE_ADMIN_REDESIGN_VALIDATION_LOG.md).
**External review:** PASS — approved and frozen (5 review rounds)
**Canonical authority:** this document in `magpern/universal-multilingual`
**Scope:** Universal Multilingual only. Do not modify Universal Multicurrency,
WooCommerce, storefront/theme plugins, Elementor, or any third/shared plugin.

## Reconciled baselines (implementation start)

| Item | Value |
|---|---|
| Repo | `magpern/universal-multilingual` |
| `origin/main` | `96b8d9977` — `docs(release): record v1.12.0 release closure` |
| Feature branch | `feature/language-admin-redesign` |
| Version | **1.12.0** (no release bump in this milestone — WP9 only, after acceptance) |
| `Migrator::TARGET` | **8 → 9** (this milestone adds migration step 9) |
| Prior milestones | Floating Language Selector CLOSED; User Regional Preferences CLOSED |

**STATE:** admin UX + metadata-source improvement over the existing language /
routing authority. The persisted language contract (`aiml_languages` columns) is
preserved; the one schema change is a new `UNIQUE KEY locale` plus a `code`
column widening, both under migration step 9.

---

## Context — why this change

The `Multilingual → Languages` "Add a language" screen makes an administrator
hand-type every technical field: URL code, locale, English name, native name,
text direction, plus state and sort order. This is error-prone (a mistyped
`sv_se` produces a language whose translations never load) and it looks like a
stock WordPress settings form while the rest of the "Universal" plugin family
(Universal Multicurrency, Universal Geo Context) now shares a polished admin
design system.

Goal: rebuild the screen around **selection, not data entry** — pick a language
from a searchable list, pick a region only when it matters, and let the server
derive all canonical metadata from a plugin-owned registry. Visually align it
with Universal Multicurrency without any cross-plugin dependency. Preserve the
persisted language contract, routing invariants, and security posture.

---

## Frozen product decisions (non-negotiable)

1. **Registry.** GlotPress / WordPress locale data is an *offline authoring
   source* only. Runtime uses a plugin-owned PHP registry: no network, no
   dependency on installed language packs, no PHP `intl` dependency, no runtime
   dependency on Universal Multicurrency. Ship the full normalized
   WordPress-recognized locale set that can be represented safely. The registry
   locale identifier is the **exact WordPress runtime locale string** used by
   translation loading — upstream identifiers are never reshaped into invented
   alternatives. Every first-class registry locale must pass
   `Languages::is_valid_locale()`. The registry language identifier field is
   **`language_code`** (2 or 3 lowercase ASCII letters), *not* `iso_639_1`.

2. **Add Language UX.** Normal operation must not require typing URL code,
   locale, English name, native name, or text direction. The administrator
   selects: Language; regional/locale variant where applicable; Status; Sort
   order. Derived metadata is shown read-only. The searchable Language selector
   is WordPress core `ComboboxControl`. A custom progressive-enhancement
   combobox may be introduced **only** if `ComboboxControl` causes a concrete
   integration failure with the native fallback or the localized data model —
   and then implementation **STOPS** and reports the blocker for re-review. No
   silent custom combobox.

3. **Default language.** Exclusion from the Add selector is by **exact locale
   only**. If the default is `en_US`: `en_US` cannot be re-added; `en_GB` /
   `en_AU` remain selectable. Never suppress a whole language group because one
   of its locales is present.

4. **Locale/code immutability.** For curated **and** custom rows, `locale` and
   `code` are immutable after creation and read-only on Edit. Changing either
   requires delete + re-add. No code-change hooks, no route-history work, no
   automatic redirects for code changes.

5. **Curated Edit behaviour.** For registry-backed rows: `code` and `locale`
   preserved verbatim from the stored row; `name` and `direction` re-derived
   from the current registry; `native_name` = registry default plus an allowed
   display override; `status` and `sort_order` editable. POST attempts to alter
   canonical identity fields are ignored.

6. **Custom Edit behaviour.** For custom rows: `code` and `locale` immutable;
   `name`, `native_name`, `direction`, `status`, `sort_order` editable. Custom
   mode stays gated behind the `aiml_allow_custom_language` filter (default
   enabled) + `manage_options`.

7. **URL-code grammar.** Extended to `language` / `language-region` /
   `language-region-variant`, validator
   `^[a-z]{2,3}(-[a-z]{2}(-[a-z0-9]+)?)?$`. Examples: `sv`, `pt-br`,
   `de-de-formal`, `pt-pt-ao90`, `ceb`. Identity is derived only at creation,
   then immutable. This changes the routing contract → ADR required.

8. **URL derivation rules.** Parse registry metadata into `language_code`,
   optional region (uppercase `_XX` segment, lowercased), optional explicit
   variant (further `_yyy` segment or the variant-only `_[a-z0-9]{3,}` form,
   lowercased).
   - **Ordinary locale or plain regional variant:** take the first free
     candidate among existing rows (ignoring the row being edited):
     (a) `language`, (b) `language-region`. E.g. `sv_SE` → `sv`; `pt_PT` →
     `pt`; then `pt_BR` → `pt-br` because `pt` is taken.
   - **Explicit formal/script/orthography variant attached to a region:** the
     code is **fixed** at `language-region-variant` and is *never* shortened,
     even when the base is free. E.g. `de_DE_formal` → `de-de-formal`;
     `pt_PT_ao90` → `pt-pt-ao90`. Creation order therefore does not matter:
     adding `de_DE_formal` first yields `de-de-formal` (not `de`), and a later
     `de_DE` still gets `de`.
   - **Variant-only locale with no region** that cannot fit the grammar
     (`art_xemoji`, `art_xpirate`) is **not** first-class — the generator drops
     it; it is reachable only via custom mode.
   - No numeric suffixing. If the deterministic code is occupied →
     `aiml_conflicting_variant`.

9. **Duplicate-locale invariant.** Two rows must never share a locale. Enforced
   in application validation (`aiml_duplicate_locale`) **and** with a database
   `UNIQUE KEY locale`.

10. **Migration safety (schema 8 → 9).** Widen `code` `VARCHAR(12)` →
    `VARCHAR(20)`; add `UNIQUE KEY locale`. **Preflight duplicate locales
    first.** If duplicates exist: run no `ALTER`; do not advance
    `aiml_db_version` past 8; persist the conflicting locale + row ids; show a
    persistent, neutral admin notice saying the upgrade is paused and linking
    to safe manual-resolution guidance — **not** "delete a language" as the
    primary action. When clean: run one combined statement where MariaDB
    allows —
    `ALTER TABLE aiml_languages MODIFY code VARCHAR(20) NOT NULL, ADD UNIQUE KEY locale (locale)` —
    then **verify the actual schema** (`code` is `VARCHAR(20)` *and* the
    `locale` unique index exists). Only then set version 9 and clear the
    blocked state. If the `ALTER` or the verification fails (including a lost
    race where a duplicate slipped in after preflight): remain at version 8,
    record/log the failure, retry on a later pass. Never mark step 9 complete
    on an unverified schema.

11. **Styling.** The Languages screen visually matches the Universal
    Multicurrency admin design language: reuse/recreate its design tokens, page
    layout, header, cards, borders, radii, spacing, typography, labels, helper
    text, badges, panels/notices, buttons, responsive behaviour — re-prefixed
    for AIML. Do not load CSS from UMC; no runtime dependency between plugins;
    no global restyle of WordPress admin. Everything scoped to the Languages
    screen body class.

12. **Release.** No version bump / tag / release / production deploy during
    implementation. WP9 is a separate closure step, only after milestone
    acceptance.

---

## Current architecture (frozen facts, verified against `96b8d9977`)

- **One class renders list + add + edit:** `src/Admin/SettingsPage.php`
  (`final class SettingsPage`, ~1606 lines). No WP Settings API for languages,
  no `WP_List_Table`, no REST/AJAX for this screen (intentional, ADR-0002).
  Hand-rolled `<form action=admin-post.php>`.
- **Menu:** `add_menu_page(..., 'ai-multilingual', [$this,'render_languages'], 'dashicons-translation', 58)`
  plus a submenu alias with the same slug. Hook suffixes
  `toplevel_page_ai-multilingual` and `multilingual_page_ai-multilingual`.
  Constructed in `src/Plugin.php` (~line 988) with
  `($settings, $languages, $vault, $localized_urls, $routing_admission, $frontier)`.
- **Save flow:** `admin_post_aiml_save_language` → `handle_save_language()`
  (SettingsPage:546-570): `current_user_can('manage_options')`,
  `check_admin_referer('aiml_save_language')`, `$data` from `$_POST` via
  `sanitize_text_field(wp_unslash())`, then `insert()` / `update()`, then
  `redirect_with_result()` (→ `?aiml_error=` / `?aiml_updated=1`, rendered by
  `render_notice()`). Delete: `admin_post_aiml_delete_language`, nonce
  `aiml_delete_language_{id}`.
- **No assets** enqueued on this screen — `SettingsPage` has no
  `admin_enqueue_scripts`. House pattern for adding one:
  `src/Admin/GlossaryAdminPage.php` (gate on exact `$hook_suffix`, version from
  `AIML_VERSION`, `plugins_url(..., AIML_PLUGIN_FILE)`, hand-written asset, no
  build step).
- **Storage:** custom table `{$wpdb->prefix}aiml_languages`.
  `src/Database/Schema.php` `create_languages()` (lines 161-178): `language_id`
  SMALLINT PK, `code VARCHAR(12)` **UNIQUE**, `locale VARCHAR(20)` *not
  unique*, `name VARCHAR(100)`, `native_name VARCHAR(100) DEFAULT ''`,
  `direction VARCHAR(3) DEFAULT 'ltr'`, `is_default TINYINT(1)`, `status
  VARCHAR(16) DEFAULT 'preview'`, `sort_order SMALLINT UNSIGNED`, timestamps,
  `UNIQUE KEY code`, `KEY status_sort`. Created by `Migrator` `step_1` via raw
  `$wpdb->query()` (never `dbDelta`). `Migrator::TARGET = 8`, option
  `aiml_db_version`, numbered `steps()` map (1-8).
- **Repository/service:** `src/Language/Languages.php` (`final`, ~550 lines,
  `__construct(Cache $cache)`). Rows are plain `stdClass` via `hydrate()`.
  Constants `STATUS_*`, `DIRECTIONS`, `CACHE_KEY`.
  - Static WP-free validators: `is_valid_code()` = `/^[a-z]{2}(-[a-z]{2})?$/`;
    `is_valid_locale()` =
    `/^[a-z]{2,3}(_[A-Z]{2}(_[A-Za-z0-9]+)?|_[a-z0-9]{3,})?$/`;
    `is_valid_status()`; `can_transition()` (preview↔published, either→disabled,
    disabled→preview only).
  - `private validate()` (491-532) → normalized array or `WP_Error`
    (`aiml_invalid_code`, `aiml_invalid_locale`, `aiml_missing_name`,
    `aiml_invalid_status`; unknown `direction` coerced to `ltr`).
  - Duplicate **code** rejected in `insert()`/`update()` (`aiml_duplicate_code`)
    + `UNIQUE KEY`. **Duplicate locale is never checked — gap this milestone
    closes.**
  - `insert()` always writes `is_default=0`. `update()`: editing default forces
    `status=published`. `delete()`: default → `aiml_default_language`. Deleting
    a language keeps its translations (invariant I5).
  - `ensure_default($locale)` seeds the default row on activation with weak
    metadata (`name = $code`, `native_name = ''`,
    `direction = is_rtl() ? 'rtl' : 'ltr'`).
  - `$wpdb` confined to this class + `src/Database/*` (invariant 8,
    `tests/integration/PluginGuardTest.php`).
- **Routing:** `src/Language/LanguageResolver.php` (pure, no WP). The URL code
  is the first URL path segment matched verbatim as an opaque string, lowercase
  ASCII. Default language owns the unprefixed root. Anonymous resolution is
  URL-authoritative — no cookie / `Accept-Language` / geo (invariant 11,
  ADR-0024, `tests/integration/RoutingTest.php`). Localized-slug tables
  `aiml_slug_routes` / `aiml_route_history` key on `language_id`, not `code`.
- **WordPress locale metadata — verdict: no reliable offline core source.**
  `wp_get_available_translations()` is network-only (api.wordpress.org, 3s
  timeout, 3h site-transient, empty array on failure) and carries **no
  text-direction and no script**. `get_available_languages()` returns only
  installed packs. PHP `intl` ICU data is English-only on typical hosts. →
  plugin-owned curated registry required (same as Polylang, WPML,
  TranslatePress). API data: `pt_BR` = "Portuguese (Brazil)" / "Português do
  Brasil"; `pt_PT` = "Portuguese (Portugal)" / "Português"; `en_US` is **not**
  in the API and must be injected.
- **Universal Multicurrency design system** (`../universal-multicurrency`,
  v1.3.0): single admin CSS `assets/admin/umc-settings.css`; tokens scoped
  under body class `umc-settings-page` (added by `src/Admin/AdminAssets.php`
  only on `page=wc-settings&tab=umc`); asset enqueue gated to that screen;
  cache-bust via `filemtime()`. PHP render helpers in
  `src/Admin/AdminComponentRenderer.php` (page_intro, settings_card,
  choice_cards, toggle_row, select_row, input_row, number_row, status_badge,
  info/warning/success_panel, empty_state, sticky_save_bar) return escaped HTML
  strings; `src/Admin/AdminPageShell.php` renders the `.umc-shell-header` hero.
  Base tokens: `--umc-accent:#6c3cff` (hover `#5a2ef2`, soft `#f3f0ff`),
  `--umc-border:#e5e7eb`, `--umc-text:#1f2937`, `--umc-muted:#6b7280`,
  `--umc-surface:#fff`, `--umc-success:#10b981`,
  `--umc-shadow:0 6px 18px rgba(0,0,0,.05)`, `--umc-radius:14px`,
  `--umc-space-1/2/3:8/16/24px`. Design-system tokens:
  `--umc-ui-neutral-50:#f9fafb … -900:#111827`, `--umc-ui-card-radius:16px`,
  `--umc-ui-card-divider:rgba(17,24,39,.08)`,
  `--umc-ui-shadow-sm:0 1px 2px rgba(15,23,42,.04)`, semibold weight `650`.
  Field rows `.umc-ui-field-row` `display:grid;gap:8px;padding:14px 16px;
  border-radius:12px;background:var(--umc-ui-neutral-50)`; controls
  `min-height:36px;padding:6px 10px;border-radius:8px`. Primary button
  `background:var(--umc-accent);border-radius:8px;box-shadow:none;
  min-height:40px;font-weight:600`. Panels `--info #f0f6ff/#c7d9ff`,
  `--warning #fffbeb/#fde68a`, `--success #ecfdf5/#a7f3d0`. Family pattern: each
  Universal plugin **copies** the token blocks re-prefixed (universal-geo-context
  uses identical values as `--ugc-*`); no shared package is consumed at runtime.
- **Tests (Docker only — no PHP/Node on host, `CLAUDE.local.md`):** `composer:2.8`
  for install; `php:8.3-cli` for `phpcs` (hard gate) + unit; `aiml-test-runner`
  + `mariadb:11.4` on internal `aiml-test` network for integration.
  `acceptance/*-browser/` Playwright suites exist (floating-selector, jobs, …) —
  **none for the Languages admin.**

---

## Proposed UX structure

**Screen:** `Multilingual → Languages` (unchanged slug/URL). Assets gated to
`toplevel_page_ai-multilingual` + `multilingual_page_ai-multilingual`; body
class `aiml-languages-page` added via `admin_body_class` on those hooks only.

Top to bottom:

1. **Page header** (`.aiml-ui` hero): mark icon, title "Languages", subtitle.
2. **Notices** — `render_notice()` restyled as `.aiml-ui-panel--success/--error`.
3. **Languages list** — styled card/table (Code / Locale / Name / State /
   Default / Actions). `.aiml-ui-empty-state` when only the default exists.
4. **"Add a language" card** (`.aiml-ui-settings-card`):
   - **Language** — core `ComboboxControl` over a hidden native `<select>` of
     every registry group; each option `English — Native` ("Swedish — Svenska").
     Groups are not hidden when partly used.
   - **Regional variant** — native `<select>`, rendered only when the chosen
     group has >1 locale. Locales already registered (including the site
     default's exact locale) are shown disabled with "(already added)". A group
     whose every locale is taken shows an inline note.
   - **Derived summary** — read-only `.aiml-ui-readonly-summary`: URL prefix
     (`/sv/`), Locale (`sv_SE`), Native name (`Svenska`), Direction
     (`Left to right`). Updated live by JS from a localized registry blob;
     **re-derived authoritatively server-side on submit.**
   - **Status** — native `<select>` over `Languages::statuses()`, default
     Preview.
   - **Sort order** — `number` input pre-filled with `max(sort_order) + 1`.
   - Primary button "Add language".
5. **`<details>` "Advanced: custom language"** — collapsed. The legacy
   raw-field form, posting `aiml_custom=1`. Gated by `manage_options` +
   `aiml_allow_custom_language` (default `true`).

**Edit screen** (`?language_id=`): card "Edit «name»". `locale` and `code`
read-only for every row (curated and custom). Editable: `status`,
`sort_order`, `native_name` override; plus `name` + `direction` for custom
rows only. No language/region selectors, no locale/code inputs.

**JS data:** `wp_localize_script('aiml-languages-admin', 'aimlLanguagesAdmin', …)`
with the registry (groups + locales + english/native/direction), the existing
language identities, direction labels, i18n strings. No `wp-api-fetch`. The
form still posts through `admin-post.php` with `wp_nonce_field`.

---

## Technical architecture (new code)

| File | Responsibility |
|---|---|
| `src/Language/data/locales.php` | Curated registry data (returns a plain array). Provenance header: GlotPress source revision, snapshot date, generator version. GPL/GlotPress attribution. |
| `src/Language/LocaleMetadata.php` | `final readonly` VO: `group`, `locale`, `language_code` (2–3 lowercase ASCII, *not* necessarily ISO 639-1), `english_name`, `native_name`, `direction`, `script`, `region_label`. Constructor-validated, WP-free. |
| `src/Language/LanguageRegistry.php` | `final`. Loads `locales.php` once (static memo). API: `groups()`, `group(string):?LocaleMetadata`, `has_group()`, `locales_for(string)`, `group_for_locale(string):?string`, `metadata_for_locale(string):?LocaleMetadata`. No `$wpdb`, no network. |
| `src/Language/ExistingLanguage.php` | `final readonly` identity VO: `language_id`, `code`, `locale`, `group` (nullable — `null` for a custom row), `is_default`. Built from `Languages::all()` rows. |
| `src/Language/DerivedLanguageMetadata.php` | `final`. `static derive(string $locale, string $group, ExistingLanguage[] $existing, ?int $editing_id, LanguageRegistry $registry): array\|WP_Error` → canonical `code`, `name`, `native_name`, `direction`, applying decision 8. Pure, no DB, no globals. Used by the admin handler **and** unit tests. |
| `src/Admin/Languages/LanguagesScreen.php` | Focused screen class extracted from `SettingsPage` (WP1). Registers its own `admin_post_*`, submenu callback, `admin_enqueue_scripts`, `admin_body_class`. `MENU_SLUG` + hook suffixes unchanged. |
| `src/Admin/Languages/LanguagesComponentRenderer.php` | `final`, escaped-HTML component helpers mirroring UMC's `AdminComponentRenderer` (only the pieces this screen uses). |
| `assets/languages-admin/languages-admin.css` | UMC tokens + components, re-prefixed `--umc-*`→`--aiml-*` / `.umc-ui-*`→`.aiml-ui-*`, scoped under `.aiml-languages-page`. Hand-written, no build step. |
| `assets/languages-admin/languages-admin.js` | Mounts core `ComboboxControl` (deps `wp-components`, `wp-element`) over a native `<select>` + live derived-summary + region-select show/hide. Hand-written fallback combobox only if core's component cannot integrate — and only after re-review. |
| `bin/build-locale-registry.php` | Dev-only generator: GlotPress `GP_Locales` data + a hand overrides file → prints `locales.php` for human review. Not autoloaded, not shipped (dist excludes). |

**`Languages.php` changes** (no public signature changes): new
`find_by_locale(string $locale): ?object`; enforce `aiml_duplicate_locale` in
`insert()`/`update()`; widen `is_valid_code()` to
`^[a-z]{2,3}(-[a-z]{2}(-[a-z0-9]+)?)?$` and update its docblock + the
routing-contract note.

**`Schema.php` / `Migrator.php`:** `create_languages()` DDL gains `UNIQUE KEY
locale` and `code VARCHAR(20)`; `Migrator` gains `step_9_language_metadata`
per decision 10; `TARGET = 9`.

**Server-side trust boundary (`handle_save_language`):**
- **Curated add:** trusts `registry_group`, `locale` (must belong to the
  group), `status`, `sort_order`. Calls `DerivedLanguageMetadata::derive()`;
  takes `code`/`name`/`native_name`/`direction` from the result. POSTed values
  for those four are discarded.
- **Curated edit:** trusts `language_id`, `status`, `sort_order`,
  `native_name_override`. `code`/`locale` preserved verbatim from the stored
  row; `name`/`direction` re-derived from the registry. POSTed
  `code`/`locale`/`name`/`direction` ignored.
- **Custom add/edit** (`aiml_custom=1`, behind cap check +
  `aiml_allow_custom_language`): trusts the raw fields, but on edit `code` and
  `locale` stay pinned to the existing row.

---

## Work packages (implementation order)

### WP1 — Extract `LanguagesScreen` from `SettingsPage` (move-only)
- **Objective:** stop growing the 1606-line `SettingsPage`; give the screen a
  focused class before adding features.
- **Steps:** cut `render_languages`, `render_language_form`,
  `handle_save_language`, `handle_delete_language` and the languages-only
  helpers (`render_notice`, `status_label`, `text_row`, `redirect_with_result`)
  verbatim into `src/Admin/Languages/LanguagesScreen.php`; it registers its own
  `admin_post_*` + submenu callback. `SettingsPage` keeps what it still needs
  (helpers may be duplicated for now — **no** shared trait/helper unless the
  Settings screen genuinely needs the same code). `MENU_SLUG` + hook suffixes
  unchanged. Wire in `src/Plugin.php`.
- **Tests:** existing `AdminAuthorizationTest` passes unchanged; add a guard
  that `admin_post_aiml_save_language` resolves to the new class.
- **Acceptance:** strict equivalence — identical rendered HTML, redirects,
  behaviour; `phpcs` + unit + integration green. Own commit.
- **Dependencies:** none.

### WP2 — Locale registry + metadata model
- **Files:** `src/Language/data/locales.php`, `src/Language/LocaleMetadata.php`,
  `src/Language/LanguageRegistry.php`, `bin/build-locale-registry.php`,
  `readme.txt` (attribution), dist-exclude config.
- **Decisions:** 1. `language_code` not `iso_639_1`. Exact WP runtime locale
  ids. Full normalized WP-recognized set + injected `en_US`. Preserve
  recognized region/formal/script/orthography variants that are valid runtime
  locales and representable; drop unsupported source entries with an explicit
  generator warning (never silently reshape).
- **Steps:** write the generator (GlotPress locale data + hand overrides:
  region labels, `en_US`, drops); generate `locales.php`; human review of the
  diff; implement `LocaleMetadata` (readonly VO, constructor-validated) and
  `LanguageRegistry` (static memo + query API); provenance header.
- **Tests:** `tests/unit/LanguageRegistryTest.php` — every group has ≥1 locale;
  every locale passes `Languages::is_valid_locale()`; every `language_code`
  matches `/^[a-z]{2,3}$/`; `group_for_locale()` round-trips; `en_US` present;
  `ar`/`he`/`fa`/`ur` are `rtl`; `pt`/`en`/`zh` multi-locale; a recognized
  formal/orthography variant present where WP supports it. Runtime requires no
  DB / network / language pack / `intl`.
- **Dependencies:** none (parallel with WP1).

### WP3 — Extended URL-code grammar + deterministic derivation
- **Files:** `src/Language/Languages.php` (`is_valid_code()` regex + docblock +
  routing-contract note), `src/Language/ExistingLanguage.php`,
  `src/Language/DerivedLanguageMetadata.php`, ADR.
- **Steps:** widen `is_valid_code()` to
  `^[a-z]{2,3}(-[a-z]{2}(-[a-z0-9]+)?)?$`; implement `ExistingLanguage`;
  implement `derive()` per decision 8 — **explicit-variant locales get a fixed
  `language-region-variant` code**, ordinary locales walk `language` →
  `language-region` first-free — taking `ExistingLanguage[]`, returning
  `['code','name','native_name','direction']` or `WP_Error`. Derivation is pure
  and deterministic.
- **Tests:**
  - `tests/unit/LanguageValidationTest.php` — extend the `is_valid_code` data
    provider: accept `de-de-formal`, `pt-pt-ao90`, `ceb`; still reject `DE`,
    `de_de`, `de--formal`, trailing `-`.
  - `tests/unit/DerivedLanguageMetadataTest.php` — `sv_SE` → `sv`/`Swedish`/
    `Svenska`/`ltr`; `pt_BR` alone → `pt`; `pt_BR` when `pt_PT` present →
    `pt-br`; `pt_BR` when `pt` held by an unrelated custom row → `pt-br`
    (different message); `de_DE` present, add `de_DE_formal` → `de-de-formal`;
    **reverse order** — `de_DE_formal` first → `de-de-formal` (not `de`), then
    `de_DE` → `de`; `pt_PT_ao90` first → `pt-pt-ao90`, then `pt_PT` → `pt`;
    re-adding the same explicit-variant locale → `aiml_conflicting_variant`;
    `ar_*` → `rtl`; unknown/out-of-group locale → `aiml_unknown_locale`; exact
    locale already registered → `aiml_duplicate_locale`; `en_GB` when default
    `en_US` → `en-gb`; editing id excluded from the collision set.
  - `tests/unit/LanguageRegistryTest.php` (cross-check) — every registry
    locale, derived against an empty existing-set, yields a code that passes
    the extended grammar.
  - `tests/integration/RoutingTest.php` — a three-segment code (`de-de-formal`)
    resolves as a prefix and strips correctly; anonymous resolution still
    URL-authoritative.
- **Dependencies:** WP2.

### WP4 — Duplicate-locale enforcement + schema migration
- **Files:** `src/Language/Languages.php`, `src/Database/Schema.php`,
  `src/Database/Migrator.php`.
- **Steps:** `find_by_locale()`; enforce `aiml_duplicate_locale` in
  `insert()`/`update()`; `Schema` DDL gains `UNIQUE KEY locale` +
  `code VARCHAR(20)`; `Migrator` `step_9_language_metadata` per decision 10
  (preflight → blocked state + neutral notice, or one combined `ALTER` →
  verify `code VARCHAR(20)` + `locale` unique index → advance to 9; on
  `ALTER`/verify failure stay at 8 + log + retry). `TARGET = 9`.
- **Tests:** extend `tests/integration/LanguagesTest.php`; add
  `tests/integration/LanguageLocaleMigrationTest.php` — fresh install has the
  unique key, `code` is `VARCHAR(20)`, `current_version == 9`;
  clean v8 → v9 upgrade verified; dirty v8 upgrade (seeded duplicate) → no
  `ALTER`, stays 8, `aiml_locale_unique_blocked` set, notice hook registered;
  duplicate removed → next pass advances to 9; simulated `ALTER`/verify failure
  → stays 8, logged, retries; idempotent; existing rows byte-identical. Confirm
  / adjust any `TARGET` assertion in `PluginGuardTest`.
- **Dependencies:** merge after WP3.

### WP5 — Admin assets (CSS + selector infrastructure)
- **Files:** `assets/languages-admin/languages-admin.css`,
  `assets/languages-admin/languages-admin.js` (enqueued with deps
  `wp-components`, `wp-element`; `wp_enqueue_style('wp-components')`).
- **Steps:** copy + re-prefix UMC tokens/components (settings-card, field-row,
  readonly-summary, status-badge, panel, primary-button, empty-state, header);
  drop UMC's WooCommerce `.form-table` overrides. Build the selector on core
  `ComboboxControl` over a real native `<select>` that stays the submitted
  source of truth and the JS-off fallback; on select it writes the native
  value and dispatches `change`; the derived-summary + region logic react to
  that real control. Enqueue only on the two Languages hook suffixes.
  `prefers-reduced-motion`; responsive breakpoints 1024/782/480/390.
- **STOP CONDITION:** if `ComboboxControl` cannot integrate cleanly with the
  native fallback or localized data model — stop, report the exact blocker,
  what was attempted, and proposed custom-combobox scope. Do not implement the
  fallback without approval.
- **Tests:** ARIA / keyboard / JS-off asserted by Playwright in WP7.
- **Dependencies:** WP2.

### WP6 — New Add/Edit screen + server handler
- **Files:** `src/Admin/Languages/LanguagesScreen.php`,
  `src/Admin/Languages/LanguagesComponentRenderer.php`, `src/Plugin.php`
  (inject `LanguageRegistry`).
- **Steps:** `render_languages()` → styled header + list + Add card +
  `<details>` custom form; split into `render_add_card()` /
  `render_edit_card()` / `render_custom_form()`. `handle_save_language()`
  branches on `aiml_custom` per the trust boundary above; curated branch calls
  `DerivedLanguageMetadata::derive()` and discards POSTed canonical fields.
  Keep nonce `aiml_save_language`, cap check, `redirect_with_result()`.
  Region step offers every group; only exact registered locales disabled.
  Enqueue assets + `wp_localize_script` the registry / existing-identities /
  labels blob.
- **Tests:** new `tests/integration/LanguagesAdminTest.php` — curated POST
  creates a row with registry-derived name/native/direction/code; POSTed
  `direction=rtl` / `name=Hacked` / `code=xx` on a curated LTR locale ignored;
  duplicate locale → `aiml_duplicate_locale` notice; `pt_PT` then `pt_BR` →
  `pt` then `pt-br`; `de_DE` then `de_DE_formal` → `de` then `de-de-formal`;
  default `en_US` + `en_GB` → `en-gb`; re-adding the default's own locale →
  `aiml_duplicate_locale`; custom POST works when allowed, blocked when the
  filter returns `false`; curated + custom Edit change status/sort/native-name
  override but a POST changing `locale`/`code` is ignored (row unchanged);
  malformed `registry_group`/`locale` rejected server-side. Extend
  `AdminAuthorizationTest` — handlers still cap- and nonce-gated; logged-out
  `admin-post.php` exposure unchanged.
- **Dependencies:** WP1, WP2, WP3, WP4, WP5.

### WP7 — Playwright acceptance suite
- **Files:** new `acceptance/languages-admin-browser/` (mirror
  `floating-selector` / `jobs`; reuse the Playwright config).
- **Coverage:** searchable selector; English/native filtering; keyboard
  operation; a11y semantics of the chosen core control; Swedish single-locale
  flow; Portuguese regional flow; Arabic RTL flow; derived summary; duplicate
  locale handling; default `en_US` + adding `en_GB`; explicit variant route
  (`de_DE` then `de_DE_formal` → `/de/` then `/de-de-formal/`); reverse-order
  explicit variant if practical; JS-disabled native fallback; curated Edit
  identity immutability; custom Edit identity immutability; custom-language
  mode; custom-language filter disabled; blocked-migration notice; responsive
  desktop/narrow; no horizontal overflow; route resolution after creation.
  Archive evidence under `docs/plans/<code>-evidence/` per repo convention.
- **Dependencies:** WP5, WP6.

### WP8 — Documentation closure (no version bump)
- **Files:** ADR(s) (below); this plan's status → implemented; a validation
  log; `CHANGELOG.md` (Unreleased); `readme.txt` changelog + GlotPress
  attribution; `docs/user-manual` entry for adding a language.
- **Acceptance:** ADR merged; docs updated; **no `Version:` change**.
- **Dependencies:** WP1–WP7.

### WP9 — Release (separate, after acceptance — NOT this run)
Bump `Version:` header, move Unreleased changelog entries, tag `vX.Y.Z`, push
tag; CI verifies tag == header + publishes the zip; bucket-publish standing
rule applies.

---

## ADRs

- **ADR-0028 — Curated locale registry and selection-based language admin.**
  Plugin-owned PHP registry as source of truth; GlotPress data as offline
  authoring input with recorded provenance; registry locale id = exact WP
  runtime string; `language_code` (2–3 letters) not `iso_639_1`; server-side
  canonical metadata derivation (client values never trusted); `ExistingLanguage`
  identity model; locale/code immutable after create (curated + custom); DB
  `UNIQUE KEY locale`; blocked-migration behaviour (schema 8 stays until data
  is clean; verify before advancing); `ComboboxControl` as the selector with a
  re-review gate before any custom combobox; UMC design-system reuse by
  re-prefixed copy, no runtime dependency, decision not to extract a shared
  package yet.
- **ADR-0029 — Extended language URL-code grammar.** `language` /
  `language-region` / `language-region-variant`
  (`^[a-z]{2,3}(-[a-z]{2}(-[a-z0-9]+)?)?$`); deterministic derivation at
  creation, immutable afterward; explicit variant segment always preserved in
  the code regardless of base-code availability; `code` column widened to
  `VARCHAR(20)`; `LanguageResolver` matches the segment as an opaque string so
  anonymous URL-authoritative resolution (ADR-0024, invariant 11) is unchanged;
  variant-only-no-region locales are not first-class.

---

## Required validation (Docker, per `CLAUDE.local.md`)

```
docker run --rm -v "$PWD":/app -w /app composer:2.8 composer install
docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpcs            # hard gate
docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit -c phpunit.xml.dist
# integration: aiml-test internal network + mariadb:11.4 + aiml-test-runner
docker run --rm --network aiml-test ... aiml-test-runner vendor/bin/phpunit -c phpunit-integration.xml.dist
# playwright: existing acceptance runner against acceptance/languages-admin-browser/
```

Run: `phpcs`; unit; integration; fresh-install migration; clean v8→v9 upgrade;
dirty v8 blocked upgrade; retry-after-resolution upgrade; ALTER/verify-failure
path; Playwright Languages admin suite; `RoutingTest`; `PluginGuardTest`; any
docs/release audit that does not require a version bump/tag. Images stay
pinned — never `:latest`.

---

## Stop conditions

Stop and report instead of improvising if: another milestone is actively
mid-flight; the routing contract conflicts with an existing invariant not
identified here; the registry contains legitimate WP runtime locales that
cannot pass the approved locale validator; legitimate first-class variants
cannot be represented by the approved URL grammar; `ComboboxControl` cannot
integrate cleanly; migration cannot safely preserve existing installations;
implementation would require changing a settled decision; unrelated repo
changes make the branch unsafe.
