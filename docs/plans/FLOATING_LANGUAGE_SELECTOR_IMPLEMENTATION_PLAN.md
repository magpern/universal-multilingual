# FLOATING LANGUAGE SELECTOR — Implementation Plan (FROZEN)

**Status:** FROZEN for implementation
**External review:** PASS — READY TO FREEZE AND IMPLEMENT
**Canonical authority:** this document in `magpern/universal-multilingual`
**Scope:** Universal Multilingual only. Do not modify Universal Multicurrency, WooCommerce, storefront/theme plugins, Elementor, or any third/shared plugin.

## Reconciled baselines (implementation start)

| Item | Value |
|---|---|
| Repo | magpern/universal-multilingual |
| `origin/main` | `7aba71c4876d1e8dd03bb0bd7a1db1e1216a4de0` |
| Version | **1.11.1** (no release bump in this milestone) |
| `Migrator::TARGET` | **8** (unchanged; this is not a DB migration) |
| `Settings::SCHEMA_VERSION` | **1 → 2** (option-shape marker only) |
| Planning SHA | same as current `origin/main` — no material upstream drift |

The body below is the externally reviewed plan, reconciled only for current-main facts.

---

# Floating Language Selector — Implementation Plan

**Planning authority:** current `origin/main` (not historical assumptions).

