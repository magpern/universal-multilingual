# Changelog

All notable changes to Universal Multilingual are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.15.2] - 2026-09-10

### Fixed

- The Translator Workspace no longer renders **Quality checks** findings
  (`empty_translation`, `qd9_number_corruption`, `qd6_html_tag_loss`, …) for a
  segment whose target is still empty. There is no translation to assess, the
  Status column already shows *Missing*, and the detectors otherwise turned
  every untranslated segment — a fresh page, or fields the AI flow does not
  translate such as the URL slug (`post_name`) — into a wall of warnings.
  `WorkspaceService::attach_meta()` and `request_suggestions()` return an empty
  QA result for an empty target; the save and review paths still run the full
  detector suite. No schema or settings change.

## [1.15.1] - 2026-09-10

### Fixed

- A background translation job created by **Translate with AI** /
  **Translate selected with AI** now runs to completion. The worker
  processes segments in bounded wakes of `MAX_ITEMS_PER_WAKE` (10); when
  claimable segments remained it returned without scheduling the next
  wake, and the hourly sweep only recovers crashed jobs — so a page with
  more than 10 translatable segments translated the first 10 and then
  stalled. `BackgroundTranslationWorker` now re-enqueues its own next wake
  while claimable items remain (guarded so a zero-progress wake cannot
  hot-loop). No schema or settings change.

## [1.15.0] - 2026-09-10

### Added

#### User-initiated AI translation — page + bulk (ADR-0031)

- **"Translate with AI"** on the Translator Workspace editor: one primary
  action translates a page's eligible segments. A mode selector offers
  **Translate missing** (default), **Retranslate stale** and **Retranslate AI
  translations**. The action creates a background job, starts it immediately,
  polls status, refreshes the editor and reports
  `N translated · M kept · F failed` — with any review need shown separately.
  Disabled (never hidden) with a *Configure AI settings* link when AI is not
  configured.
- The legacy **Translate** screen is no longer an AI dead end: it deep-links
  into the Workspace AI flow with the post and target language preselected,
  plus an *Open in Translator Workspace* link.
- **Site Translate**: a mode selector and a single **Translate selected with
  AI** action that creates and starts the batch; pre-selects content passed
  from the new Pages/Posts list-table **Translate with AI** bulk action.
- New `retranslate_machine` job type — replaces every eligible machine
  translation for an object regardless of stale state.
- New **Settings | Overview** links and a **Documentation** meta link on the
  Plugins screen.
- A shared **internal navigation bar** (`.aiml-ui-subnav`) at the top of every
  Universal Multilingual admin screen — Languages, Settings, Limited Rollout,
  SEO Diagnostics, Translate, Workspace, Glossary, Translation Promotion — so an
  operator can move between areas without the WordPress sidebar. One renderer
  (`src/Admin/AdminNavigation`); each tab maps to the owning screen's existing
  slug constant and existing capability, so a section the current user cannot
  open never appears. No JavaScript; the active tab is marked by a filled accent
  pill and `aria-current="page"`.

### Changed

- **Manual, reviewed and in-review translations are never overwritten by a
  page or bulk AI action, in any mode.** A single shared policy
  (`TranslatableSegmentEligibility`) now governs the synchronous workspace
  "Translate selected" path as well as the background worker and job
  materialisation — closing a gap where the synchronous path could overwrite
  a human's translation. Protected segments are reported as *skipped*.
- User-facing AI actions carry an automatic, invisible idempotency token; a
  double-click or a retry after a timeout collapses to one job, while a
  deliberate re-run after completion is new work.
- The `aiml-ui` admin design system is extracted to
  `assets/admin-ui/aiml-ui.css` and shared by Languages, Settings, the
  Translator Workspace, Site Translate and Jobs. Job status reads in plain
  language (Queued / Translating / Completed / Completed with skips / Failed);
  execution status and review status render as separate badges.
- The generic `POST aiml/v1/jobs` and Site Translate create endpoints accept
  an explicit `autostart` flag; without it they keep their deliberate
  create-then-run behaviour.

## [1.14.0] - 2026-09-09

### Added

#### DEV → PROD translation promotion (ADR-0030)

- A first-class **Translation Promotion** module: export reviewed translations
  from one environment as a versioned `.json` package, import it on another with
  a strictly read-only dry-run, review the classification, then explicitly
  apply. Idempotent and safe to re-run.
