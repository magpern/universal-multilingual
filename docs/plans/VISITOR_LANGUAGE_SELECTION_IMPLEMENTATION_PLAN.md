# Visitor-facing language selection for Universal Multilingual

## Context

Universal Multilingual (`/opt/biopentra/dev/universal-multilingual`) currently resolves
language for anonymous visitors purely from the URL (`LanguageResolver`, ADR-0002),
and only logged-in users get a persistent preference (`aiml_preferred_language` user
meta, ADR-0026). There is no way for an anonymous storefront visitor to have their
language choice remembered, or to be automatically offered a better-matching language
based on browser or geography. The product goal is to make the multilingual system
visitor-first rather than account-first, while keeping login-based persistence for
cross-device durability.

**The central constraint discovered during planning**: this plugin has an existing,
deliberately-written architecture contract — **ADR-0024 "Anonymous-language cache
contract"** — plus an enforced regression guard (`PluginGuardTest::test_no_cookie_is_set`,
`RoutingTest::test_routing_sets_no_cookie`) that flatly forbid any cookie,
`Accept-Language`, or geo signal from changing what an anonymous visitor's browser
receives for a given URL. This exists because `dev.biopentra.eu` sits behind this
VPS's own SWAG reverse-proxy cache (`proxy/config/nginx/site-confs/default.conf`),
keyed on `$scheme|$host|$request_uri|$bp_currency_bucket`. ADR-0024 explicitly
anticipates this exact feature and requires reopening itself, resolving one of: (a)
encode selection into the URL only, (b) add a cache-key dimension in coordination
with the proxy owner, or (c) scope to authenticated visitors only. There is also an
existing, shipped, presentation-only `FloatingSelector` (ADR-0027) that already
renders language links from `Switcher::all_language_links()`.

**Decision (confirmed with the user during planning):** go with option (a), entirely
client-side, zero proxy changes. Server-side anonymous resolution stays byte-identical
to today for any given URL. All new "automatic" behaviour (cookie read/write, browser
detection, geo fallback) lives in a small, static, equally-cacheable JS bundle that
acts **after** the (unchanged, cached) HTML has loaded. Weak/automatic signals
(browser, geo) never auto-redirect — they show a dismissible suggestion banner; only
an explicit human action (click, or an already-explicit account/cookie preference)
ever triggers a redirect or cookie write.

