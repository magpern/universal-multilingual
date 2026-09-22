# Visitor Language Selection — Closure

| # | Field | Value |
|---|---|---|
| 1 | Authoritative plan | [VISITOR_LANGUAGE_SELECTION_IMPLEMENTATION_PLAN.md](VISITOR_LANGUAGE_SELECTION_IMPLEMENTATION_PLAN.md) |
| 2 | Architecture ADR | [ADR-0035](../adr/0035-anonymous-visitor-language-persistence-and-detection.md) — Accepted |
| 3 | Reopened contract | ADR-0024 (anonymous-language cache contract) — resolved via its own sanctioned option (a): client-side-only anonymous behavior; server-side resolution unchanged |
| 4 | Feature branch | `feature/visitor-language-selection` (deleted post-merge) |
| 5 | Feature PR | https://github.com/magpern/universal-multilingual/pull/70 |
| 6 | Feature merge | `a6a16c08372f07342e6bcf11d01874636b302513` |
| 7 | Settings schema | `Settings::SCHEMA_VERSION` 3 → 4 (additive; no `Migrator::TARGET` change; no DB schema change) |
| 8 | PluginGuard | `PluginGuardTest::test_no_cookie_is_set` — one narrow, named exception for `src/User/VisitorLanguageCookie.php` |
| 9 | Regression | `tests/integration/VisitorLanguageCookieTest.php`; extended `tests/unit/SettingsSanitizeTest.php` |
| 10 | Browser acceptance | `acceptance/visitor-language-browser/` — 20 tests |
| 11 | Acceptance result | desktop-1440 20/20, tablet-768 20/20, mobile-375-chromium 20/20 (all re-confirmed live on DEV post-merge) |
| 12 | WebKit (mobile-375) | **Blocked** — missing system shared libraries, no passwordless sudo in the sandbox used for this work. Environment-specific; not a mobile-UI implementation gap (Chromium mobile coverage is green). |
| 13 | Independent reviews | 4 focused/broad Opus review rounds across the milestone; all BLOCKER/MAJOR findings fixed and re-verified |
| 14 | Post-merge CI (main) | GREEN — build, integration, phpcs, quality, unit all pass on `a6a16c083` |
| 15 | Post-merge DEV verification | PASS — see below |
| 16 | Version bump | **Not performed** — this repo's convention bumps `Version:`/CHANGELOG on the feature branch itself before merge; this branch did not, and none was invented for closure per explicit instruction. Pending a deliberate release decision, not part of development completion. |
| 17 | Tag / GitHub Release | **Not performed** |
| 18 | PRODUCTION DEPLOYMENT | **Not performed** — DEV-only, per instruction |
| 19 | DEV canonical mount | `/opt/biopentra/dev/universal-multilingual`, restored to `main` after verification |

## Scope

Anonymous, not-logged-in visitors can now have a language choice remembered
across visits, and can optionally be offered a browser/location-based
suggestion — while anonymous server-side rendering stays a pure function of
`host + request_uri` for a given URL, exactly as ADR-0024 requires. No
competing selector was introduced; the existing `FloatingSelector` and
`[aiml_switcher]` remain the sole visitor-facing language controls.

## Architecture (ADR-0035)

- **Precedence**: explicit URL prefix → account preference (signed-in) →
  explicit `aiml_visitor_lang` cookie → browser suggestion → geo suggestion →
  site default. An explicit URL always wins for the request that names it.
- **Cache-safe client-side model**: all "automatic" anonymous behavior
  (cookie read/write, browser detection, geo fallback, the cookie-driven
  redirect) runs in a static JS bundle (`assets/frontend/visitor-language.js`)
  that acts only after the (unchanged, cached) HTML has already loaded. No
  PHP path reachable from an anonymous render ever reads or writes the
  cookie. Verified directly against the live reverse-proxy config and
  confirmed live (`X-BP-Cache: HIT` regardless of cookie presence; no
  anonymous `Set-Cookie`).
- **Anonymous cookie persistence**: an explicit selector click may be
  remembered (`visitor_cookie_persist_enabled`, default on); a later visit to
  the unprefixed/default URL client-side-redirects to the remembered
  language, without rewriting the cookie on mere navigation. Loop-freedom
  rests on structural per-invocation invariants only (no persisted
  "already redirected" flag survived review — two were tried and removed for
  two different failure modes before landing on this).
- **Account synchronization**: `src/User/VisitorLanguageCookie.php` is the
  one narrowly-scoped PHP path allowed to touch this cookie, wired only to
  `wp_login`/`user_register` (never an anonymous render path). Registration
  seeds an empty account preference from a validated, untrusted cookie value,
  scoped to a genuinely anonymous self-registration only; login lets an
  existing preference win and reissues the cookie to match; logout leaves the
  cookie untouched.
- **Browser suggestion**: `navigator.languages`/`navigator.language` only
  (never server-side `Accept-Language`), gated by `visitor_autodetect_enabled`
  (default off) and `visitor_autodetect_browser_enabled` (default on once the
  master is on). Suggestion only — never a silent switch.
- **Geo fallback**: client-side fetch to Universal Geo Context's public REST
  endpoint only, no PHP-level dependency, gated by
  `visitor_autodetect_geo_enabled` (default off) and an admin-owned
  `geo_language_map` with no shipped defaults.
- **Selector reuse**: `FloatingSelector`/`[aiml_switcher]` unchanged in
  behavior; only an additive `data-aiml-code` attribute on switcher/menu
  links so one shared click handler works everywhere a language link appears.

## Major review findings fixed

1. Login/registration sync was dead code (`wp_login`/`user_register` fire
   before WordPress establishes a current user, so `PreferredLanguage`'s
   permission checks always failed) — fixed with a scoped, always-restored
   current-user bridge.
2. The one path that did fire used the wrong actor's cookie, inverting the
   account-isolation contract — fixed with an explicit anonymous-actor guard.
3. The cookie-driven redirect dropped the current URL's query string/hash —
   fixed; preserved losslessly.
4. A destination-keyed redirect-loop guard (added to hedge a MINOR finding)
   itself had a persistence bug, suppressing legitimate later redirects to
   the same destination — removed; loop-freedom rests on structural
   invariants alone, with direct regression coverage for same-URL revisits
   and the browser-Back case.
5. Banner/chat-widget visual collision found via live DEV acceptance —
   repositioned.

## Test / acceptance status

- `composer phpcs`: 865 files, 0 errors.
- Unit: 1107 tests green.
- Integration: 1117 tests green.
- Acceptance: 20/20 on desktop-1440, tablet-768, mobile-375-chromium — all
  re-confirmed live against the merged `main` on DEV.
- CI on the merge commit (`a6a16c083`): all 5 required jobs green.

## Known limitations

- **WebKit (Safari engine)** mobile acceptance could not run in this sandbox
  (missing system libraries, no passwordless sudo). Chromium mobile-viewport
  coverage is green and independent of this gap. Safari/WebKit's ~7-day cap
  on script-set cookies (an accepted trade-off of the client-side-only
  design, documented in ADR-0035) has not been manually verified in a real
  Safari browser.
- No PO functional sign-off has been sought (a release step, not a
  development-completion requirement per this closure's instructions).

## Production status

**Not deployed.** DEV-only. No tag, no GitHub Release, no version bump.
Release is a separate, explicitly-authorized step.
