=== Universal Multilingual ===
Contributors: magpern
Tags: multilingual, translation, woocommerce, gutenberg, ai
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multilingual layer for WordPress: canonical content with segment translations applied as render-time overlays.

== Description ==

Universal Multilingual stores one canonical object per content item and applies language overlays at render time. Version 1.12.0 adds logged-in Regional Preferences (preferred language) and an optional floating language selector (default off). Version 1.11.0 adds Site Translate (coverage-aware picker, chunked Jobs, Run batch now, Localized URL batch). Version 1.10.0 adds the DeepSeek AI provider and per-provider generation settings. Version 1.9.0 rebranded to Universal Multilingual.

== Installation ==

1. Upload the `universal-multilingual` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Confirm database schema version 8 (option `aiml_db_version`).
4. Configure languages, providers, and rollout in the Universal Multilingual admin screens.
5. Publication gate and auto-publication mode default off/manual — enable only after reviewing release notes.
6. Localized URLs default OFF; enable only after reviewing MSEO release notes and verifying routes.

== Changelog ==

= 1.12.0 =
* Regional Preferences: logged-in preferred language on WordPress profile and WooCommerce Account details; public aiml_get/set_preferred_language API; optional composition with Universal Multicurrency. URL language remains authoritative.
* Optional floating language selector (default off): edge-docked visitor control using existing switcher URLs, progressive enhancement, keyboard access, authenticated best-effort preference persist. No flags, no language cookie, no DB migration.

= 1.11.1 =
* Automatic updates from a private update server (bundled Plugin Update Checker v5); base URL read from the PRIVATE_UPDATE_SERVER constant, inert when it is not defined.

= 1.11.0 =
* Site Translate workspace: coverage-aware picker for pages, posts, and products.
* Chunked translation Jobs (50 per job) with shared batch_id, Run batch now, and partial-create retry via client_token.
* Localized URL batch generate/publish with title_stale and collision outcomes via existing routing authorities.
* Selection-scoped Strategy F gate for Gutenberg bodies.

= 1.10.0 =
* DeepSeek AI provider alongside OpenAI.
* Per-provider settings: API key, model, temperature, max tokens (OpenAI and DeepSeek keep separate configs).
* Legacy shared AI key/model migrate into the OpenAI settings slot. Schema target remains 8.

= 1.9.0 =
* Identity rebrand to Universal Multilingual (`universal-multilingual`). Runtime APIs remain AIML/`aiml_*`.

= 1.8.0 =
* M5-A.1: public Integration descriptor creation with `Contract::FORMAT_PLAIN`, `Contract::FORMAT_HTML`, and `TranslationUnitDescriptor::from_source(...)`.
* Existing descriptor constructor remains supported; third-party public examples must use the factory rather than internal `Store` symbols.

= 1.7.0 =
* M5-A: integration-owned private CPT chrome admission (`DeclaresChromeOwnedSurfaces`), host-independent Extension `p:` resolve, `aiml_visitor_language()`, Extension-strict chrome eligibility (stale→null; source must be publish).
* Invalid chrome declarations disable only that surface; existing Integration API implementors and FrontendBridge I7 unchanged.
* Schema target remains 8 (no migration). Tag/ZIP/deploy separately authorized.

= 1.6.0 =
* Operator completion: Localized URL Workspace/term slug surfaces and Settings admission/frontier honesty (P0). No new Localized URL routing capability.
* Operator completion: Jobs multi-post create without segment keys, progress/stale/conflict literacy, capability-gated recovery (P2). No new Job type or silent overwrite.
* Documentation: Rank Math Model A / G4 characterization (P1) — no Supported-contract defect; DEV xhtml omit under blog_public=0 is expected, not a universal absence claim.
* Schema target remains 8 (no migration). Public Extension/Integration APIs unchanged.

= 1.5.1 =
* Patch: restore Localized URL Supported contracts from Gate B — bounded term_link re-entry (localized GET completion), EffectiveUrl agreement for hreflang/og:url/switcher, Woo render health from the same correction family.
* Schema target remains 8 (no migration). Existing routes/history remain compatible. No new URL capability, SEO architecture, or Program B.
* Does not claim: translated rewrite bases, Woo endpoint names, variation routes, pretty layered-nav, Extension API 1.1, or taxonomy operator-completeness UI.

= 1.5.0 =
* Multilingual SEO & Localized URLs (MSEO.0–MSEO.5): optional localized URL slugs; PathCanonicalizer; EffectiveUrlService; candidate vs active routes; history; hierarchy/terms; Woo %product_cat% permalink hardening; SEO Model A; program PluginGuard/acceptance/release/dogfood closeout.
* Schema target remains 8 (no migration in this release). Localized URLs default off. Preview remains source-slug. Translated rewrite bases and Woo endpoint names remain Deferred.
* Does not claim: provider-generated slugs, rewrite-rule ownership, competing sitemaps, distinct variation routes, or fuzzy URL matching.

= 1.4.0 =
* Translation Surface Coverage (TSC.0–TSC.6) Complete: internal surface capabilities, taxonomy terms, registered meta, WooCommerce extended surfaces, Gutenberg expansion, Elementor expansion, and public Extension API v1.
* Extension API v1: aiml_register_extensions, public meta/block registration, VisitorTranslationResolver, aiml_mark_source_dirty(), WP-CLI extension diagnostics.
* Schema target remains 7 (no migration). Safe publication defaults unchanged (gate off; mode manual). Gutenberg/Elementor flags remain off by default.

= 1.3.0 =
* Operator Translation Lifecycle (OTL.0–OTL.6): Operations list/attention, unified detail edit/review, publication + stale/retranslate, Jobs integration, bounded bulk operations, lifecycle polish.
* Schema target remains 7 (no migration). Safe publication defaults unchanged (gate off; mode manual).
* TSC not included.

= 1.2.0 =
* Translation Intelligence & Quality (TQ.0–TI.7): quality baseline, persist structural safety, bounded context, TM intelligence, deterministic QA, explainable assessment R1.0, Jobs scale/safety, controlled publication P1.0.
* Schema target 7: publication axis with safe upgrade backfill; gate default off; auto-publication mode default manual.
* New translations default unpublished; existing overlayable translations backfilled published so upgrades do not hide content.

= 1.1.0 =
* First intentional public release package: WooCommerce visitor surfaces, WordPress visitor chrome, Fluent Forms contact bridge, and A.SEO (A.SEOa–A.SEOf).
* Canonical/hreflang, Rank Math title/meta overlays, Open Graph/Twitter text overlays, Rank Math sitemap xhtml honesty, SEO diagnostics CLI/admin.
* CI/release baseline recovery with audited production ZIP packaging.
* Schema target remains 6 (no migration required from historical 1.0.0 packages).

= 1.0.0 =
* Scoped platform release: Store, TM, Glossary, Review, Jobs, Rollout/GA, REST/CLI.
* OpenAI gpt-5 / o-series temperature compatibility for Chat Completions.