| Item | Value |
|---|---|
| Repo | [universal-multilingual](https://github.com/magpern/universal-multilingual) |
| `origin/main` | `7aba71c4876d1e8dd03bb0bd7a1db1e1216a4de0` |
| Version | **1.11.1** |
| `Migrator::TARGET` | **8** (unchanged) |
| `Settings::SCHEMA_VERSION` | **1** → bump to **2** for new keys only (option-shape marker, **not** a DB migration) |
| Regional Preferences | CLOSED — PASS; APIs exist; language runtime still storage+API+UI only |

**STATE A** — presentation layer over existing language/routing authority.

---

## 1–4. Current architecture (frozen facts)

### Switcher today
[`src/Frontend/Switcher.php`](dev/universal-multilingual/src/Frontend/Switcher.php) is PHP-only:

- Shortcode `[aiml_switcher]`
- Opt-in nav via `aiml_switcher_in_menu` (default false)
- **No widget, no floating UI, no frontend CSS/JS, no flags**
- `links()` uses `LanguageRelationshipService::for_path()` + `Languages::routable($can_preview)`
- Navigation is ordinary `<a href>` to SB11 URLs (EffectiveUrl / SA7 fallback)

Existing shortcode/nav stay. Floating selector is a **second presentation surface** over the same link model — not a second URL builder.

### URL authority
Reuse `Switcher::links()` (or a small shared helper extracted from it that **does not** apply `switcher_hide_current`). Do not call EffectiveUrl directly from the floating UI.

Switcher vs hreflang: when a localized path is not discoverable, switcher still emits **SA7 source-slug** URLs; hreflang omits. Floating selector **follows switcher**, not hreflang omit.

### Preferred language
[`aiml_get/set_preferred_language`](dev/universal-multilingual/src/Extension/functions.php) + state. **No REST.** Writes are PHP + nonce on profile/Account. ADR-0026: preference must not change URL render language.

### Frontend assets
No public-frontend enqueue pattern today (admin/workspace only). This milestone adds the **first** small public CSS+vanilla JS pair. Assets enqueue on `wp_enqueue_scripts` only when the request-local selector model is eligible (enabled, ≥2 languages, at least one viewport category enabled). `wp_footer` only prints that already-prepared model.

---

## 5. Ownership

**Universal Multilingual only.** No UMC, storefront, theme, Woo, Elementor, or third UI plugin. UMC already has its own edge-pill currency switcher (`data-umc-*`, `--umc-switcher-*`); UML must not depend on it.

---

## 6. Settings model (compact, default off)

Add keys to [`Settings::defaults()`](dev/universal-multilingual/src/Settings.php) + sanitize allowlists. `sanitize()` already starts from defaults, so existing sites pick up new keys without backfill.

| Key | Type | Default |
|---|---|---|
| `floating_selector_enabled` | bool | **false** |
| `floating_selector_side` | `left`\|`right` | `right` |
| `floating_selector_vertical` | `top`\|`center`\|`bottom` | `center` |
| `floating_selector_collapsed` | `code`\|`name`\|`globe` | `code` |
| `floating_selector_preset` | `edge_pill`\|`minimal`\|`tab` | `edge_pill` |
| `floating_selector_show_desktop` | bool | true |
| `floating_selector_show_mobile` | bool | true |
| `floating_selector_persist_preference` | bool | true |

**Out of v1:** flags, arbitrary CSS, pixel offset builder, style customizer, live storefront iframe preview.

Admin UI: one Settings subsection “Floating language selector” using existing `form-table` / `checkbox_row` conventions in [`SettingsPage.php`](dev/universal-multilingual/src/Admin/SettingsPage.php). Static thumbnail/diagram of preset+side — **no live preview**.

Existing `switcher_show_native_name` applies to **open-panel labels**. `switcher_hide_current` does **not** apply to the floating control (current language must remain visible in collapsed + open states).

---

## 7. Collapsed / open UX

**Collapsed:** edge-flush tab/pill. Representation:

- `code` → uppercase language code (e.g. `SV`) — **v1 recommended default**
- `name` → short native/English name, truncated
- `globe` → inline SVG globe + visually hidden current-language name (never icon-only for SR)

**No flags.** UML has no flag authority (`aiml_languages` has name/native_name/locale only). Do not invent a flag pack.

**Open:** inward panel from the docked edge; list of languages; current marked with `aria-current="page"` + check glyph; bounded max-height + scroll. Toggle button (no extra close button). Click-outside and Escape close. Selecting a language **navigates** (browser follows the link).

---

## 8. Current language vs preferred language

Visible selected state = **current URL/request language** (`LanguageRelationship.is_current` / `LanguageContext`), never stored `aiml_preferred_language`.

---

## 9. Eligibility

Same as `Switcher::links()` without hide-current:

| Language | Outcome |
|---|---|
| Published + relationship exists | selectable |
| Current | current (shown, not a no-op hide) |
| Preview | only if `current_user_can( aiml_translate )` |
| Disabled | hidden |
| Not in `for_path()` | hidden |
| `< 2` resulting links | **do not render** the floating control |

Unavailable localized routes: still linked via SA7 source-slug URL (existing switcher policy). Do not duplicate publication/routing policy.

---

## 10. Navigation contract (non-negotiable)

```text
click / activate language option
  → existing SB11 URL (Switcher/relationship)
  → browser navigation
```

No same-URL mutation, no cookie, no Accept-Language, no geo, no slug rewrite.

---

## 11. Preferred-language persistence (frozen — amendment 1)

**Navigate independently. Persist best-effort for logged-in users when the setting is on. The persistence request must be navigation-safe.**

```text
<a href="{target}">  ← always the authority; never delayed
optional: wp_ajax_aiml_floating_selector_prefer (priv only, no nopriv)
```

- New `wp_ajax_aiml_floating_selector_prefer` (logged-in only): nonce + `code` → `aiml_set_preferred_language( get_current_user_id(), $code )`.
- Transport (frozen): `fetch(url, { method: 'POST', credentials: 'same-origin', keepalive: true, body, headers })`.
- **Do not await** the response. **Do not** `preventDefault` wait, timeout-then-navigate, or `location.assign` after success. Let the browser follow the existing `<a href>`.
- `keepalive: true` is required so the request can survive document unload. An ordinary fire-and-forget `fetch` without keepalive is **not** acceptable — it is cancelled on navigation and would make persistence fail in the normal selector flow.
- If `fetch`/`keepalive` is unavailable or the call throws, navigation still proceeds; preference remains unchanged.
- Failed/network/error responses never change the destination URL.
- No-JS / JS fail: links still work; preference unchanged (acceptable).
- Anonymous: no AJAX, no cookie, no meta.

This is a small authenticated AJAX helper, **not** REST-as-sole-save-path and **not** a public unauthenticated API. `navigator.sendBeacon()` is an allowed fallback if `fetch`+keepalive cannot be used, but `fetch`+keepalive is preferred so WordPress AJAX can still receive nonce/body as a normal POST.

---

## 12. Rendering architecture (frozen — amendments 2–4)

### Asset vs markup lifecycle

Do **not** enqueue styles inside `wp_footer` (stylesheets have already printed).

```text
wp_enqueue_scripts
  prepare request-local selector model (once)
    disabled OR <2 langs OR both viewport flags off → cache null; enqueue nothing
    else → cache model; enqueue CSS + JS

wp_footer
  render the already-prepared model (or nothing)
```

The model is request-local (instance property / static memo). Preparation and render **share one** extracted switcher link list so SB11/EffectiveUrl work is not duplicated.

### Viewport visibility (CSS-controlled)

PHP does not know the client viewport. Frozen rule:

- Both `show_desktop` and `show_mobile` false → do not render, do not enqueue.
- Otherwise **always render the same markup** (when enabled and ≥2 langs).
- Emit deterministic classes, e.g. `aiml-floating-selector--hide-mobile` / `--hide-desktop`.
- CSS media queries hide the control on the disabled category (`max-width: 781px` aligned with WP admin-bar breakpoint).
- No user-agent sniffing. Cached HTML stays deterministic for a given URL + settings + published languages.

### Progressive enhancement (unenhanced links must be usable)

CSS must **not** hide the language list by default in a way that requires JS to reveal it.

Frozen behavior:

1. Unenhanced (no JS / JS failed): the `<ul>` of language links is **visible and operable**. The toggle button may be present but is not required to expose the links.
2. JS, on init: set `data-aiml-enhanced="1"` (or class `is-enhanced`) on the root **before** applying collapsed disclosure CSS/behavior.
3. Enhanced CSS then collapses the panel and uses `aria-expanded` for open/close.

A native `<details>` is an acceptable alternative if it is simpler, but the button/disclosure model is fine **only if** this enhancement handshake is implemented. Do not ship a collapsed-only stylesheet that leaves a dead toggle when the JS bundle fails.

### Markup / JS / CSS split

PHP: eligibility, current language, target URLs, viewport classes, config data attributes.  
JS: mark enhanced, then open/close, focus return on close, Escape, outside click, reduced-motion, keepalive prefer AJAX.  
CSS: docking, presets, motion, safe-area, admin-bar, viewport hide classes, unenhanced vs enhanced panel visibility.

**Not** the translator-workspace React app. **Not** jQuery. **Not** a shortcode (automatic floating control).

Recommended semantics: **disclosure, not listbox**. This is navigation, not an in-place value widget.

- Toggle: `button` `aria-expanded` `aria-controls` (inert until enhanced, or `hidden` until enhanced)
- Panel: `ul` of `a` with `hreflang` and `aria-current="page"` on current
- `aria-label` on nav: “Language”

Keyboard (enhanced): Enter/Space on toggle; Tab through links; Escape closes and returns focus to toggle. Unenhanced: normal link tab order.

---

## 13. Motion / mobile / admin bar

- CSS transform+opacity, ~180–220ms, `transform-origin` side-aware
- `@media (prefers-reduced-motion: reduce)` → no transform, instant opacity
- Do not delay navigation for animation
- Mobile: same edge tab, narrower panel (`min(18rem, calc(100vw - 1.5rem))`), `env(safe-area-inset-*)`, `44px` min hit target
- Desktop/mobile visibility: CSS only (see §12); PHP never omits markup based on guessed viewport
- `body.admin-bar`: offset top using `--wp-admin--admin-bar--height` (fallback 32px / 46px)
- z-index: `1000` via `--um-edge-z-index` (same order of magnitude as UMC edge pill; not above WP admin bar ~99900)

---

## 14. Edge-control interoperability (documented convention only)

No shared package. No runtime coupling.

Emit on the UML root:

```html
<nav class="aiml-floating-selector"
     data-um-edge-control="language"
     data-um-edge="right"
     data-um-edge-slot="1"
     data-um-edge-priority="10">
```

Document in HOOKS.md / a short ADR:

| Control | `data-um-edge-control` | default slot | priority |
|---|---|---|---|
| UML language | `language` | `1` | `10` |
| Future UMC currency | `currency` | `2` | `20` |

Stacking CSS (each plugin implements independently):

```text
--um-edge-control-size: 2.75rem
--um-edge-stack-gap: 0.5rem
offset along edge = (slot - 1) * (size + gap)
```

v1 does **not** require UMC changes. If both later occupy the same edge, documented slots prevent overlap without JS discovery. Dynamic collision detection is deferred.

UMC’s existing `data-umc-*` / `--umc-switcher-*` stay UMC-private. The `data-um-edge-*` names are the **only** shared convention.

---

## 15. Cache / SEO

| Concern | Verdict |
|---|---|
| Anonymous cookie / AL / geo | **None** (PluginGuard + RoutingTest remain green) |
| Same URL + settings + published langs | Same markup |
| Preview langs in markup | Only for `aiml_translate` users (typically uncached) |
| FPC cache key change | **NONE** |
| canonical / hreflang / sitemap / slugs | **Unchanged** — selector is navigation UI only |

---

## 16. Failure / degradation

| Case | Behavior |
|---|---|
| Disabled | no render, no assets |
| One language | no render |
| Target URL missing | language omitted |
| One viewport flag off | same HTML; CSS hides that category |
| Both viewport flags off | no render, no assets |
| JS/CSS fail | unenhanced links remain visible and usable; no fatal |
| Preference AJAX fail / keepalive unsupported | navigate anyway; preference unchanged |
| Admin bar | offset, still usable |
| Woo/theme sticky bars | z-index 1000; no theme-specific selectors |

Selector failure must not break page render (`try` around render not required if methods are fail-closed).

---

## 17. Performance

- No Store scans, no AI, no route generation at render (reuse SB11 request-cached EffectiveUrl)
- Assets only when the request-local model is eligible, enqueued on `wp_enqueue_scripts` (not footer)
- Bounded language list (`routable()`)
- No anonymous DB writes
- Guard: PluginGuard-style “no setcookie in src”; optional assertion that floating assets enqueue only when enabled

---

## 18. Files / classes expected to change

**New**

- `src/Frontend/FloatingSelector.php` — prepare model on `wp_enqueue_scripts`, render on `wp_footer`, AJAX register
- `assets/frontend/floating-selector.css`
- `assets/frontend/floating-selector.js`
- `docs/adr/0027-floating-language-selector.md` (if 0027 unused)
- `docs/EDGE_CONTROL_CONVENTION.md` (tiny; referenced from UML HOOKS and later UMC)

**Edit**

- [`src/Frontend/Switcher.php`](dev/universal-multilingual/src/Frontend/Switcher.php) — extract `all_language_links()` (ignore hide-current) for reuse; keep `links()` wrapping it for shortcode/nav
- [`src/Settings.php`](dev/universal-multilingual/src/Settings.php) — defaults, sanitize, accessors; SCHEMA_VERSION 2
- [`src/Admin/SettingsPage.php`](dev/universal-multilingual/src/Admin/SettingsPage.php) — settings group
- [`src/Plugin.php`](dev/universal-multilingual/src/Plugin.php) — wire FloatingSelector
- `docs/HOOKS.md`, user/admin docs

**Must not change:** `LanguageResolver`, `Router`, EffectiveUrl semantics, hreflang emission, preferred-language meta key, Migrator TARGET.

---

## 19. Test matrix

**Unit/integration**

- Settings sanitize/enums/defaults; disabled → no footer markup
- Enabled + ≥2 langs → markup with current + targets from SB11
- Left/right, vertical, collapsed modes
- Mobile-only / desktop-only → markup present with hide classes; both off → absent
- Assets not enqueued when model is null; enqueued on `wp_enqueue_scripts` when eligible
- Unenhanced CSS exposes links (no JS-required panel)
- One language → absent
- Preview hidden from anonymous, visible to translator
- Current language from URL, not user meta
- `SwitcherTest` still green (hide-current only affects shortcode/nav)
- PluginGuard: no `setcookie` / `$_COOKIE`
- RoutingTest: same-URL isolation
- AJAX: logged-in valid code persists; bad nonce rejected; nopriv unregistered
- JS transport uses `keepalive: true`; tests/docs assert navigation is not awaited
- Anonymous has no preference write
- `data-um-edge-*` attributes present

**Playwright / DEV** (`dev.biopentra.eu` after implementation auth)

- 375 / 768 / 1440; left + right; open/closed; keyboard; Escape; reduced motion
- Navigate `/` ↔ prefixed language URL; no language cookie
- Logged-in persist (best-effort) then profile shows stored preference
- Admin bar; product/page/My Account
- Do not test production

---

## 20. Public API / migration

- **Migration: NONE** (no user backfill, no TARGET bump)
- **Public API:** additive AJAX action + settings keys + documented `data-um-edge-*`. No breaks.
- Shortcode unchanged.

---

## 21. Risk / size / ladder

**Size:** Medium (new public CSS/JS + settings + a11y + one small AJAX). Architecture risk **low**.

Internal ladder (one implementation after freeze):

1. **LS.0** Settings keys + sanitize + admin UI  
2. **LS.1** Extract switcher link model; request-local FloatingSelector model  
3. **LS.2** `wp_enqueue_scripts` enqueue + `wp_footer` markup; CSS docking/presets/viewport hide/unenhanced panel  
4. **LS.3** JS enhancement handshake, disclosure/a11y/motion  
5. **LS.4** Keepalive best-effort preference AJAX  
6. **LS.5** `data-um-edge-*` + docs convention  
7. **LS.6** Tests, PluginGuard, DEV acceptance  

---

## 22. STOP conditions

Re-open if implementation needs: LanguageResolver/Router change; anonymous cookie/AL/geo; second URL builder; render-time route generation; hard UMC dependency; third plugin; TARGET/DB migration; anonymous cache-key change; REST as sole save path; flag system.

---

## 23. Docs / next step

ADR-0027 (floating selector + cache invariance + preference best-effort). EDGE_CONTROL_CONVENTION.md. HOOKS.md. Settings help text.

Amendments from external review are incorporated.

**NEXT:** FREEZE → one coherent UML implementation. No version bump, tag, release, or production deploy in that phase.

---

## Amendment log (external review)

| # | Amendment | Resolution |
|---|---|---|
| 1 | Navigation-safe preference transport | `fetch(..., { keepalive: true, credentials: 'same-origin' })`; never await; `<a href>` remains authoritative |
| 2 | Enqueue before footer | Prepare model + enqueue on `wp_enqueue_scripts`; `wp_footer` only prints the cached model |
| 3 | Viewport not PHP-gated | Render unless both desktop and mobile are off; hide via CSS classes/media queries |
| 4 | Unenhanced links usable | Default CSS exposes the link list; JS sets enhanced flag before collapsed disclosure |

---

# Final decision block

FLOATING LANGUAGE SELECTOR PLAN: AMENDED — READY TO FREEZE

STATE A — PRESENTATION LAYER OVER EXISTING LANGUAGE AUTHORITY

OWNER: UNIVERSAL MULTILINGUAL  
OPTIONAL: YES (default off)  
LANGUAGE URL AUTHORITY: UNCHANGED  
CURRENT LANGUAGE SOURCE: REQUEST / URL  
ANONYMOUS LANGUAGE PERSONALIZATION: NONE  
ANONYMOUS CACHE CONTRACT: UNCHANGED  
CACHE KEY CHANGE: NONE  
HARD UMC DEPENDENCY: NONE  
THIRD PLUGIN: NONE  
FLAGS: NONE (code / name / globe)  
USER PREFERENCE API: KEEPALIVE BEST-EFFORT; NEVER AWAIT; NEVER BLOCKS NAV  
ASSETS: ENQUEUE ON wp_enqueue_scripts FROM REQUEST-LOCAL MODEL  
VIEWPORT VISIBILITY: CSS ONLY  
UNENHANCED: LANGUAGE LINKS REMAIN ACCESSIBLE  
EDGE-CONTROL INTEROPERABILITY: DOCUMENTED `data-um-edge-*` CONVENTION ONLY  
MIGRATION: NONE  

NO IMPLEMENTATION · NO VERSION BUMP · NO RELEASE · NO DEPLOYMENT · PRODUCTION UNTOUCHED  

NEXT: FREEZE → ONE COHERENT UML IMPLEMENTATION