- **Cross-environment identity**: new `aiml_object_identity` table holding a
  plugin-owned UUID plus a deterministic, environment-independent natural key
  (canonical slug + type + ancestor chain, stored as TEXT, indexed by sha256).
  Export mints the UUID before serialization; the far side bootstraps by
  natural key on the first promotion and is UUID-joined and rename-proof
  afterwards. `identity_conflict` / `ambiguous_source` fail closed.
- **Three-way merge baseline**: new `aiml_promotion_state` table records, per
  promoted segment, the last-promoted translation and source hash so an import
  distinguishes an ordinary update from a translation edited independently on
  the target.
- **Package format v1**: manifest / deterministic payload split, dual checksums
  (`payload_checksum` for content identity, `package_checksum` over
  `{manifest, payload}` for whole-artifact integrity). `format_version == 1`
  only. Size ceiling 25 MB, 3,000 objects.
- **Dry-run** classifies every segment (new / update / unchanged /
  conflict_target_modified / conflict_both_changed / stale_source /
  missing_source / missing_language / unsupported_type / unknown_field /
  route_conflict / identity_conflict / ambiguous_source / validation_failure)
  and returns a **stateless signed review token**; it performs no writes and
  emits no action hooks.
- **Apply** re-uploads the package, verifies the token and re-plans; any
  material drift since the reviewed dry-run refuses the whole apply. A narrow
  write-time change makes just that row `changed_since_dry_run`. Modes:
  `safe_only` (new + update), `selective` (per-row allowlist + explicit
  stale/conflict/route acknowledgement), `force`. Writes go through
  `Store::save_translation()` / `save_slug_candidate()` only. Route rows stay a
  per-environment projection — only the slug candidate is imported.
- **Conservative language mapping**: exact locale, then a bare-code fallback
  only when a locale is unspecified and unique; two explicit locales are never
  cross-mapped; a language is never auto-created.
- **Capabilities**: `aiml_promote_translations` and
  `aiml_trust_promoted_review_state` (both `administrator` only by default,
  provisioned through the versioned `aiml_caps_version` option on activation and
  `admin_init`), widenable per-site via the `aiml_promotion_can_promote` filter.
- REST API under `aiml/v1/promotion/{export,import/validate,import/apply,history}`,
  admin screen under Universal Multilingual, and `wp aiml promotion
  {export,import,history,backfill-identity}` CLI — all sharing one domain
  service layer.
- New `do_action( 'aiml_promotion_audit' )` channel (`export` / `apply_*`
  stages only). New `aiml_promotion_log` history table.

### Changed

- `Migrator::TARGET` 9 → 10 (`step_10_promotion_foundation` creates the three
  promotion tables; no `aiml_translations` column).
- `Settings::SCHEMA_VERSION` 2 → 3 adds `promotion_max_package_bytes` and
  `promotion_log_retention`.

## [1.13.0] - 2026-09-09

### Changed

#### Add a language — selection instead of data entry

- The Languages screen is rebuilt on the Universal Multicurrency admin design
  system (re-prefixed and scoped; no cross-plugin CSS load, no runtime
  dependency).
- Adding a language is now a choice: pick a language in a searchable
  `ComboboxControl`, pick a regional variant only when the language has more
  than one locale, set status and sort order. A read-only summary previews the
  URL prefix, locale, native name and direction.
- The server derives the URL code, English name, native name and text direction
  from a plugin-owned locale registry (206 WordPress locales in 162 groups,
  generated offline from GlotPress locale data — no network, no language packs,
  no `intl` dependency). Values the browser submits for those fields are
  discarded.
- A gated "Advanced: custom language" disclosure keeps the raw-field path for
  locales outside the registry, behind the new `aiml_allow_custom_language`
  filter (default on).
- URL-code grammar extended to `language-region-variant`
  (`^[a-z]{2,3}(-[a-z]{2}(-[a-z0-9]+)?)?$`, ADR-0029) so explicit
  formal/orthography variants such as `de_DE_formal` route as `/de-de-formal/`.
  Derivation is deterministic and order-independent.
- On Edit, `locale` and `code` are immutable for every row; a curated row
  re-derives name/direction from the registry and takes a native-name display
  override, a custom row also allows name/direction.

### Added

- `aiml_languages` now has a `UNIQUE KEY locale`; two languages can never share
  a WordPress locale.

### Fixed

- A mistyped locale can no longer be saved and silently fail to load
  translations — the locale is chosen from a list.

