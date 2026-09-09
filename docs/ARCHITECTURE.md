# Architecture

## The idea

There is exactly one WordPress object per piece of content. A translation is
not another post — it is a set of stored strings applied over the canonical
object while the page renders. Nothing about translating writes to `wp_posts`,
`wp_postmeta` or any WooCommerce table.

That choice drives everything else. Because the source object never moves, IDs,
permalinks, menus, relationships, inventory and orders keep working exactly as
they did before the plugin was installed, and disabling the plugin returns the
site to its previous behaviour with no cleanup.

## Layers

| Layer | What it covers | Status |
|---|---|---|
| A — object fields | Known post, term and meta fields | Milestone 1 (post title, excerpt, classic body) |
| B — structured content | Gutenberg blocks, Elementor components | Milestone 2 / 6 |
| C — residual strings | gettext and theme strings not backed by a field | Milestone 6 |

## Segments

The unit of translation is a *segment*: one addressable piece of one field of
one object in one language. Identity is
`(source_type, source_id, segment_hash, language_id)`, where `segment_hash` is
`sha1(field_key ␟ segment_key)`.

Hashing the pair rather than indexing the raw columns keeps the unique key at
roughly 131 bytes instead of a kilobyte, and makes every write an upsert — so a
retried job is a no-op rather than a duplicate row.

Segment keys are designed to stay stable across edits:

| Kind | Example | Milestone |
|---|---|---|
| field | `post_title`, `post_excerpt`, `post_content` | 1 |
| block | `block:0.innerHTML:2` | 2 |
| component | `elementor:a1b2c3:heading` | 6 |
| string | `string:<sha1>` | 6 |

## Two hashes

`source_hash` answers *did the meaning of the source change?* It is computed
over normalized text, where normalization depends on the segment's format —
collapsing whitespace is harmless in a title and destructive inside `<pre>` or a
JSON string. The algorithm is versioned per row (`norm_version`) so changing the
rules later cannot mark an entire translated site stale at once.

`translation_hash` is an integrity marker over the stored translation. Because
the plugin owns the write path, it does *not* detect ordinary editing — an
editor saving through the UI updates text and hash together. What it catches is
change from outside: direct SQL, a partial restore, replication damage. Its
forward use is comparison against remembered historical states
(`last_machine_hash`, `reviewed_hash`), which arrive with the AI milestone.

## Provenance and freshness are separate

`status` records provenance: `missing`, `machine_translated`,
`manually_edited`, `reviewed`, `failed`, `ignored`.

Freshness is derived from the hashes and materialized in `is_stale`, so "show me
everything needing review" stays one indexed query. A single enum would have
conflated the two: a translation can be machine-produced *and* current, or
reviewed *and* stale.

A source edit sets `is_stale` and refreshes the stored source snapshot. It never
touches `translated_text` or `status`.

## Routing

`/sv/about/` is resolved by stripping the prefix from `REQUEST_URI` on
`plugins_loaded` at priority 999, before `WP::parse_request()` reads it. Every
rewrite rule the site already has then matches unchanged — core's, WooCommerce's
and any plugin's — with no per-language duplication and no rewrite state to
flush.

Priority 999 is chosen so every plugin has loaded, and so the `locale` filter is
in place before `load_default_textdomain()` runs at `wp-settings.php:704`.

Outbound, `home_url` gains the prefix — but the filter attaches on
`parse_request`, not before. `WP::parse_request()` calls `home_url()` itself and
strips that path from the request URI with an unanchored pattern; with the
filter live during routing, a Swedish request for `/svenska-sidan/` would have
its first two characters eaten and 404. `RoutingTest` pins this down.

## Language states

One column, three states, with explicit transitions:

- `disabled` — not a route at all; existing translations are retained.
- `preview` — routable only for a user holding `aiml_translate`; absent from
  switchers and, later, from hreflang and sitemaps.
- `published` — public.

A disabled language returns through `preview` before it can be published, so
nothing goes live without someone having looked at it. New languages start in
`preview`.

