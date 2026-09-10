=== Universal Multilingual ===
Contributors: magpern
Tags: multilingual, translation, woocommerce, gutenberg, ai
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.16.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multilingual layer for WordPress: canonical content with segment translations applied as render-time overlays.

== Description ==

Universal Multilingual stores one canonical object per content item and applies language overlays at render time. Version 1.16.0 makes the localized URL feel like part of translation (it is proposed automatically, the technical route controls move under Advanced), adds a "Submit selected for review" bulk action, and translates WooCommerce product short descriptions on the storefront. Version 1.15.0 surfaces AI translation as one obvious action: a "Translate with AI" control on the Translator Workspace and a "Translate selected with AI" bulk action, both driven by the existing background Jobs pipeline, with manual, reviewed and in-review translations never overwritten. Version 1.13.0 rebuilds "Add a language" around selection: the URL code, locale, name, native name and direction are derived server-side from a bundled offline locale registry. Version 1.12.0 adds logged-in Regional Preferences (preferred language) and an optional floating language selector (default off). Version 1.11.0 adds Site Translate (coverage-aware picker, chunked Jobs, Run batch now, Localized URL batch). Version 1.10.0 adds the DeepSeek AI provider and per-provider generation settings. Version 1.9.0 rebranded to Universal Multilingual.

== Installation ==

1. Upload the `universal-multilingual` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Confirm database schema version 9 (option `aiml_db_version`).
4. Configure languages, providers, and rollout in the Universal Multilingual admin screens.
5. Publication gate and auto-publication mode default off/manual — enable only after reviewing release notes.
6. Localized URLs default OFF; enable only after reviewing MSEO release notes and verifying routes.

== Third-party data ==