### Migration

- Schema **8 → 9** (`Migrator::TARGET`): widens `aiml_languages.code` to
  `VARCHAR(20)` and adds `UNIQUE KEY locale`. If an install already has two
  languages sharing a locale the upgrade **pauses** (a neutral admin notice
  asks for each language to be given a distinct locale) and completes
  automatically once resolved. Existing language rows are otherwise untouched.

## [1.12.0] - 2026-09-08

### Added

#### Regional preferences

- Logged-in preferred language stored as `aiml_preferred_language` (language code).
- WordPress Profile and WooCommerce My Account → Account details fields.
- Public API: `aiml_get_preferred_language`, `aiml_get_preferred_language_state`, `aiml_set_preferred_language`.
- Optional composition with Universal Multicurrency via `um_regional_preferences_*` hooks (no hard UMC dependency).
- Site-default fallback uses WordPress `WPLANG` (empty → `en_US`). Preferred language does not change URL render language.

#### Floating language selector

- Optional visitor-facing edge selector, **default off**.
- Left/right edge, top/center/bottom, code/name/globe collapsed modes, edge_pill/minimal/tab presets.
- Desktop/mobile visibility via CSS (same HTML; no device sniffing).
- Progressive enhancement: language links remain usable without JavaScript.
- Keyboard disclosure (not a listbox); no flags.
- Authenticated best-effort preference persist (`keepalive` fetch) that never blocks navigation.
- Documented edge-control convention for future independent UMC currency UI.

### Compatibility / infrastructure

- URL/host remains the only anonymous language authority. No language cookie. Anonymous cache contract unchanged.
- `Migrator::TARGET` remains **8** (no DB migration).
- `Settings::SCHEMA_VERSION` **1 → 2** (option-shape marker only).
- No hard Universal Multicurrency dependency.

### Documentation

- User manual: Regional Preferences and floating selector.
- Release notes: `docs/releases/v1.12.0.md`.

## [1.11.1] - 2026-09-02

### Added

- Self-updates from a private update server via the bundled Plugin Update Checker v5 Composer dependency; active only when `PRIVATE_UPDATE_SERVER` is defined in `wp-config.php`.

## [1.11.0] — 2026-08-31

### Added

- **Site Translate:** Workspace operator surface for pages, posts, and products with coverage read model (eligible/missing/translated/unpublished/published/stale/blocked), filtering, and multi-select.
- **Chunked Jobs:** Site Translate creates translation Jobs in chunks of `JobBounds::MAX_POSTS_PER_BULK` (50) with shared `batch_id`; Jobs tab focuses the batch group.
- **Run batch now:** thin orchestration enqueue for all waiting Jobs in a batch (async; not synchronous HTTP execution).
- **Partial create retry:** preserves successful chunks; retries failed creation only via existing `client_token` / idempotency.
- **Localized URL batch:** generate/publish routes through `SlugCandidateService` / `RoutePublicationService`; `title_stale`, collision, and eligibility outcomes surfaced per object.
- **Strategy F selection gate:** hard-blocks Gutenberg (`BODY_BLOCKS`) selections when Strategy F is incomplete; classic-only selections allowed.

### Compatibility / infrastructure

- Schema TARGET remains **8** (no migration).
- Publication gate / manual publish axis unchanged; review and publication remain separate.
- Rank Math Model A and anonymous URL/host language resolution unchanged.
- Includes all **1.10.0** changes (DeepSeek provider, per-provider AI settings) — `v1.10.0` was prepared on main but never tagged.

### Documentation

- User manual updated for Site Translate operator workflow.
- Release notes: `docs/releases/v1.11.0.md`.

### Notes

- **DEV / pre-production release** — production deployment separately authorized.
- Full operator-led Swedish workflow acceptance remains pending before release-readiness.

## [1.10.0] — 2026-08-31

### Added

- **DeepSeek AI provider:** second Chat Completions provider (`deepseek`) registered beside OpenAI for workspace auto-translate and AI suggest.
- **Per-provider generation settings:** each provider stores its own encrypted API key, model, temperature (0–2), and max tokens (0 = omit). Settings UI exposes separate OpenAI and DeepSeek fieldsets.
- DeepSeek translation requests send `thinking: { type: disabled }` so temperature/max_tokens take effect.

### Changed