There is deliberately no per-language fallback chain: the default language is
the implicit fallback, and arbitrary chains need a complete policy for
mixed-language pages, canonical URLs, hreflang and indexability first.

## Caching

Two counters — a global epoch and a per-language counter — are embedded in every
cache key, so invalidation is one integer write rather than key enumeration.
That matters because the target stack runs Redis through Predis, where prefix
deletion is not cheaply available.

The trade-off is deliberate and documented in ADR-0012: one translation write
invalidates every cached entry for that language, not just the affected object.
Always correct, wasteful at scale, and cheap to improve later by adding a
per-object counter to the same key shape.

Nothing containing cart state, nonces, prices, stock or per-user data is ever
cached — only pure functions of source content, translations and language.

That internal object cache is separate from whatever reverse-proxy / full-page
cache sits in front of the site. For anonymous requests, `host + request_uri`
alone must be sufficient to determine the rendered language — see ADR-0024.

## Component map

```
universal-multilingual.php  Loader: guards, constants, activation hook, boot
uninstall.php           No-op unless retention is switched off
src/
  Plugin.php            Composition root; also wires stale detection
  Settings.php          Owns aiml_settings; pure defaults()/sanitize()
  Database/
    Schema.php          Table names and DDL
    Migrator.php        Versioned explicit-SQL steps; drift check
  Language/
    Languages.php       CRUD, validation, state transitions
    LanguageResolver.php  Pure: (path, languages, capability) -> language
    LanguageContext.php   Request state + exception-safe switch stack
  Routing/
    Router.php          Prefix strip, locale, home_url, canonical guard
  Translation/
    Store.php           Segment repository + format-aware hashing
    Extractor.php       Source segments + body translatability guard
    Renderer.php        Overlay filters
  Frontend/
    Switcher.php        Shortcode, menu integration, renderer
  Cache/Cache.php       The only object-cache access
  Admin/
    SettingsPage.php    Languages and Settings screens
    Editor.php          Minimal translation editor
  Cli.php               Four WP-CLI commands
  Promotion/            DEV -> PROD translation promotion (ADR-0030)
    ObjectNaturalKey.php        Deterministic env-independent key builder (pure)
    TranslationPackage.php      Manifest/payload + dual checksums (pure)
    JsonPackageSerializer.php   Untrusted-input decode (size/depth/shape)
    TranslationPackageValidator.php  format v1 + UUID + checksums
    ObjectIdentityResolver.php  Read-only UUID-first -> natural key -> fingerprint
    TranslationConflictDetector.php  3-way merge truth table (pure)
    LanguageMap.php             Conservative locale/code mapping (pure)
    ReviewToken.php             Stateless HMAC dry-run token (pure crypto)
    TranslationExportService.php  Walk -> Store reads -> mint UUID -> package
    TranslationImportService.php  validate() / plan() (read-only) / apply()
    PromotionCli.php            wp aiml promotion ...
  Rest/PromotionController.php   aiml/v1/promotion/{export,import/*,history}
  Admin/PromotionAdminPage.php   Vanilla wp-api-fetch screen
  Database/
    ObjectIdentityRepository.php / PromotionStateRepository.php /
    PromotionLogRepository.php   The only $wpdb access for the three new tables
```

## Translation promotion (ADR-0030)

Promotion never copies `aiml_*` tables. It serializes translated **segments**
with a plugin-owned **UUID** (minted on the source before export) plus a
deterministic natural key, and a per-segment source hash. Import is a
strictly read-only **dry-run** (classify every segment against the local
`Store` row and the `aiml_promotion_state` baseline; mint nothing; emit no
hooks) that returns a stateless signed **review token**, followed by an
explicit **apply** that re-verifies the token, re-plans, refuses on any drift,
revalidates each row's preconditions immediately before writing, and writes
only through `Store::save_translation()` / `save_slug_candidate()`. Routes stay
a per-environment projection — only the slug candidate crosses.

Resolution and request state are separate classes on purpose: one is a pure
function evaluated once, the other is mutable state read by every overlay for
the rest of the request.
