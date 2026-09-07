# FLOATING LANGUAGE SELECTOR — Closure

**Status:** CLOSED — PASS  
**Date:** 2026-09-07  
**Owner:** Universal Multilingual  
**Release:** not performed  
**Production:** untouched

## Baselines

| Item | Start | Final |
|---|---|---|
| `origin/main` | `7aba71c4876d1e8dd03bb0bd7a1db1e1216a4de0` | `d253cadcb51c5956d3b3d10633177926b42244bd` |
| Version | 1.11.1 | 1.11.1 |
| `Migrator::TARGET` | 8 | 8 |
| `Settings::SCHEMA_VERSION` | 1 | 2 (option-shape marker only) |

Upstream drift at freeze: **none** (planning SHA matched current main).

## Freeze

| Item | Value |
|---|---|
| Frozen plan | `docs/plans/FLOATING_LANGUAGE_SELECTOR_IMPLEMENTATION_PLAN.md` |
| Freeze commit | `907af27805b7dfad697ed596044b4f4526e318ea` |
| Feature branch | `feature/floating-language-selector` |

## Implementation

| Item | Value |
|---|---|
| Implementation commit | `3eac4821e190c278be0b782b9ebd5e423c8384b0` |
| Merge | `--no-ff` PR #62 → `d253cadcb51c5956d3b3d10633177926b42244bd` |

Materially changed components:

- `Switcher::all_language_links()` shared SB11/SA7 link model
- `Frontend\FloatingSelector` request-local model, enqueue, markup, AJAX
- `Settings` keys + schema 2
- `SettingsPage` subsection
- frontend CSS/JS
- tests, ADR-0027, edge convention, HOOKS, user manual

## Settings delivered

| Key | Default |
|---|---|
| `floating_selector_enabled` | `false` |
| `floating_selector_side` | `right` |
| `floating_selector_vertical` | `center` |
| `floating_selector_collapsed` | `code` |
| `floating_selector_preset` | `edge_pill` |
| `floating_selector_show_desktop` | `true` |
| `floating_selector_show_mobile` | `true` |
| `floating_selector_persist_preference` | `true` |

Migration: **NONE**. `Migrator::TARGET` unchanged.

## Contracts

| Contract | Result |
|---|---|
| Shared Switcher link model | `all_language_links()`; hide-current still applies only to shortcode/nav |
| Eligibility | published + relationship; current included; preview gated; `<2` links → absent |
| Current language | request/URL / SB11 `is_current`; not preferred language |
| Target URLs | Switcher / SB11 / SA7 |
| Collapsed | code / name / globe (globe has accessible name; no flags) |
| Presets | edge_pill / minimal / tab |
| Viewport | CSS-only `--hide-mobile` / `--hide-desktop` at 781/782px |
| Assets | enqueue on `wp_enqueue_scripts`; footer prints cached model |
| Memoization | request-local `prepared` flag; footer does not rebuild URLs |
| Unenhanced | language `<a href>` list visible |
| JS enhancement | `data-aiml-enhanced="1"` then collapse |
| Keyboard | disclosure; Escape closes and returns focus to toggle |
| Reduced motion | `prefers-reduced-motion: reduce` |
| Mobile / safe-area | `env(safe-area-inset-*)`; bounded panel width |
| Admin bar | `--wp-admin--admin-bar--height` for top docking |
| Preference persist | authenticated `wp_ajax_aiml_floating_selector_prefer` → `aiml_set_preferred_language()` |
| Transport | `fetch(..., { keepalive: true })`; navigation never awaits |
| Anonymous | no AJAX, no cookie, no preference write |
| AJAX security | nonce; current user only; no nopriv |
| Edge convention | `data-um-edge-*` + `docs/EDGE_CONTROL_CONVENTION.md` |
| UMC code | none |
| Routing / SEO | unchanged |
| Anonymous cache | ADR-0024 preserved; **CACHE KEY CHANGE: NO** |

## Gates

| Gate | Result |
|---|---|
| Unit | PASS — 941 tests, 3110 assertions (2 skipped) |
| Integration | PASS — 951 tests, 36425 assertions (3 skipped) |
| PHPCS | PASS |
| JS/build | vanilla assets; zip audit includes frontend CSS/JS |
| PluginGuard | PASS (in integration suite) |
| RoutingTest | PASS (in integration suite) |
| quality:validate | PASS |
| Playwright harness | added; Chromium pin mismatch on this host; DEV verified via WP-CLI/curl + browser |
| Independent review | PASS — 0 BLOCKER, 0 HIGH; M1/L2/L3/L5/L7 remediated before commit |
| PR | https://github.com/magpern/universal-multilingual/pull/62 |
| PR CI | PASS — run `34164295514` (phpcs, unit, integration, build, quality) |
| DEV acceptance | PASS on https://dev.biopentra.eu only (restored default OFF after) |
| Merge SHA | `d253cadcb51c5956d3b3d10633177926b42244bd` |
| Fresh-main CI | PASS — run `34165202698` https://github.com/magpern/universal-multilingual/actions/runs/34165202698 |

## DEV acceptance notes

- Disabled: selector absent.
- Enabled: right-edge `EN` control; disclosure opens `en` / `Svenska`; Escape returns focus.
- Product and page URLs match Switcher/SB11 (`/product/nacl-water/` ↔ `/sv/product/nacl-water/`).
- `/sv/peptide-guide/` shows current language Svenska.
- CSS/JS contracts: keepalive, no `await`, unenhanced panel not `display:none`.
- UMC `SEK` remained on the left; UML language on the right — no UMC changes.
- Authenticated keepalive wrote `aiml_preferred_language=sv` for `bp_manager` without waiting on navigation.
- Pre-existing site issue (out of scope): WordPress `301` loop on `/sv/` **homepage** (`x-redirect-by: WordPress`). Selector correctly emits that URL; inner localized pages work.

## Deferred

- Memoize `LanguageRelationshipService::for_path()` across Switcher + SEO head (MEDIUM follow-up, not a contract break).
- Homepage `/sv/` redirect loop (site routing, not this milestone).
- Playwright Chromium download pin for `acceptance/floating-selector-browser`.
- UMC currency edge-control implementation.

## Release / production

- Release: **NOT PERFORMED**
- Production: **UNTOUCHED**
- Selector default: **OFF**