The bundled language list (`src/Language/data/locales.php`) is generated offline
from the locale data in GlotPress (https://github.com/GlotPress/GlotPress),
GPL-2.0-or-later. Only the data is used; the plugin has no runtime dependency on
GlotPress. See the file's header for the exact source revision and snapshot date.

== Changelog ==

= 1.16.0 =
* Localized URL as part of translation: the localized slug is proposed automatically after "Translate with AI" and on Workspace load (following the translated title), shown as a plain "URL for <language>" with an Edit action and a clear state ("Ready when Swedish is published." / "Ready to publish." / "Published."). A manually edited slug is never overwritten. The technical lifecycle controls (Regenerate / Clear / Publish route / Refresh, origin and route detail) move under "Advanced URL controls". New POST aiml/v1/workspace/<id>/slug/ensure endpoint.
* The localized URL slug (post_name) is no longer shown as an editable translation segment in the Workspace — every AI and bulk path already excludes it and it has its own dedicated lifecycle. This also removes the misleading "empty translation" warnings on it.
* New bulk "Submit selected for review" action in the Translator Workspace (the batch endpoint already supported it).
* "Accept TM exact" is now labelled "Apply exact memory matches" with an explanatory tooltip.
* WooCommerce product short descriptions are now translated on the storefront. WooCommerce renders them through its own woocommerce_short_description filter (never get_the_excerpt), so the overlay missed them; it is now applied there at priority 1, before WooCommerce's own formatting filters.
* Preview note: the Workspace explains that Preview opens the translated page for signed-in editors only when the target language is not published yet.
* No database migration. Schema stays at version 10; settings shape stays at 3.

= 1.15.2 =
* Fix: the Translator Workspace no longer shows "Quality checks" warnings (empty_translation, number, HTML-tag) on segments that have not been translated yet. An absent translation has no translation quality to report and the Status column already shows "Missing"; the noise was especially visible on fields the AI flow does not translate, such as the URL slug (post_name). Quality checks still run in full on the save and review paths.
* No database migration. Schema stays at version 10; settings shape stays at 3.

= 1.15.1 =
* Fix: a background translation job created by "Translate with AI" (or "Translate selected with AI") now runs to completion. The worker processes segments in bounded wakes of 10; when more segments remained it stopped without scheduling the next wake, so a page with more than 10 translatable segments only translated the first 10 and then stalled. The worker now re-arms its own next wake while work remains.
* No database migration. Schema stays at version 10; settings shape stays at 3.

= 1.15.0 =
* "Translate with AI" on the Translator Workspace editor: one primary action translates a page's eligible segments, with a mode selector (Translate missing / Retranslate stale / Retranslate AI translations). It creates a background job, starts it immediately, polls status, refreshes the editor and reports N translated / M kept / F failed. Disabled (never hidden) with a "Configure AI settings" link when AI is not configured.
* The legacy Translate screen is no longer an AI dead end: it deep-links into the Workspace AI flow with the post and target language preselected, plus an "Open in Translator Workspace" link.
* Site Translate gains the same mode selector and a single "Translate selected with AI" action that creates and starts the batch; it pre-selects content passed from a new Pages/Posts list-table "Translate with AI" bulk action.
* New retranslate_machine job type: replaces every eligible machine translation for an object regardless of stale state.
* Manual, reviewed and in-review translations are never overwritten by a page or bulk AI action, in any mode. One shared policy (TranslatableSegmentEligibility) now also governs the synchronous workspace "Translate selected" path, closing a gap where it could overwrite a human's translation; protected segments are reported as skipped.
* User-facing AI actions carry an automatic, invisible idempotency token: a double-click or a retry after a timeout collapses to one job, a deliberate re-run after completion is new work.
* Shared internal navigation bar at the top of every Universal Multilingual admin screen (Languages, Settings, Limited Rollout, SEO Diagnostics, Translate, Workspace, Glossary, Translation Promotion). Each tab maps to the owning screen's existing capability, so a section the current user cannot open never appears. No JavaScript.
* Shared aiml-ui admin design system extracted to assets/admin-ui/aiml-ui.css and applied to Languages, Settings, the Translator Workspace, Site Translate and Jobs. Job status reads in plain language; execution status and review status render as separate badges.
* New Settings and Overview action links plus a Documentation meta link on the Plugins screen.
* Elementor translation safety: _elementor_data, widget IDs and structure are never written; html/shortcode controls stay excluded (regression test added).
* No database migration. Schema stays at version 10; settings shape stays at 3.

= 1.14.0 =
* DEV -> PROD translation promotion (ADR-0030): export reviewed translations as a versioned .json package, import on another environment with a strictly read-only dry-run, review new/updates/conflicts/stale/missing/route classifications, then explicitly apply. Idempotent and safe to re-run.
* Cross-environment identity: plugin-owned UUID + deterministic environment-independent natural key (new aiml_object_identity table). The UUID is authoritative once both sites have seen the object, so renames on either side do not break matching.
* Three-way merge baseline (new aiml_promotion_state table) tells an ordinary update from a translation edited independently on the target; conflicts and stale translations are never applied in Safe mode.
* Apply re-uploads the package and re-plans; a stateless signed review token binds the apply to the reviewed dry-run, and any drift since review refuses the whole apply.
* Conservative locale mapping (exact locale, safe code fallback only; pt_BR never maps to pt_PT); no language is ever auto-created. Routes/history stay per-environment; only the translated slug candidate is imported.
* REST (aiml/v1/promotion/*), an admin screen under Universal Multilingual, and wp aiml promotion export|import|history|backfill-identity share one service layer. New aiml_promotion_audit hook and aiml_promotion_log table.
* Schema 9 -> 10 (aiml_object_identity, aiml_promotion_state, aiml_promotion_log). Settings shape 2 -> 3. New capabilities aiml_promote_translations and aiml_trust_promoted_review_state (administrators only by default).

= 1.13.0 =
* Add a language is now selection, not data entry: pick a language (and a region where relevant); the URL code, locale, name, native name and direction are derived server-side from a bundled locale registry (offline; no language packs). A gated "Advanced: custom language" path covers unusual locales.
* URL-code grammar extended to language-region-variant so explicit variants such as de_DE_formal route as /de-de-formal/.
* Locale/URL code are immutable once a language exists.
* Schema 8 -> 9: aiml_languages.code widened to VARCHAR(20) and a UNIQUE KEY on locale. If two languages already share a locale the upgrade pauses with a neutral admin notice until each has a distinct locale.

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
