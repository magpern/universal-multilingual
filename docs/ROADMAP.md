# Roadmap

**Released in v1.13.0:** **Language admin redesign** — selection-based "Add a
language", plugin-owned offline locale registry, extended URL-code grammar,
`UNIQUE KEY locale` (schema **8 → 9**). Plan +
[validation log](plans/LANGUAGE_ADMIN_REDESIGN_VALIDATION_LOG.md);
[ADR-0028](adr/0028-curated-locale-registry-and-language-admin.md) /
[ADR-0029](adr/0029-extended-language-url-code-grammar.md). Merged in PR #63;
Playwright acceptance executed on DEV (30/30). Production deployment separately
authorized.

**Long-term product planning (canonical):** [POST_V1_PLATFORM_ROADMAP.md](plans/POST_V1_PLATFORM_ROADMAP.md) — Roadmap **v1.0** (frozen). Do not duplicate strategic planning in this file.

**Implementation priority (canonical):** [PRODUCT_PRIORITIES.md](PRODUCT_PRIORITIES.md) — product-direction guidance for which program to pursue next.

**Post-v1.1 program:** Translation Intelligence & Quality — [TIQ_PARENT_IMPLEMENTATION_PLAN.md](plans/TIQ_PARENT_IMPLEMENTATION_PLAN.md) (**COMPLETE** on `main`; TQ.0–TI.7). **v1.2.0 released**.

**Post-v1.2.0 / v1.3.0 programs:** Operator Translation Lifecycle — [OTL_PARENT_IMPLEMENTATION_PLAN.md](plans/OTL_PARENT_IMPLEMENTATION_PLAN.md) (**OTL program COMPLETE**; OTL.0–OTL.6). Translation Surface Coverage — [TSC_PARENT_IMPLEMENTATION_PLAN.md](plans/TSC_PARENT_IMPLEMENTATION_PLAN.md) (**TSC PROGRAM COMPLETE — TSC.0–TSC.6** — [TSC6 plan](plans/TSC6_PUBLIC_EXTENSION_SEO_STABILIZATION_IMPLEMENTATION_PLAN.md); [validation log](plans/TSC6_PUBLIC_EXTENSION_SEO_STABILIZATION_PLANNING_VALIDATION_LOG.md); [evidence](plans/TSC6_IMPLEMENTATION_EVIDENCE.md); [ADR-0022](adr/0022-public-extension-boundary-and-registration-lifecycle.md); Extension API v1; STATE A / TARGET 7). Historical Program C remains catalog history; OTL is authoritative for overlapping operator-lifecycle scope.

**Post-v1.4.0 program:** Multilingual SEO & Localized URLs — [MSEO_PARENT_IMPLEMENTATION_PLAN.md](plans/MSEO_PARENT_IMPLEMENTATION_PLAN.md); [ADR-0023](adr/0023-localized-url-overlay-architecture.md); **MSEO PROGRAM COMPLETE — MSEO.0–MSEO.5** ([MSEO5 closure](plans/MSEO5_CLOSURE.md); [MSEO5 plan](plans/MSEO5_PROGRAM_HARDENING_ACCEPTANCE_RELEASE_DOGFOOD_IMPLEMENTATION_PLAN.md)). **No formal MSEO.6.** STATE B / `Migrator::TARGET` **8**.

**Active post-v1.5.1 milestone:** **v1.6.0** — [closure](releases/V1_6_0_RELEASE_CLOSURE.md) — **RELEASED**. Tag `v1.6.0` → `417df7a5b…`. Accumulated train **P0 + P1 + P2**. Version **1.6.0**; TARGET **8** / migration **NONE**. **DEPLOYMENT NOT PERFORMED**. **P3 NOT AUTHORIZED**. Next: DEV product use + fresh prioritization. **P2** COMPLETE. **P1** COMPLETE. **P0** COMPLETE.