- OpenAI temperature is no longer hardcoded to `0.2`; it comes from the OpenAI settings row (default `0.2`). Optional `max_tokens` is sent when greater than zero.
- Legacy shared `ai_model` / `ai_api_key_encrypted` migrate into `ai_providers.openai` on sanitize for upgrades from ≤1.9.0.

### Compatibility / infrastructure

- Schema TARGET remains **8** (no migration).
- `AIProviderInterface` / workspace / jobs / REST consumers unchanged (provider-agnostic).
- Public Extension / Integration APIs unchanged.

### Documentation

- Release notes and scope audit under `docs/releases/` for v1.10.0.

### Notes

- Formal production package / tag / deploy remain separately authorized.

## [1.9.0] — 2026-08-31

### Changed

- **Identity rebrand:** display name, slug, text domain, Composer package, and packaging are now **Universal Multilingual** (`universal-multilingual` / `magpern/universal-multilingual`). Runtime APIs remain `AIMultilingual\`, `AIML_*`, and `aiml_*` (admin menu query slugs unchanged).

## [1.8.0] — 2026-08-24

### Added

- **M5-A.1 Public Integration descriptor factory:** `AIMultilingual\Integration\Contract::FORMAT_PLAIN`, `Contract::FORMAT_HTML`, and immutable `TranslationUnitDescriptor::from_source(...)` for public descriptor creation without third-party `Store` imports.

### Compatibility / infrastructure

- Existing `TranslationUnitDescriptor` constructor remains unchanged.
- Canonical source-hash implementation remains internal; no public Store API or arbitrary hash callback/filter added.
- Translation storage semantics, M5-A chrome admission, host-independent resolver, stale eligibility, and visitor-language context remain unchanged.

### Documentation

- Integration API v1 docs now document public descriptor creation through `from_source(...)` and public format constants.
- M5-A.1 plan and closure added under `docs/plans/`.

### Notes

- DEV installation of 1.8.0 can unblock downstream implementation once merged and feature-probe verified.
- Formal production package / tag / deploy remain separately authorized.

## [1.7.0] — 2026-08-23

### Added

- **M5-A Private CPT chrome integration:** optional companion interface `DeclaresChromeOwnedSurfaces` + `ChromeOwnedSurfaceDeclaration` for integration-owned administrative/private CPT fields.
- Host-independent public `p:` resolve via Extension `VisitorTranslationResolver` for activated chrome surfaces (source-id explicit; Extension-strict stale → `null`; source `post_status=publish` required).
- Public `aiml_visitor_language(): ?VisitorLanguageContext` (URL/host language code + `is_default`).
- Post-`init` declaration validation: invalid chrome-surface declarations disable only that surface with an authorized diagnostic and never fail the integration registry.
- Workspace/Jobs discovery for activated chrome CPT sources with `integration_units_only` extraction (declared `p:` fields only).
- `aiml_mark_source_dirty` admits activated chrome CPT sources under the same ownership/admission checks.

### Compatibility / infrastructure

- Schema TARGET remains **8** (no migration).
- Existing `PluginIntegrationInterface` implementors unchanged without the companion interface.
- Host-bound `IntegrationFrontendBridge` I7 stale behaviour unchanged.
- No public `aiml_admitted_post_types` filter; AIML does not flip CPT REST/archive/permalink visibility.
- No cookie / geo / `Accept-Language` language decision path.

### Documentation

- ADR-0025 (private CPT chrome admission); Integration API v1 / Extension API v1 / HOOKS updates; M5-A plan frozen; closure under `docs/plans/`.

### Notes

- Production package / tag / deploy for 1.7.0 remain separately authorized after M5-A closure.
- USA M5-B remains blocked until this release is authorized and deployed to the USA target environment.

## [1.6.0] — 2026-08-16

### Added

- **Localized URL operator surfaces (P0):** Workspace localized-slug panel for posts/pages/products; term/archive localized-slug admin UI; Settings Localized URLs admission and frontier honesty; thin term slug REST under `aiml/v1/workspace/terms/{id}/slug*` delegating to existing route authorities.
- **Jobs / stale operator literacy (P2):** Multi-post Workspace Job create without manual segment keys (`bulk_translate` resolves missing segments); Run CTA and light monitoring; skipped/stale progress counts; human item labels for conflict/`stale_source`; state-accurate stale copy; Jobs→Operations source deep-link.

### Changed

- Operator-facing Jobs status labels (e.g. Waiting, Completed with skips) without changing Job engine semantics.
- Release package includes `assets/term-slug-admin/` runtime assets required by P0 term UI.

### Documentation

- **P1 G4 / Rank Math Model A characterization:** no Supported-contract defect. Sitemap primary `<loc>` remains Rank Math default/source. AIML xhtml enrichment remains subject to public/discoverability gates; DEV omit under `blog_public=0` is **EXPECTED OMIT**, not a claim that xhtml is generally absent.
- Jobs and Localized URL operator runbooks updated for P0/P2 terminology.

### Compatibility / infrastructure

- Schema TARGET remains **8** (no migration).
- Public Extension API and Integration API unchanged.
- Existing Localized URL settings/routes/history remain authoritative; no new URL routing capability.
- No new Job type; concurrency/stale/conflict fail-safes and no-silent-overwrite policy unchanged; Run remains administrator-gated.

### Notes

- Production package is `ai-multilingual-1.6.0.zip` from `bin/build-zip.sh` / GitHub Actions on `v*` tags (tag/release separately authorized).
- See [docs/releases/v1.6.0.md](docs/releases/v1.6.0.md) and [docs/releases/V1_6_0_RELEASE_SCOPE.md](docs/releases/V1_6_0_RELEASE_SCOPE.md).

## [1.5.1] — 2026-08-15

### Fixed

- Localized CURRENT_LOCALIZED render recursion/timeout from unbounded `term_link` re-entry under Localized URLs ON.
- EffectiveUrl agreement for affected Model A consumers (hreflang, Open Graph URL, language switcher) on CURRENT_LOCALIZED requests.
- Woo localized product URL/render health regression in the same correction family (Gate B truncated HTML disposition A).

### Compatibility / infrastructure

- Schema TARGET remains **8** (no migration).
- Existing active routes and history remain valid; settings defaults unchanged.
- Localized URLs remain controlled by existing settings/admission; no new URL capability or SEO architecture.
- Sitemap Model A unchanged (default-language primary locs; localized XHTML alternates).

### Notes

- Production package is `ai-multilingual-1.5.1.zip` from `bin/build-zip.sh` / GitHub Actions on `v*` tags (tag/release separately authorized).
- See [docs/releases/v1.5.1.md](docs/releases/v1.5.1.md) and [docs/releases/V1_5_1_RELEASE_SCOPE.md](docs/releases/V1_5_1_RELEASE_SCOPE.md).

## [1.5.0] — 2026-08-15

### Multilingual SEO & Localized URLs (MSEO.0–MSEO.5)

- **MSEO.0** Inert foundation: TARGET 8 tables (`aiml_slug_routes`, `aiml_route_history`, `aiml_slug_reindex_frontier`), PathCanonicalizer, EffectiveUrlService scaffold, ADR-0023.
- **MSEO.1** Candidate vs active route lifecycle, `slug_origin`, ObjectLanguagePublicEligibility, Workspace slug field, RoutePublicationService.
- **MSEO.2** First activatable stack: recognition, history, outbound EffectiveUrl, SEO graph (canonical/hreflang/sitemap Model A/switcher), activation state machine; flat post, top-level page, plain product.
- **MSEO.3** Hierarchical pages/terms, HierarchyPathBuilder ancestor-leaf localization, frontier reindex ≤100/tick, capability admission epoch.
- **MSEO.4** WooCommerce `%product_cat%` permalink hardening: Woo source authority, fingerprint gate, product_dep / woo_product_config frontiers.
- **MSEO.5** Program hardening, acceptance harness, v1.5.0 release, DEV DOGFOOD (published asset).

### Compatibility / infrastructure

- Schema TARGET remains **8** (no migration in this release).
- Localized URLs default **OFF**; PathRecognition remains always-on with 302 fallbacks when generation is off.
- Preview remains source-slug only.
- Translated rewrite bases, Woo endpoint names, variation routes, pretty layered-nav remain Deferred/Unsupported (Post-MSEO backlog).

### Notes

- Production package is `ai-multilingual-1.5.0.zip` from `bin/build-zip.sh` / GitHub Actions on `v*` tags.
- See [docs/releases/v1.5.0.md](docs/releases/v1.5.0.md) and [docs/releases/V1_5_0_RELEASE_SCOPE.md](docs/releases/V1_5_0_RELEASE_SCOPE.md).

## [1.4.0] — 2026-08-14

### Translation Surface Coverage (TSC.0–TSC.6)

- **TSC.0** Internal surface capability foundation: `SurfaceRegistry` / `SurfaceCapability`, request-local invalidation coordination, admitted surface ownership.
- **TSC.1** First-class taxonomy terms: native term identity, lazy hosted adoption, term edit/review/publication, visitor term overlays, Rank Math term coexistence.
- **TSC.2** Registered meta translation surfaces: exact-key catalog, provider admission, Rank Math ownership, post/term registered meta lifecycle.
- **TSC.3** WooCommerce extended translation surfaces: global attribute labels, single-writer authority, shop-host rehome, variation safety, Woo email stale improvements.
- **TSC.4** Gutenberg coverage expansion: broader supported block field rendering, structural-attribute safety, block-field authority hardening, stale granularity.
- **TSC.5** Elementor coverage expansion: authoritative `after_save` invalidation, shared structural safety, editor/preview context isolation, eight supported widget families hardened.
- **TSC.6** Public Extension / SEO stabilization: Extension API v1, public meta/block registration, `VisitorTranslationResolver`, `aiml_mark_source_dirty()`, WP-CLI extension diagnostics, Rank Math regression, ADR-0022.

### Extension API v1

- `aiml_register_extensions` hook with root extension ownership and registry sealing.
- Public exact-key meta registration (`ExtensionMetaDefinition`; `provider_allowed` default false).
- Public custom block adapter contract (`ExtensionBlockAdapter`).
- Read-only visitor resolver with complete source identity and language code.
- Public invalidation helper and bounded WP-CLI diagnostics.

### Compatibility / infrastructure

- Schema TARGET remains **7** (no migration).
- Integration API v1 unchanged; TIQ and OTL programs remain complete.
- Safe publication defaults unchanged: gate OFF, mode `manual`.
- Gutenberg/Elementor feature flags remain OFF by default.

### Notes

- Production package is `ai-multilingual-1.4.0.zip` from `bin/build-zip.sh` / GitHub Actions on `v*` tags.
- See [docs/releases/v1.4.0.md](docs/releases/v1.4.0.md) and [docs/releases/V1_4_0_RELEASE_SCOPE.md](docs/releases/V1_4_0_RELEASE_SCOPE.md).

## [1.3.0] — 2026-08-12

### Operator Translation Lifecycle

- OTL.0–OTL.6 Complete: Operations list/attention, unified detail edit/review, publication + stale/retranslate workflow, Jobs integration, bounded bulk operations, and final lifecycle polish.
- Shared ConfirmDialog and centralized async dirty-leave admission; session-only Operations context restore; Review→Operations and bulk→Jobs navigation.
- Bounded bulk publish / unpublish / enqueue_retranslate (max 50) via OperationsBulkCoordinator → TI.7 / TI.6.
- Authoritative local Playwright suite `acceptance/otl-browser/`; historical otl1–otl5 archives retained.

### Compatibility / infrastructure

- Schema TARGET remains **7** (no migration).
- Integration API v1 unchanged; TIQ authorities (Store, review, QA, assessment, Jobs, PublicationService) unchanged.
- Safe publication defaults unchanged: gate OFF, mode `manual`.

### Notes

- Production package is `ai-multilingual-1.3.0.zip` from `bin/build-zip.sh` / GitHub Actions on `v*` tags.
- TSC is not part of this release.
- See [docs/releases/v1.3.0.md](docs/releases/v1.3.0.md) and [docs/releases/V1_3_0_RELEASE_SCOPE.md](docs/releases/V1_3_0_RELEASE_SCOPE.md).

## [1.2.0] — 2026-08-11

### Translation quality and safety

- TQ.0 Translation Quality Baseline: C1.0 corpus, H1.0 scorer, B1.0 reviews, official immutable `baseline-v1.1.0` evidence pack, quality CLI/CI (network-free).
- TI.1 persist-path structural safety on sync and Background Jobs.
- TI.4 shared deterministic QA detectors and policy adapters; additive H1.1 / C1.3 evidence.

### Translation intelligence

- TI.2 bounded translation context on the generation path.
- TI.3 exact approved Translation Memory direct reuse and relevance-gated assisted examples.
- TI.5 explainable read-only risk/readiness assessment (**R1.0**) — no aggregate score, no LLM confidence, no publication decision.

### Background operations

- TI.6 truthful provider usage/budgets, Retry-After handling, bounded concurrency, and recovery/operator evidence improvements.
- Exactly-once provider spend is not claimed (Outcome B may repeat a provider call after crash-after-Store).

### Controlled publication

- TI.7 segment publication axis (`publish_status` / `published_at` / `published_by`); Migrator **TARGET 7**.
- Frontend publication gate (default **off**); modes `manual` (default), `approved_only`, `controlled_auto`.
- Single PublicationPolicy **P1.0** and PublicationService; Workspace / REST / CLI controls.
- Sync and Jobs publish via the same service; publication failure is separate from translation failure.
- Upgrade backfills previously overlayable rows to `published`; new rows default `unpublished`; no silent auto-publication on upgrade.

### Compatibility / infrastructure

- Compatible CI/Actions maintenance landed after v1.1.0 (including Node 24 runtime upgrades).
- Integration API v1 unchanged; A.SEO / Woo ownership unchanged.

### Notes

- Production package is `ai-multilingual-1.2.0.zip` from `bin/build-zip.sh` / GitHub Actions on `v*` tags.
- Official quality evidence pack remains labeled **baseline-v1.1.0** (historical behavioral baseline).
- See [docs/releases/v1.2.0.md](docs/releases/v1.2.0.md) and [docs/releases/V1_2_0_RELEASE_SCOPE.md](docs/releases/V1_2_0_RELEASE_SCOPE.md).

## [1.1.0] — 2026-08-09

### Added

- First intentional public release package after the restored green CI/release baseline.
- WooCommerce visitor coverage (A.7a–A.7d): product/catalog overlays, archive chrome (orderby labels), customer journey chrome (checkout / My Account), and customer email subject/heading overlays with ADR-0018 transactional language context.
- WordPress visitor chrome (A.6): translated custom nav menu item titles (Supported N1).
- Fluent Forms contact bridge (A.8): Integration API v1 consumer for contact form chrome.
- A.SEO family (A.SEOa–A.SEOf):
  - A.SEOa — permalink/preview honesty for Supported SA7/SA10 (translated leaf slugs remain Deferred).
  - A.SEOb — canonical, hreflang, and SB11 `LanguageRelationshipService`.
  - A.SEOc — Rank Math title/description overlays (Partially Supported SC7–SC9).
  - A.SEOd — Open Graph / Twitter text overlays via official Rank Math hooks.
  - A.SEOe — Rank Math sitemap xhtml:link discovery overlays with `blog_public` honesty.
  - A.SEOf — bounded SEO diagnostics (CLI `wp aiml seo status` + admin), not a site-wide crawler.
- Release engineering: full-repo PHPCS green (warnings fail), Action Scheduler integration harness fix, runtime-only ZIP packaging, and `bin/audit-zip.sh` in CI/Release.

### Notes

- Schema target remains **6** — upgrading from a historical 1.0.0 package does not require a schema bump.
- Production package is `ai-multilingual-1.1.0.zip` from `bin/build-zip.sh` / GitHub Actions on `v*` tags.
- See [docs/releases/v1.1.0.md](docs/releases/v1.1.0.md) and [docs/releases/V1_1_0_RELEASE_SCOPE.md](docs/releases/V1_1_0_RELEASE_SCOPE.md) for scope admissions and known limitations.

## [1.0.0] — 2026-08-06

### Added

- Scoped platform v1.0.0: Gutenberg leaf translation, Translator Workspace, Translation Memory, Glossary MVP, Review Workflow, Background Translation Jobs, Limited Rollout, and General Availability controls.
- Database schema target **6** (Store, TM, glossary, review columns, background jobs).
- OpenAI provider via Chat Completions (`/v1/chat/completions`) with encrypted API key storage.
- REST, WP-CLI, diagnostics, and Action Scheduler job execution.

### Fixed

- OpenAI Chat Completions: omit `temperature` for `gpt-5*` and `o`-series models that reject custom values; retain `0.2` for other models.

### Notes

- Production package is the Release ZIP built by `bin/build-zip.sh` / GitHub Actions on `v*` tags.
- Explicit product limits (not blockers): no Elementor body translation; no nested container block identity; WooCommerce surfaces incomplete; render cache default-off; seven documented Gutenberg leaf adapters.
- Historical package/tag metadata only — not treated as an intentional public release for SemVer sequencing of 1.1.0.

## [0.1.0] — prior

Initial development builds through Strategy F (F1–F14) and the post-v1 platform track (Glossary, Review, Background Jobs).
