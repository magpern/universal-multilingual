# ADR-0035 — Anonymous visitor language persistence and detection

**Status:** Accepted

## Context

Universal Multilingual has no way for an anonymous visitor to have an explicit
language choice remembered, or to be offered a better-matching language from
their browser or location. Only logged-in users get a persistent preference
(`aiml_preferred_language`, ADR-0026).

ADR-0024 ("Anonymous-language cache contract") requires that, for an anonymous
request, `host + request_uri` alone determine the rendered language — no
cookie, `Accept-Language`, or geo/IP signal may change resolution for the same
URL — because the production deployment sits behind a full-page reverse-proxy
cache keyed on `scheme | host | request_uri | currency_bucket`, with no
language dimension. ADR-0024 explicitly anticipates a milestone like this one
and requires reopening itself, resolving one of: (a) encode selection into the
URL only, (b) add a cache-key dimension in coordination with the reverse-proxy
owner, or (c) scope the feature to authenticated visitors only.

ADR-0027 similarly forbids a competing URL builder, anonymous cookie,
`Accept-Language`, or geo resolver inside the existing `FloatingSelector`.

**Reverse-proxy cookie audit** (performed before this ADR was written, against
the actual deployment's SWAG configuration — `proxy/config/nginx/site-confs/
biopentra-cache-request-eligibility.conf` and `default.conf`):

- The request-eligibility map `$bp_skip_cookie` (driven by `$http_cookie`,
  i.e. cookies the visitor's browser sends) matches only specific auth/session
  substrings: `wordpress_logged_in`, `wp_woocommerce_session`,
  `woocommerce_cart_hash`, `woocommerce_items_in_cart`, `comment_author`. It
  defaults to cache-eligible for anything else. A new `aiml_visitor_lang`
  cookie does not match any of these — **its presence on an incoming request
  does not disable full-page caching** for a returning anonymous visitor.
- `proxy_no_cache` already includes `$upstream_http_set_cookie` as a generic
  backstop: if the origin ever emitted `Set-Cookie` on a response, that
  specific response simply would not be *saved* to cache — fail-safe, not a
  leak. This is independent, pre-existing infrastructure; this milestone does
  not rely on it, because the design below never has PHP emit `Set-Cookie` on
  an anonymous render, but it corroborates that even an accidental violation
  would fail closed.
- **Finding: no reverse-proxy configuration change is required or made.**

## Decision

This milestone resolves ADR-0024's option (a): **anonymous language selection
is encoded entirely client-side**, never in server-rendered state. Server-side
anonymous resolution (`LanguageResolver`, `Router`) is byte-for-byte unchanged
by this milestone. All new "automatic" behavior — cookie read/write, browser
detection, geo fallback, the suggestion banner — runs in a static, equally
cacheable JS bundle (`assets/frontend/visitor-language.js`) that acts only
*after* the (unchanged, cached) HTML has already loaded.

### Precedence

1. Explicit URL/prefix for the current request (unchanged) — **always
   authoritative for the request it names**, even against a conflicting
   cookie.
2. Logged-in account preference (`aiml_preferred_language`, unchanged).
3. Explicit anonymous visitor cookie (`aiml_visitor_lang`, new).
4. Browser language (`navigator.languages`/`navigator.language`) — suggestion only.
5. Universal Geo Context fallback — suggestion only, lower priority than browser.
6. Site default language (unchanged, final fallback).

### Explicit vs automatic

A language becomes "explicit" only via a selector click, accepting a
suggestion (itself an explicit act), or an explicit account preference.
Browser/geo signals are never written to the cookie or user meta directly —
they only drive a dismissible suggestion banner. Automatic detection never
masquerades as an explicit choice.

### Cookie: `aiml_visitor_lang`

- Host-only (`Domain` omitted — scoped to whichever host serves the response;
  DEV and PROD are naturally independent as a result, with no hostname
  hard-coded anywhere), `Path=/`, `Max-Age=31536000`, `SameSite=Lax`,
  `Secure` (both DEV and PROD are HTTPS-only; the acceptance suite runs
  against the real HTTPS origin, so `Secure` is never relaxed for testability).
  Not `HttpOnly` — must stay JS-readable.
- Value: bare enabled/published language code, validated both client-side and
  server-side before use; malformed/unsupported values are treated as absent.

**Write/read boundary** (resolves an earlier draft's ambiguity):

- **Writes** happen only for an actual new explicit selection — a selector
  click, or accepting a suggestion — and only via `document.cookie` in JS.
  Both refresh `Max-Age`.
- The **cookie-driven redirect** (see below) only *reads* the cookie. Plain
  navigation never rewrites or refreshes it.
- **Authenticated login/registration synchronization** (`wp_login`,
  `user_register` — never part of the anonymous cache) may use PHP
  `setcookie()`, but **only** inside `src/User/VisitorLanguageCookie.php`, the
  one class this ADR allow-lists. No other PHP path — `Router`,
  `LanguageResolver`, `LanguageContext`, `Switcher`, `FloatingSelector`, or
  anything reachable from an anonymous render — may read or write this
  cookie. This is enforced by a narrow, explicit exception in
  `PluginGuardTest::test_no_cookie_is_set` naming exactly that one file.
- No PHP/server-side anonymous-render code path is ever given a reason to
  read `aiml_visitor_lang` — the approved client-side JS does read it, after
  page load; this invariant concerns only the server-side render path
  ADR-0024 governs.

### Explicit URL always wins

A visitor cookie may trigger a client-side redirect only when the current
request has no explicit language prefix — i.e. the visitor is on the
unprefixed/default-language URL. An explicitly-prefixed URL (`/de/...`) is
authoritative for that request and is never fought by the cookie. A
deliberate click to a different language updates the cookie and becomes the
new future preference.

### Account preference remains authoritative

For an authenticated user with a valid explicit `aiml_preferred_language`,
that preference remains authoritative regardless of the visitor cookie's
state — absent, expired, conflicting, or malformed
(`Router::maybe_redirect_to_authenticated_preference` already only consults
user meta, never the cookie, and this milestone does not change that). The
cookie is reissued to match the account preference exactly once, at
`wp_login` — a one-shot repair, not a continuous per-request sync.

### Registration and login synchronization

- **Registration** (`user_register`, which can be an anonymous request): a
  valid, explicit visitor cookie may seed an *empty* account preference for
  the account being created in that same request. The cookie value is
  untrusted input — normalized and validated against currently routable
  languages — and never targets any other account, never overwrites an
  existing stronger preference, and introduces no general anonymous
  state-mutation endpoint.
- **Login** (`wp_login`): an existing account preference always wins; if
  none exists yet, it is seeded from the cookie (same rule as registration).
  Either way the cookie is reissued to match the now-effective preference.
- **Logout** (`wp_logout`): the cookie is left untouched. The visitor's
  explicit choice remains theirs after logout — deterministic, avoids
  cookie/account ping-pong.

### Browser detection

`navigator.languages` (ordered), falling back to `navigator.language`. Exact
code match against enabled/published languages first, then base subtag
(`sv-SE` → `sv`). **Does not inspect the HTTP `Accept-Language` header** —
that header is never read server-side for anonymous resolution, which would
itself violate the cache contract. True q-value weighting is not available to
client-side JS; this is a documented, accepted trade-off of doing detection
client-side, not a silent omission.

### Geo integration

Client-side `fetch()` to Universal Geo Context's public, anonymous,
`Cache-Control: no-store` REST endpoint (`/wp-json/universal-geo-context/v1/
context`) only — never a PHP-level `universal_geo_get_country_code()` call
during anonymous render. Called only when geo is enabled, browser detection
produced no match, and completes within a short timeout; any failure/absence
silently degrades to default. Country→language mapping is entirely
admin-owned (`geo_language_map` setting), with no shipped defaults — ambiguous
countries (CH/BE/FI/...) get no automatic behavior unless an admin
explicitly configures them.

### Selector ownership

No new selector is introduced. `FloatingSelector` (ADR-0027) and
`[aiml_switcher]` remain the sole visitor-facing language-switch UI; their
existing settings (enable/disable, placement, desktop/mobile, language
list/state, accessible links) already cover this milestone's needs. The only
markup change is an additive `data-aiml-code` attribute on `[aiml_switcher]`/
nav-menu links (mirroring the convention `FloatingSelector` already uses), so
one shared click-handler can identify the selected language on any language
link on the page. The suggestion banner is a separate, new, small UI element.

### Defaults and backward compatibility

- `visitor_cookie_persist_enabled` defaults **true** — intentional new
  behavior on upgrade: a visitor's own deliberate selector click may now
  persist. This is the entire point of the milestone; gating it behind an
  extra opt-in would ship a selector that still forgets the choice by default.
- `visitor_autodetect_enabled` (and its browser/geo sub-flags) default
  **false** — automatic suggestions are opt-in and never activate merely
  because the plugin was upgraded.
- Existing translations, routing, URL structure, rendering, SEO, account
  preferences, and cache behavior are unchanged for every installation,
  regardless of settings. An installation that receives no visitor clicks
  behaves identically to before the upgrade.

### Known trade-off

A visitor with an explicit cookie landing on the unprefixed default URL may
briefly see the default-language page before the client-side redirect fires
(a "flash of default language"). This is the direct, accepted cost of keeping
the proxy cache untouched — the alternative (a proxy cache-key change) was
explicitly declined in favor of the smaller, fully plugin-scoped correction.

## Scope

This ADR only concerns anonymous language *selection and persistence*. It
does not reopen ADR-0026 (authenticated preference storage/UI), ADR-0002
(routing), ADR-0023 (localized URLs), or the SEO/hreflang model — all of
those continue to apply unchanged.

## Consequences

- `PluginGuardTest::test_no_cookie_is_set` gains one narrow, named file
  exception (`src/User/VisitorLanguageCookie.php`); every other file remains
  covered by the original blanket guard.
- `Settings::SCHEMA_VERSION` moves 3 → 4 (additive; no `Migrator::TARGET`
  change; no DB schema change).
- Future geo-plugin-absent, timeout, and malformed-response cases must
  degrade silently, per the existing "no hard dependency" convention this
  plugin already uses for optional integrations.
- If a future milestone needs true server-side anonymous personalization
  (e.g. to remove the flash-of-default-language trade-off), it must reopen
  this ADR and pursue ADR-0024's option (b) — a coordinated reverse-proxy
  cache-key change — which this milestone deliberately does not attempt.