**Prior corrective milestone:** **v1.5.1 Localized URL Correctness / SEO Stabilization** — [closure](plans/V151_LOCALIZED_URL_CORRECTNESS_STABILIZATION_CLOSURE.md); [release closure](releases/V1_5_1_RELEASE_CLOSURE.md); [DEV runtime re-acceptance](validation/V1_5_1_DEV_RUNTIME_REACCEPTANCE.md) — **COMPLETE / V151AC22 PASS**. TARGET **8** / no migration.

**Release status:** AI Multilingual **v1.5.1** released and DEV runtime re-accepted — tag `v1.5.1` → `6298df08b3b1456e4875ecdb860b71506d5ae313`. P0 does **not** authorize a new release. Prior released: **v1.5.0**. **A.SEO** Complete. **TIQ** Complete. **OTL** Complete. **TSC PROGRAM COMPLETE**. **MSEO PROGRAM COMPLETE**. Runtime `Migrator::TARGET` **8**.

This document retains the classic milestone table (M0–M7) and Strategy F completion status for historical orientation. One approved milestone at a time. Each is accepted before the next begins.

| M | Name | Delivers | Schema |
|---|---|---|---|
| 0 | Discovery and plan | Architecture, scaffold, CI, docs, ADRs | — |
| **1** | **Skeleton and language foundation** | **Activation, migrations, three-state language model, `/sv/` routing, switcher, manual translation of one classic page, stale detection, inert deactivation** | **languages, translations** |
| 2 | Object-aware manual translation | Block-level segmentation; translated slugs with permanent reservation and redirects; term, menu and SEO-meta segments; REST API; side-by-side segment editor; assembled-field cache | slugs |
| 3 | AI, memory and jobs | `AIProviderInterface` with OpenAI first; prompt profiles; response validator; ten-stage resumable pipeline with bounded checkpoints; Action Scheduler; translation memory; glossary; usage and cost tracking; revision-hash migration | jobs, glossary, tm, ai_usage |
| 4 | WooCommerce completeness | Variations, attributes, archives, cart and checkout routing, order language, email language, Store API context, language cookie | — |
| 5 | SEO | Canonical, hreflang, `x-default`, Open Graph, Rank Math adapter, sitemap alternates, redirect history, noindex for preview languages — **Program A A.SEO** family [parent plan](plans/ASEO_PARENT_IMPLEMENTATION_PLAN.md) (waves A.SEOa–A.SEOf; [dependency matrix](plans/A_SEO_DEPENDENCY_MATRIX.md)); **A.SEOa** [plan](plans/ASEOA_SLUGS_PERMALINK_TRANSLATION_IMPLEMENTATION_PLAN.md) (**Complete**; tag `a-seoa-slugs-permalinks-complete`; Supported SA7/SA10); **A.SEOb** [plan](plans/ASEOB_CANONICAL_HREFLANG_IMPLEMENTATION_PLAN.md) (**Complete**; tag `a-seob-canonical-hreflang-complete`; Supported SB1–SB11; [validation log](plans/ASEOB_CANONICAL_HREFLANG_VALIDATION_LOG.md); [evidence](plans/aseob-evidence/); **A.SEOc** [plan](plans/ASEOC_RANK_MATH_INTEGRATION_IMPLEMENTATION_PLAN.md) (**Complete**; tag `a-seoc-rankmath-complete`; Supported SC1–SC6/SC10–SC14; Partially Supported SC7–SC9; [validation log](plans/ASEOC_RANK_MATH_INTEGRATION_VALIDATION_LOG.md); [evidence](plans/aseoc-evidence/); **A.SEOd** [plan](plans/ASEOD_OPENGRAPH_IMPLEMENTATION_PLAN.md) (**Complete**; tag `a-seod-opengraph-complete`; Supported SD1–SD3/SD5–SD8/SD11; Partially Supported explicit Facebook/Twitter text; Deferred SD4/SD9/SD10/SD12; [validation log](plans/ASEOD_OPENGRAPH_IMPLEMENTATION_VALIDATION_LOG.md); [evidence](plans/aseod-evidence/); **A.SEOe** [plan](plans/ASEOE_SITEMAPS_IMPLEMENTATION_PLAN.md) (**Complete**; tag `a-seoe-sitemaps-complete`; Supported SE1–SE9/SE12; Deferred SE10/SE11; [evidence](plans/aseoe-evidence/); [validation log](plans/ASEOE_SITEMAPS_IMPLEMENTATION_VALIDATION_LOG.md); **A.SEOf** [plan](plans/ASEOF_SEO_DIAGNOSTICS_IMPLEMENTATION_PLAN.md) (**Complete**; tag `a-seof-seo-diagnostics-complete`; Supported SF1–SF14; Partially Supported SF15; [validation log](plans/ASEOF_SEO_DIAGNOSTICS_IMPLEMENTATION_VALIDATION_LOG.md); [evidence](plans/aseof-evidence/)); A.SEO family **Complete**; shipped in **v1.1.0**; Deferred SEO surfaces remain Deferred (not TIQ). Post-v1.1 next program: [TIQ parent](plans/TIQ_PARENT_IMPLEMENTATION_PLAN.md) | — |
| 6 | Visual editor and residual strings | Front-end editing, gettext capture, menu and theme strings, Elementor segmentation | strings |
| 7 | Hardening and release candidate | Compatibility matrix, security review, performance profiling, migration and rollback, uninstall with removal, import/export, packaging | — |

## Milestone 1 scope boundary

Explicitly **not** in Milestone 1: AI translation, translation memory, glossary,
background jobs, REST API, cookies, default-language URL prefixing, translated
slugs, Gutenberg block bodies, Elementor bodies, WooCommerce product
translation, advanced SEO, bulk operations.

The schema and interfaces do not block any of them.

## Known limitations of Milestone 1

- Body translation covers classic content only. Block and Elementor bodies are
  refused by the extractor rather than risked; their titles and excerpts still
  translate.
- Slugs are not translated, so a Swedish page lives at `/sv/<source-slug>/`.
- Canonical URLs and hreflang are not yet emitted; the canonical redirect is
  suppressed for prefixed requests so nothing loops, but correct canonical
  output is Milestone 5.
- On a site running an SEO plugin that emits its own title tag — Rank Math
  does — the document `<title>` is not translated, because
  `document_title_parts` never runs. Headings and body text translate normally.
  The adapter that fixes this is Milestone 5.
- Editing content while the plugin is deactivated will not mark translations
  stale, because the hook is not registered. Re-checking hashes on activation is
  a Milestone 2 addition.
- One translation write invalidates the whole language's cache. Correct, and
  deliberately simple at this content scale.

## Strategy F production track

Block-identity production work (F1–F14) runs in parallel with roadmap
milestones. As of 2026-08-05:

| Milestone | Status |
|---|---|
| F1–F9 | Engineering complete on `main` |
| **F10** Translator Workspace MVP | **Complete** — merged to `main`; see [F10 validation log](plans/F10_TRANSLATOR_VALIDATION_LOG.md) |
| **F11** Translation Memory & AI Assistance | **Complete** — [canonical plan](plans/STRATEGY_F_F11_TRANSLATION_MEMORY_AND_AI_ASSISTANCE.md); [validation log](plans/F11_TRANSLATOR_VALIDATION_LOG.md) PASS; tags `strategy-f-f11-tm-ai-complete` / `strategy-f-f11-tm-ai-merged` |
| **F12** Limited rollout | **Complete** — merged to `main`; [validation log PASS](plans/F12_LIMITED_ROLLOUT_VALIDATION_LOG.md) |
| **F13** General Availability + ADR acceptance | **Complete** — merged to `main`; [canonical plan](plans/STRATEGY_F_F13_GENERAL_ROLLOUT.md); [validation log PASS](plans/F13_GENERAL_AVAILABILITY_VALIDATION_LOG.md); ADR-0013 **Accepted**; tags `strategy-f-f13-general-availability-merged` / `strategy-f-f13-general-availability-complete` |
| **F14** Supported Gutenberg Block Expansion | **Complete** — merged to `main`; [canonical plan](plans/STRATEGY_F_F14_BLOCK_EXPANSION.md); [validation log PASS](plans/F14_BLOCK_EXPANSION_VALIDATION_LOG.md); [summary](plans/F14_IMPLEMENTATION_SUMMARY.md); tag `strategy-f-f14-block-expansion-complete` |
| **Post-v1 product roadmap** | **Complete for v1 platform track** — historical [POST_V1_PRODUCT_ROADMAP.md](plans/POST_V1_PRODUCT_ROADMAP.md). **Long-term planning:** [POST_V1_PLATFORM_ROADMAP.md](plans/POST_V1_PLATFORM_ROADMAP.md). **P1 Platform Stabilization:** **Complete** — [plan](plans/P1_PLATFORM_STABILIZATION_IMPLEMENTATION_PLAN.md); [validation log PASS](plans/P1_PLATFORM_STABILIZATION_VALIDATION_LOG.md); tag `p1-platform-stabilization-complete`. **A.R1 Elementor Identity Research Spike:** **Complete** — [plan](plans/AR1_ELEMENTOR_IDENTITY_RESEARCH_SPIKE.md); [research log](plans/AR1_ELEMENTOR_IDENTITY_RESEARCH_LOG.md) (**CONDITIONAL GO**); [ADR-0016](adr/0016-elementor-identity-and-ownership.md) **Accepted**; tag `ar1-elementor-identity-research-complete`. **A.2 Elementor Foundation:** **Complete** — [plan](plans/A2_ELEMENTOR_FOUNDATION_IMPLEMENTATION_PLAN.md); [validation log PASS](plans/A2_ELEMENTOR_FOUNDATION_VALIDATION_LOG.md); tag `a2-elementor-foundation-complete`. **A.3 Elementor Widget Coverage:** **Complete** — [plan](plans/A3_ELEMENTOR_WIDGET_COVERAGE_IMPLEMENTATION_PLAN.md); [validation log PASS](plans/A3_ELEMENTOR_WIDGET_COVERAGE_VALIDATION_LOG.md); tag `a3-elementor-widget-coverage-complete`. **A.R2 Nested Gutenberg Identity:** **Complete** — [research log](plans/A4_NESTED_GUTENBERG_IDENTITY_RESEARCH_LOG.md) (**CONDITIONAL GO**); tag `ar2-nested-gutenberg-identity-research-complete`; **F5 PASS**. **A.4 Nested Gutenberg:** **Complete** — [implementation plan](plans/A4_NESTED_GUTENBERG_IMPLEMENTATION_PLAN.md); [validation log PASS](plans/A4_NESTED_GUTENBERG_VALIDATION_LOG.md); tag `a4-nested-gutenberg-complete`; Navigation/shared/dynamic remain deferred. **A.1 Plugin Integration Framework:** **Complete** — [implementation plan](plans/A1_PLUGIN_INTEGRATION_FRAMEWORK_IMPLEMENTATION_PLAN.md); [ADR-0017](adr/0017-plugin-integration-framework-ownership-and-identity.md) (**Accepted**); [validation log PASS](plans/A1_PLUGIN_INTEGRATION_FRAMEWORK_VALIDATION_LOG.md); tag `a1-plugin-integration-framework-complete`. **A.0 Gutenberg Leaf Expansion:** **Complete** — [implementation plan](plans/A0_GUTENBERG_LEAF_EXPANSION_IMPLEMENTATION_PLAN.md); [validation log PASS](plans/A0_GUTENBERG_LEAF_EXPANSION_VALIDATION_LOG.md); tag `a0-gutenberg-leaf-expansion-complete`. **A.8** first production bridge: Fluent Forms Contact Form #5 — [selection](plans/A8_INTEGRATION_CANDIDATE_SELECTION.md); [plan](plans/A8_FLUENTFORMS_CONTACT_INTEGRATION_IMPLEMENTATION_PLAN.md) (**Complete / merged / tagged** `a8-fluentforms-contact-integration-complete`; [validation log PASS](plans/A8_FLUENTFORMS_CONTACT_INTEGRATION_VALIDATION_LOG.md); admission **Supported**) **A.7** WooCommerce visitor coverage family: [plan](plans/A7_WOOCOMMERCE_VISITOR_COVERAGE_IMPLEMENTATION_PLAN.md); **A.7a** Product & Catalog: **Complete** — [plan](plans/A7A_WOOCOMMERCE_PRODUCT_CATALOG_IMPLEMENTATION_PLAN.md); [validation log PASS](plans/A7A_WOOCOMMERCE_PRODUCT_CATALOG_VALIDATION_LOG.md); tag `a7a-woocommerce-product-catalog-complete`; **A.7b** Archive Chrome: **Complete** — [plan](plans/A7B_WOOCOMMERCE_ARCHIVE_CHROME_IMPLEMENTATION_PLAN.md); [validation](plans/A7B_WOOCOMMERCE_ARCHIVE_CHROME_VALIDATION_LOG.md); tag `a7b-woocommerce-archive-chrome-complete`; **A.7c** Customer Journey: **Complete** — [plan](plans/A7C_WOOCOMMERCE_CUSTOMER_JOURNEY_IMPLEMENTATION_PLAN.md); [validation](plans/A7C_WOOCOMMERCE_CUSTOMER_JOURNEY_VALIDATION_LOG.md); tag `a7c-woocommerce-customer-journey-complete`; **A.7d** Customer Emails: **Complete** — [plan](plans/A7D_WOOCOMMERCE_CUSTOMER_EMAILS_IMPLEMENTATION_PLAN.md); [validation](plans/A7D_WOOCOMMERCE_CUSTOMER_EMAILS_VALIDATION_LOG.md); tag `a7d-woocommerce-customer-emails-complete`; ADR-0018 implemented; Supported CE1–CE6/CE9–CE10 subject+heading; CE7/CE8 Deferred; **A.6** WordPress Visitor Chrome: [plan](plans/A6_WORDPRESS_VISITOR_CHROME_IMPLEMENTATION_PLAN.md); [validation](plans/A6_VALIDATION_LOG.md) (**Complete / merged / tagged** `a6-wordpress-visitor-chrome-complete`; Supported N1) per [PRODUCT_PRIORITIES.md](PRODUCT_PRIORITIES.md) |
| **Glossary MVP** | **Complete** — merged to `main`; [plan](plans/GLOSSARY_MVP_IMPLEMENTATION_PLAN.md); [validation log PASS](plans/GLOSSARY_MVP_VALIDATION_LOG.md); ADR-0014 **Accepted**; tag `glossary-mvp-complete` |
| **Review Workflow** | **Complete** — merged to `main`; tag `review-workflow-complete`; [plan](plans/REVIEW_WORKFLOW_IMPLEMENTATION_PLAN.md); [validation log PASS](plans/REVIEW_WORKFLOW_VALIDATION_LOG.md); ADR-0015 **Accepted** ([0015-review-workflow-and-tm-approval-policy.md](adr/0015-review-workflow-and-tm-approval-policy.md)); review queue = Store filter view (not assignments); live smoke 68/68 PASS |
| **Background Translation Jobs** | **Complete** — merged to `main` @ `b308138c4`; tag `background-translation-jobs-complete`; [plan](plans/BACKGROUND_TRANSLATION_JOBS_IMPLEMENTATION_PLAN.md); [validation log PASS](plans/BACKGROUND_TRANSLATION_JOBS_VALIDATION_LOG.md); [ADR-0011](adr/0011-resumable-job-pipeline.md) **Accepted**; schema target 6 |
