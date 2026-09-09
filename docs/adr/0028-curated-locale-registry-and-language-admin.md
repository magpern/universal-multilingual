# ADR-0028 — Curated locale registry and selection-based language admin

**Status:** Accepted

## Context

The `Multilingual → Languages` "Add a language" screen requires an administrator
to hand-type URL code, locale, English name, native name and text direction. A
mistyped locale (`sv_se`) silently produces a language whose translation files
never load. The screen is also stock WordPress chrome while the rest of the
"Universal" plugin family shares a polished admin design system.

WordPress exposes **no reliable offline source** of language/locale metadata:

- `wp_get_available_translations()` is a network call to api.wordpress.org
  (3s timeout, 3h transient, empty array on failure) and carries no
  text-direction and no script.
- `get_available_languages()` returns only installed language packs.
- PHP `intl` ICU data is English-only on typical hosts.
- WooCommerce `i18n/locale-info.php` is country-keyed and coarse.

Polylang, WPML and TranslatePress all ship their own registry for this reason.

## Decision

### Plugin-owned registry as the single runtime source of truth

`src/Language/data/locales.php` returns a plain PHP array. `LanguageRegistry`
(`final`, static memo) reads it. Runtime requires **no** database, network,
installed language pack, or PHP `intl` extension. There is **no** runtime
dependency on Universal Multicurrency.

GlotPress / WordPress locale data (`GP_Locales`, GPL-2.0-or-later) is used
**only as an offline authoring input** by the dev-only, not-shipped generator
`bin/build-locale-registry.php`. `locales.php` carries a provenance header
recording the GlotPress source revision, the snapshot date, and the generator
version. Refreshing the data is a deliberate, manual, per-milestone action.

### Registry contract

Each locale entry carries: `group`, `locale`, `language_code`, `english_name`,
`native_name`, `direction` (`ltr`/`rtl`), optional `script`, optional
`region_label`.

- The registry **language identifier field is `language_code`**, not
  `iso_639_1`: supported identifiers are 2 **or** 3 lowercase ASCII letters
  (`ceb`), which ISO 639-1 (two-letter only) does not describe.
- The **`locale` value is the exact WordPress runtime locale string** used by
  translation loading (`WPLANG` / `.mo` filename). Upstream identifiers are
  never reshaped into invented alternatives.
- **Every first-class registry locale must pass `Languages::is_valid_locale()`**
  (asserted by a registry invariant test). A desired upstream locale the
  validator rejects is not first-class — it is reachable only through custom
  mode; the generator drops it with an explicit warning rather than reshaping
  it.
- The registry ships the full normalized WordPress-recognized set that can be
  represented safely, plus injected `en_US` (which the translations API omits).

### Server derives canonical metadata; the client is never trusted

`DerivedLanguageMetadata::derive()` (pure, no DB, no globals) is the single
authority for `code`, `name`, `native_name` and `direction`. It takes the
submitted `locale` + `group`, the full set of existing language identities
(`ExistingLanguage` VOs — id, code, locale, group, is_default), and the id
being edited. The admin handler discards any POSTed `code` / `name` /
`native_name` / `direction` in curated mode. `direction` in particular is
always the registry value; RTL is never a submitted field.

### Default-language exclusion is by exact locale

If the site default is `en_US`, only `en_US` is filtered out of the Add
selector — `en_GB`, `en_AU` and the rest of the English group remain
selectable. A whole language group is never suppressed because one of its
locales is present.

### Identity is immutable after creation

For curated **and** custom rows, `locale` and `code` are read-only on Edit.
Changing either means delete + re-add. There are no code-change hooks, no
route-history rewrites, and no automatic redirects. Curated Edit re-derives
`name` / `direction` from the current registry but preserves stored `code` /
`locale` verbatim, so a future registry refresh corrects display metadata
without ever touching routing identity. `native_name` accepts a per-row
display override.

### Database enforces locale uniqueness

Two language rows must never share a locale. Enforced in `Languages` validation
(`aiml_duplicate_locale`) **and** by a new `UNIQUE KEY locale` on
`aiml_languages` (migration step 9).

### Blocked-migration behaviour

Migration step 9 (schema 8 → 9) widens `code` to `VARCHAR(20)` and adds
`UNIQUE KEY locale`. It **preflights for duplicate locales**. If any exist it
runs no `ALTER`, does not advance `aiml_db_version` past 8, persists the
conflicting locale + rows, and shows a persistent neutral admin notice that the
upgrade is paused with a link to safe manual-resolution guidance — never
"delete a language" as the primary action. The rest of the plugin keeps
running on schema 8; the application-level duplicate-locale rule prevents new
collisions meanwhile. When the data is clean the migration runs one combined
`ALTER`, **verifies the actual resulting schema** (`code` is `VARCHAR(20)` and
the `locale` unique index exists), and only then advances to 9. On `ALTER` or
verification failure it stays at 8, logs the failure, and retries on a later
pass. The step is never marked complete on an unverified schema.

### Selector: WordPress core `ComboboxControl`

The searchable Language selector is core `@wordpress/components`
`ComboboxControl` mounted over a hidden native `<select>` that remains the
submitted source of truth and the JS-off fallback. A hand-written
progressive-enhancement combobox may be introduced **only** if `ComboboxControl`
causes a concrete integration failure with the native fallback or the localized
data model — and then implementation stops and the blocker is brought back for
re-review. No custom combobox is introduced silently.

### Design-system reuse without coupling

The Languages screen matches the Universal Multicurrency admin design language
by **copying** its tokens and the components this screen uses into
`assets/languages-admin/languages-admin.css`, re-prefixed `--umc-*` → `--aiml-*`
and `.umc-ui-*` → `.aiml-ui-*`, scoped under a new `aiml-languages-page` body
class. No CSS is loaded from Universal Multicurrency; there is no runtime
dependency; WordPress admin controls are not globally restyled. Extracting a
shared design-system package is explicitly **deferred**; reconsider when three
or more Universal plugins are visibly drifting.

The plan sketched a separate `LanguagesComponentRenderer` class mirroring
Universal Multicurrency's `AdminComponentRenderer`. As implemented, the
escaped-HTML component helpers (hero, card, field, summary, badge, panel,
list, empty-state) are **private methods on `LanguagesScreen`** rather than a
standalone class. `LanguagesScreen` has exactly one consumer of them and the
methods are covered by the screen's own render tests; a separate class would
add indirection without a second caller. This is a deliberate deviation from
the sketched file layout, not a functional change — extract the class if and
when a second screen needs the same helpers (the same trigger as the shared
design-system package).

## Consequences

- Existing `aiml_languages` rows keep working unchanged. The registry only
  populates columns that already exist; a stored locale absent from the
  registry renders as a "custom" row.
- `Migrator::TARGET` becomes 9. An install with pre-existing duplicate locales
  legitimately sits at `current_version = 8` until an operator resolves the
  duplicate; the migration retries automatically.
- `PluginGuardTest` invariants are unaffected: `$wpdb` stays confined to
  `Languages` + `src/Database/*`, no translation-plugin coupling, anonymous
  resolution stays URL-authoritative.
- The custom-language escape hatch keeps parity with today's raw-field
  capability for locales outside the registry, behind
  `aiml_allow_custom_language` (default on).
- No version bump ships with the implementation; release is a separate closure
  step after acceptance.

See also: ADR-0029 (extended URL-code grammar), ADR-0002 (no REST/AJAX for this
screen), ADR-0008 (language state model), ADR-0024 (anonymous cache contract).