**Reverse-proxy audit (performed during planning, required before freeze):** inspected
`proxy/config/nginx/site-confs/biopentra-cache-request-eligibility.conf` and
`default.conf` directly.
- The request-side eligibility map `$bp_skip_cookie` (driven by `$http_cookie`, i.e.
  cookies the *visitor's browser sends*) only matches specific substrings:
  `wordpress_logged_in`, `wp_woocommerce_session`, `woocommerce_cart_hash`,
  `woocommerce_items_in_cart`, `comment_author`. It defaults to `0` (cache-eligible)
  for anything else. A new `aiml_visitor_lang` cookie does not match any of these, so
  **its mere presence on an incoming request does not disable full-page caching** for
  a returning anonymous visitor — confirmed, not assumed.
- Separately, `proxy_no_cache` already includes `$upstream_http_set_cookie` as a
  generic backstop: if WordPress ever emitted `Set-Cookie` on a response, that
  specific response would simply not be *saved* to cache (fail-safe, not a leak).
  This is an existing, independent safety net — it does not need to be relied on,
  because the design below never has PHP emit `Set-Cookie` on the anonymous render
  path, but it is documented in ADR-0035 as corroborating evidence that even an
  accidental violation would fail closed rather than leak.
- **Finding: no proxy change is required and none is made.** The approved
  client-side-only architecture is fully compatible with the existing cache
  configuration as-is.

This requires a new ADR (0035) that formally reopens ADR-0024, records the above audit
finding, and defines a **narrow, explicitly-scoped exception** to
`PluginGuardTest::test_no_cookie_is_set` for exactly one new class used only from
login/registration/logout hooks (which run on already-uncacheable, authenticated or
request-scoped responses that ADR-0024 never governed in the first place).

## Product semantics frozen for this milestone

**Precedence** (explicit beats automatic; account beats anonymous-persistent beats
browser beats geo beats default; explicit URL always wins for its own request):

1. Explicit URL/prefix for the current request — unchanged, existing `Router`/`LanguageResolver`. **This always wins for the request it names**, even against a conflicting cookie (see "Explicit URL always wins" below).
2. Logged-in user's explicit account preference (`aiml_preferred_language`) — unchanged existing redirect-on-unprefixed-URL behavior (`Router::maybe_redirect_to_authenticated_preference`).
3. Explicit anonymous visitor cookie (`aiml_visitor_lang`) — new.
4. Browser language (`navigator.languages`/`navigator.language`) — new, suggestion only, never auto-redirect.
5. Geo (Universal Geo Context REST endpoint) — new, suggestion only, lower priority than browser, only if browser produced no match.
6. Site default language — unchanged, final fallback.

**Explicit vs automatic**: a language becomes "explicit" only via a switcher/floating-
selector click, or accepting a suggestion banner, or being synced down from an
explicit account preference. Raw browser/geo signals are never written to the cookie
or to user meta — they only drive the suggestion banner.

## Cookie write contract (resolves the write-path ambiguity from the first draft)

- **Writes** — only two anonymous-side triggers ever write `aiml_visitor_lang`, both
  **only via `document.cookie` in JavaScript**: an explicit selector click, and
  accepting a browser/geo suggestion (which is itself an explicit choice). Both
  refresh `Max-Age` since they represent a genuine new explicit selection.
- **Reads, no write** — the cookie-driven redirect (an unprefixed/default-URL visitor
  with an existing valid cookie navigating to their canonical language URL) only
  *reads* `aiml_visitor_lang` client-side to decide where to navigate. Plain
  navigation, on its own, never rewrites or refreshes the cookie — only an actual new
  explicit selection or an authoritative account-preference sync (see below) does.
- No PHP code path reachable from an anonymous page render may call `setcookie()` or
  read `$_COOKIE` for language purposes. This is what keeps the HTML response
  byte-identical for the cache.
- **Authenticated login/registration lifecycle synchronization** (server-side, never
  part of the anonymous cache — these are POST/redirect responses tied to a specific
  authenticated or in-progress-auth request) **may use PHP `setcookie()`** where that
  is the cleanest implementation (e.g. re-issuing the cookie to match a
  newly-effective account preference at login), but **only** inside the new, narrowly
  scoped `src/User/VisitorLanguageCookie.php` class, called only from `wp_login` and
  `user_register` hook handlers. This is a one-shot reissue at the login/registration
  event itself, not a continuous sync — see "Account preference remains authoritative" below.
- **No other PHP path — `Router`, `LanguageResolver`, `LanguageContext`, `Switcher`,
  `FloatingSelector`, or anything hooked before/at anonymous `template_redirect` —
  may read or write this cookie.** This is enforced by a narrow, explicit allowlist
  exception in `PluginGuardTest::test_no_cookie_is_set` naming exactly
  `src/User/VisitorLanguageCookie.php`, mirroring the existing allowlist pattern
  already used by `test_no_rest_routes_are_registered` in the same test class. A
  companion integration test proves the anonymous request path never reaches this class.

## Cookie lifecycle

- Name: `aiml_visitor_lang`. Value: bare enabled language code (e.g. `sv`).
- Attributes: `Path=/`, `Max-Age=31536000` (1 year), `SameSite=Lax`, `Secure`. Not
  `HttpOnly` (must stay JS-readable for the client-side redirect/write logic).
- **Domain**: omitted entirely. Per RFC 6265, an omitted `Domain` produces a
  **host-only cookie** scoped to whichever exact host served the response. This is a
  generic architectural property, not an environment-specific rule — DEV and PROD
  naturally end up with independent cookies simply because they are different hosts,
  with no hostname hard-coded anywhere in the implementation.
- Validated against the current enabled+published language list both client-side
  (small JSON list embedded in the page) and server-side (the one narrow PHP path in
  `VisitorLanguageCookie`, against `Languages::routable()`). Malformed/unsupported
  values are treated as absent — never redirect, never sync, never throw.
- Separate small marker for suggestion-dismissal (`aiml_visitor_lang_suggest_dismissed`,
  cookie or `localStorage`, ~30-day expiry, also client-side only) so a dismissed
  banner doesn't nag every visit.

**Secure attribute across environments**: `dev.biopentra.eu` and production are both
HTTPS-only (SWAG terminates TLS; the proxy's port-80 server block is a permanent
301-to-HTTPS redirect — confirmed in `proxy/config/nginx/site-confs/default.conf`), so
the cookie carries `Secure` unconditionally in both real environments — this is not
weakened. For the Playwright acceptance suite, tests run against the real
`https://dev.biopentra.eu` origin (the existing `acceptance/*-browser` suites already
do this, per `helpers/wp.ts` patterns seen in the floating-selector suite), so a
`Secure` cookie is storable exactly as in production; no HTTP test harness is used,
and no relaxation of the `Secure` attribute is introduced for testability.

## User-meta synchronization rules

- **Registration** (`user_register`): if the new account has no existing
  `aiml_preferred_language` and a valid explicit visitor cookie is present, seed the
  account preference from it via the existing `aiml_set_preferred_language()`. Never
  overwrites a value some other flow already set.
- **Login** (`wp_login`): existing explicit account preference always wins if present.
  If the account has none yet, seed it from the visitor cookie (same rule as
  registration). Either way, `VisitorLanguageCookie` re-issues the cookie (via
  `setcookie()`, per the write contract above) to match the now-effective account
  preference.
- **Logout** (`wp_logout`): cookie is left untouched (not cleared). The visitor's
  explicit choice remains theirs after logout — deterministic, documented behavior,
  avoids ping-pong between cookie and account state.
- **Manual change while logged in**: unchanged existing switcher/floating-selector
  navigation + existing authenticated `wp_ajax_aiml_floating_selector_prefer` persist
  path; additionally the new client-side click handler updates the visitor cookie too
  (whether authenticated or not, via `document.cookie`), keeping cookie and account
  preference consistent from the client side as well.

## Explicit URL always wins (frozen invariant, applies to WP3 and its tests)

A visitor cookie may trigger an automatic client-side navigation **only** when the
current request has no explicit language in the URL, i.e. the visitor is on the
unprefixed/default-language URL. An explicitly-prefixed URL is authoritative for that
request and is never fought by the cookie.

```
cookie = sv, request = /product/foo        → client MAY navigate to the canonical Swedish URL
cookie = sv, request = /de/product/foo     → client MUST NOT redirect to Swedish; /de/ wins
```

If the visitor deliberately clicks a selector link to a different language (e.g. from
`/de/...` to `/sv/...` while the cookie says something else), that click is itself an
explicit choice: it updates the cookie to the newly clicked language and that becomes
the future preference. Covered by a new acceptance test (see Test strategy).

## Account preference remains authoritative if the cookie is missing or stale

For an authenticated user with a valid explicit `aiml_preferred_language`, that
account preference remains authoritative regardless of the visitor cookie's state —
absent, expired, containing a different valid language, or malformed. This is already
true today (`Router::maybe_redirect_to_authenticated_preference` consults user meta
only, never the cookie) and nothing in this milestone changes it: **no PHP/server-side
anonymous-render code path is ever given a reason to read `aiml_visitor_lang`,
authenticated or not** (the approved client-side JS does read the cookie after page
load, per the write contract above — this invariant concerns only the server-side
render path that ADR-0024's cache contract governs).

The cookie is reissued to match the account preference exactly once, at the
`wp_login` event (via `VisitorLanguageCookie`, per the write contract above). This is
a one-shot repair that happens to naturally correct a missing/stale cookie as a
side effect of login — not a mechanism added to continuously reconcile the two. No
additional sync point (e.g. on every authenticated page load) is introduced; that
would add complexity for no behavioral benefit, since the account preference is
already authoritative on every request regardless of cookie state.

## Browser detection (terminology correction)

The client-side implementation does **not** inspect the HTTP `Accept-Language`
request header — that header is never read server-side for anonymous resolution
(that would itself violate the cache contract). It uses `navigator.languages`
(ordered by browser preference), falling back to `navigator.language`. For each
entry, normalize region forms (`sv-SE`, `de-DE`, `en-GB`, `en-US`): try an exact code
match against enabled+published languages first, then the base subtag. First match
that isn't the current URL's language wins. **Documented, accepted deviation**: true
Accept-Language q-value weighting is not available to client-side JS (only
`navigator.languages`'s ordering, no quality values) — an explicit trade-off of doing
detection client-side to preserve cache safety, not a silent omission. Acceptance
tests and manual verification must describe this as "mock/emulate `navigator.language`
/ `navigator.languages`", never as "spoof `Accept-Language`" — the latter only applies
if a test is actually validating server HTTP behavior, which none of these do.

## Geo integration contract

- Client-side `fetch('/wp-json/universal-geo-context/v1/context')` only — the public,
  anonymous, `Cache-Control: no-store` REST endpoint Universal Geo Context already
  ships specifically for cache-safe consumption from cached pages. Never a PHP-level
  `universal_geo_get_country_code()` call during anonymous render.
- Called only when: geo fallback setting is on AND browser detection produced no match
  AND the request completes within a short timeout (~1.5s). Any failure/absence
  (plugin inactive, route 404s, timeout) silently degrades to default — genuinely zero
  dependency at the PHP level.
- Country→language mapping is entirely admin-owned: new `geo_language_map` setting
  (assoc array `{ "SE": "sv" }`), no shipped defaults, so ambiguous countries
  (CH/BE/FI/...) simply have no automatic behavior unless an admin explicitly adds them.

## Existing selector ownership (no competing selector)

Inspected `src/Frontend/FloatingSelector.php`, `assets/frontend/floating-selector.js`,
and `src/Frontend/Switcher.php` directly. Findings:

- **Enable/disable**: `Settings::floating_selector_enabled()` — already sufficient, reused as-is.
- **Placement**: `floating_selector_side()`/`_vertical()`/`_preset()` — already sufficient, reused as-is.
- **Language list/state**: sourced from `Switcher::all_language_links()`, with correct
  `is-current`/`aria-current` handling — already sufficient, reused as-is.
- **Desktop/mobile**: `floating_selector_show_desktop()`/`_show_mobile()`, CSS-only
  viewport hiding — already sufficient, reused as-is.
- **Accessible language links**: semantic `<nav>`/`<ul>`, `aria-expanded`,
  `aria-controls`, `hreflang`/`lang`, keyboard `Escape` + outside-click close,
  `data-aiml-code` on each link — already sufficient, reused as-is.

**Conclusion: no new selector is created.** `FloatingSelector` remains the one
visitor-facing language-switch control Universal Multilingual owns; this milestone
does not add a second one.

**One small, additive markup extension is required**, not a settings extension: the
`[aiml_switcher]` shortcode output (`Switcher::shortcode()`/`menu_items()` in
`src/Frontend/Switcher.php`) currently emits `<a href hreflang lang>` **without** a
`data-aiml-code` attribute, unlike `FloatingSelector`'s links. To let one shared
click-handler write the cookie from *any* language link on the page (shortcode, nav
menu, or floating selector) without guessing a code from `hreflang`, WP3 adds
`data-aiml-code="%s"` to the `[aiml_switcher]`/menu link markup, mirroring the
convention `FloatingSelector` already established. This is a pure additive
attribute — no behavior, rendering, or existing test changes — not a new selector.

The suggestion banner (browser/geo) remains a **separate, new, small UI element**
from the actual selector, exactly as before — it only ever proposes accepting a
switch (which navigates through the existing selector link model) or dismissing.

## Cache strategy

Anonymous HTML response for a given URL is unchanged, byte-for-byte, regardless of
cookie/browser/geo state — confirmed both at the plugin level (no PHP touches
`$_COOKIE`/`setcookie()` on the anonymous path) and at the proxy level (audit above:
the new cookie does not trigger `$bp_skip_cookie`, so caching for returning anonymous
visitors is unaffected). No `Vary` header changes, no proxy config changes. New guard
tests prove the new login/registration/logout-sync PHP code is unreachable from any
anonymous/pre-`template_redirect` code path.

## SEO

No changes to `LanguageRelationshipService`/hreflang/canonical — unaffected, since
resolution and URLs are untouched. The banner-not-redirect decision removes the risk
of a crawler being client-redirected off a URL based on a geo guess.

## Settings / admin changes

Bump `Settings::SCHEMA_VERSION` 3→4 (additive, existing `??`-guarded pattern — no
migration/table changes, no `Migrator::TARGET` bump). New keys, all safe-by-default
for existing installs:

- `visitor_autodetect_enabled` (master switch for browser/geo suggestion, default `false`)
- `visitor_autodetect_browser_enabled` (default `true` once master is on)
- `visitor_autodetect_geo_enabled` (default `false` — explicit opt-in even with master on)
- `visitor_cookie_persist_enabled` (default **`true`** — see below)
- `geo_language_map` (default `[]`)

**`visitor_cookie_persist_enabled` defaults to `true` deliberately**, and this is
intentional new behavior on upgrade for every existing installation: when a visitor
deliberately clicks an existing Universal Multilingual language selector
(`[aiml_switcher]` or `FloatingSelector`), that explicit choice may now be persisted
to `aiml_visitor_lang` so it survives future visits. This is the entire point of the
milestone — persistence of an *already-explicit* human action — and gating it behind
an extra opt-in would ship a "language selector" that still forgets the visitor's
choice by default, defeating the product goal. Nothing about this default causes any
*automatic* (browser/geo) behavior: `visitor_autodetect_enabled` stays `false` by
default and gates both browser and geo suggestions independently. No automatic
browser/geo behavior ever occurs merely because the plugin was upgraded — only the
consequence of a visitor's own deliberate click changes.

No new settings are added for the selector itself (see "Existing selector ownership"
above — it is already fully configurable). New section on the existing
Settings/Languages admin screens (not a new page) for the five keys above, following
existing checkbox/validation conventions in `src/Admin/SettingsPage.php` and
`src/Admin/Languages/`. Degrades cleanly (disables/greys geo controls, explanatory
notice) when Universal Geo Context isn't detected active.

## Migration / backward compatibility

No DB schema changes. **Not** a claim of zero behavior change on upgrade — stated
precisely instead:

- Existing translations, routing, URL structure, rendering, SEO (hreflang/canonical),
  account preferences (`aiml_preferred_language`), and cache behavior are all
  **unchanged** by this milestone, for every installation, regardless of settings.
- What *does* change by default: an anonymous visitor's own deliberate click on an
  existing `[aiml_switcher]`/`FloatingSelector` link now persists (via
  `visitor_cookie_persist_enabled` defaulting to `true`) and can drive a one-time
  client-side redirect back to that language on a later visit to the unprefixed URL.
  This only ever happens as the direct consequence of a visitor's own prior explicit
  action — an installation that receives no visitor clicks behaves identically to
  before the upgrade.
- Automatic (browser/geo) suggestion behavior is fully opt-in
  (`visitor_autodetect_enabled` defaults `false`) and never activates on upgrade alone.

## Security / privacy

Cookie stores only a short language code — no fingerprinting, no analytics. Geo call
reads only `country_code` via the geo plugin's own no-store, anonymous endpoint.
Cookie value is always validated against the enabled-language allowlist before use, in
both JS and `VisitorLanguageCookie`.

**Registration security contract** (corrected): `user_register` can fire during an
anonymous registration request, so the cookie value reaching it must be treated as
**untrusted input**, not as coming from "the user's own authenticated request." The
actual contract:
- The cookie value is normalized and validated against `Languages::routable()` before
  any use; invalid/malformed values are discarded silently.
- It may only ever initialize the preference of **the specific account being created
  in that same request** — it never targets, looks up, or affects any other user.
- It never overwrites an existing, stronger explicit account preference (registration
  only *seeds* an empty preference).
- No general anonymous state-mutation endpoint is introduced by this feature; the only
  write is the existing WordPress registration flow adopting one additional, validated
  piece of data from a cookie the same browser already holds, scoped to the account it
  is itself creating.

## Test strategy

- **Unit**: cookie/country-code validation helpers, `Settings::sanitize()` additions,
  country-map validation, browser-language matching algorithm (pure JS logic, unit
  tested at the JS level if the repo gains a JS test runner for it, otherwise covered
  via the acceptance suite).
- **Integration (PHPUnit+WP)**: registration inherits cookie preference (valid case);
  registration ignores a malformed/unsupported cookie value; login syncs when no
  existing preference; login preserves a stronger existing preference; logout leaves
  cookie untouched; new/extended guard test proving the anonymous request path never
  touches `$_COOKIE`/`setcookie()` outside the one explicitly allow-listed
  `VisitorLanguageCookie` class (mirrors the existing allowlist pattern in
  `PluginGuardTest::test_no_rest_routes_are_registered`).
- **Acceptance/browser (Playwright)**: new `acceptance/visitor-language-browser/`
  directory following the established per-dir `package.json` + `tests/` +
  `helpers/wp.ts` shape (`acceptance/floating-selector-browser/` is the closest
  template, run against the real HTTPS `dev.biopentra.eu` origin so `Secure` cookies
  behave exactly as in production). Covers:
  - explicit prefixed URL beats a conflicting visitor cookie (`/de/...` with
    `aiml_visitor_lang=sv` renders/stays German, no redirect)
  - cookie-driven redirect fires only from the unprefixed/default URL
  - clicking a selector link changes the explicit cookie to the newly chosen language
  - host-only cookie behavior (cookie set on `dev.biopentra.eu` is not sent to another host)
  - malformed/unsupported cookie value never triggers a redirect
  - cookie presence on a request does not disable/bypass the intended proxy caching
    behavior (asserted via `X-BP-Cache` response header, which the proxy already
    exposes, per `add_header X-BP-Cache $upstream_cache_status always;`)
  - anonymous HTML for a given URL is identical (byte-for-byte where practical)
    regardless of cookie state, and no `Set-Cookie` response header ever appears on
    an anonymous render
  - suggestion banner shows/accepts/dismisses correctly, using mocked
    `navigator.language`/`navigator.languages` (never described as Accept-Language spoofing)
  - WooCommerce cart persists across an explicit language switch
  - keyboard/screen-reader basics reusing the floating selector's existing accessible markup

## Work packages

1. **WP0 — Freeze**: ADR-0035 (reopens ADR-0024, records the proxy audit finding and
   the client-side-only resolution), this plan doc committed under `docs/plans/`,
   exact scope of the `PluginGuardTest` exception written down.
2. **WP1 — Settings**: schema bump, new keys, sanitize, admin UI (autodetect toggles +
   geo country-map editor) on existing screens.
3. **WP2 — Server-side sync**: new `src/User/VisitorLanguageCookie.php` (validate,
   read, and — only here — write via `setcookie()`), `wp_login`/`user_register` hooks,
   `wp_logout` no-op documented, narrow `PluginGuardTest` allowlist exception + new
   integration/guard tests.
4. **WP3 — Client cookie + redirect + markup**: add `data-aiml-code` to
   `[aiml_switcher]`/menu link markup (mirroring `FloatingSelector`); shared
   click-to-persist handler (`document.cookie` write + `Max-Age` refresh,
   non-blocking) on all `[data-aiml-code]` links, gated by
   `visitor_cookie_persist_enabled`; on load, if an explicit cookie differs from the
   current URL's language **and the current URL carries no explicit language
   prefix**, a one-time session-guarded client redirect to the prefixed URL — this
   redirect path only *reads* the cookie and never rewrites it.
5. **WP4 — Suggestion banner**: browser-language matching + geo REST fetch + banner
   UI/CSS + dismissal persistence, all gated by WP1's settings.
6. **WP5 — Test suite + docs**: new acceptance/browser directory, full
   `composer phpcs && composer test:unit && composer test:integration` run, user-manual
   update, closure doc.

## Acceptance criteria

- Anonymous visitors can explicitly select a language; selection persists (cookie)
  across future visits and beats browser/geo on return, but never fights an explicit
  URL for the request that names it.
- Automatic browser/geo signals only ever produce a dismissible suggestion, never a
  silent redirect or a silently-created "explicit" preference.
- Logged-in account preferences continue to work exactly as today; login/registration
  sync rules above hold; logout is deterministic (cookie retained).
- Geo fallback works only when both the master switch and geo switch are on, the geo
  plugin/route is reachable, and an admin-configured country mapping exists; is fully
  optional and has zero hard dependency on the geo plugin.
- No WooCommerce regression (cart/checkout/login/registration/AJAX fragments unaffected).
- **No cache cross-language leakage**: anonymous HTML for a given URL is provably
  identical regardless of visitor cookie state; cookie presence does not disable the
  proxy's full-page caching (verified against the real proxy config and `X-BP-Cache`).
- No redirect loops (session-guarded, reuses existing loop-guard patterns in `Router`).
- The existing `FloatingSelector`/`[aiml_switcher]` remain the sole selector UI; no
  competing selector is introduced.
- Admin settings are self-explanatory, degrade cleanly without the geo plugin.
- Existing installations migrate with translations/routing/URLs/SEO/cache/account
  preferences unchanged; only a visitor's own deliberate selector click gains
  persistence by default (per "Migration / backward compatibility" above), with no
  automatic browser/geo behavior until an admin opts in.
- `composer phpcs`, `test:unit`, `test:integration`, and the new acceptance suite all pass.
- ADR-0035 + updated docs/user-manual reflect final behavior; working tree contains
  only intended changes.

## Key risks

- **Flash of default language** before client-side redirect fires, for visitors with
  an explicit cookie landing on the unprefixed default URL — the direct, accepted cost
  of keeping the proxy cache untouched (a proxy cache-key change was explicitly
  declined). Documented in ADR-0035 as a known trade-off.
- **Guard-test exception scope creep** — mitigated by allow-listing exactly one new
  file path in `PluginGuardTest::test_no_cookie_is_set`, mirroring the existing
  allowlist pattern in the same test class.
- **Geo endpoint latency/unavailability** — mitigated by short timeout + silent skip.
- **Repeated banner nagging** — mitigated by the ~30-day dismissal marker.

## Verification

After implementation: run `composer phpcs`, `composer test:unit`, `composer
test:integration` (via the Docker one-liners in `CLAUDE.local.md`), then the new
`acceptance/visitor-language-browser/` Playwright suite against `dev.biopentra.eu`,
then a manual pass confirming: explicit switch persists across a reload with cache
cleared/warm (`curl -sI` shows `X-BP-Cache: HIT` unaffected by the cookie), cookie
never appears in `Set-Cookie` response headers on an anonymous render, an explicit
`/de/...` URL is never overridden by a conflicting cookie, the suggestion banner
appears for a mocked `navigator.languages`/mocked geo response and behaves as
designed, and existing `[aiml_switcher]`/`FloatingSelector`/WooCommerce flows are unaffected.
