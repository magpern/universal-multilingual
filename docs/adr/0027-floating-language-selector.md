# ADR-0027 — Floating language selector (presentation consumer)

**Status:** Accepted

## Context

Operators want an optional visitor-facing language control on the page edge.
Language URLs, cacheability, and SEO already have authority:

- Switcher / `LanguageRelationshipService` (SB11) / EffectiveUrl / SA7
- ADR-0024 anonymous URL/host cache contract
- ADR-0026 preferred language is storage + UI; it must not change render language

A second URL builder, anonymous language cookie, Accept-Language, or geo
resolver would break those contracts.

## Decision

Universal Multilingual owns an **optional** floating language selector
(default **off**) as a **STATE A presentation consumer**.

- Target URLs come only from `Switcher::all_language_links()` (same model as
  the shortcode/nav switcher, without `switcher_hide_current`).
- Visible/current language is the request/URL language, never
  `aiml_preferred_language`.
- No flags. No UMC dependency. No third plugin. No routing/SEO changes.
- Assets enqueue on `wp_enqueue_scripts` only when the request-local model is
  eligible; `wp_footer` prints that cached model.
- Unenhanced markup keeps language links visible. JS may collapse the panel
  only after `data-aiml-enhanced="1"`.
- Viewport visibility is CSS-only (`--hide-mobile` / `--hide-desktop` at the
  WordPress 782px admin-bar boundary). PHP does not sniff devices.
- Authenticated preference persistence is best-effort via
  `wp_ajax_aiml_floating_selector_prefer` (no nopriv) calling
  `aiml_set_preferred_language()`. Frontend `fetch(..., { keepalive: true })`
  must not delay or replace `<a href>` navigation.
- Edge-control interoperability is a documented HTML/CSS convention
  (`docs/EDGE_CONTROL_CONVENTION.md`), not a shared package.

`Migrator::TARGET` is unchanged. `Settings::SCHEMA_VERSION` is an option-shape
marker only.

## Consequences

- Existing `[aiml_switcher]` / nav behaviour stays, including hide-current.
- Anonymous cache key does not need a language dimension.
- Future UMC currency edge UI may follow the documented slot convention
  independently; this ADR does not implement or require UMC changes.
