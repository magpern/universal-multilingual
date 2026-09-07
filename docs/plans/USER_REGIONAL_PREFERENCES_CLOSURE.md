# USER REGIONAL PREFERENCES — Milestone Closure

**Verdict:** CLOSED — PASS  
**Date:** 2026-09-07  
**Canonical plan:** [USER_REGIONAL_PREFERENCES_IMPLEMENTATION_PLAN.md](USER_REGIONAL_PREFERENCES_IMPLEMENTATION_PLAN.md)

## Baselines → finals

| | UML | UMC |
|---|---|---|
| Start `origin/main` | `cc4c8c012` (1.11.1, TARGET 8) | `fec5ec356` (1.2.1, schema 8, inventory 11) |
| Frozen plan commit | `a6811b615` | (references UML plan) |
| Feature branch | `feature/user-regional-preferences` | `feature/user-regional-preferences` |
| PR | [#61](https://github.com/magpern/universal-multilingual/pull/61) | [#33](https://github.com/magpern/universal-multicurrency/pull/33) |
| Merge SHA | `9f566cd25` | `024992b50` |
| Final main | `9f566cd25` | `024992b50` |
| Version (unchanged) | 1.11.1 | 1.2.1 |
| Schema / TARGET / inventory | TARGET **8** | schema **8**, inventory **12** |

## Contracts delivered

| Item | Result |
|---|---|
| Meta keys | `aiml_preferred_language`, `umc_preferred_currency` |
| UML APIs | `aiml_get/set_preferred_language`, `aiml_get_preferred_language_state` |
| UMC APIs | `umc_get/set_preferred_currency`, `umc_get_preferred_currency_state` |
| Composition | `um_regional_preferences_{render,save,rendered,save_started,claimed_slots}` |
| Host | UML prio 10 / UMC prio 20; render ≠ write |
| Language runtime | Storage+API+UI only; Router/LanguageResolver untouched |
| Site-default fallback | `WPLANG` → empty `en_US` |
| Currency precedence | explicit > session > cookie > user_preferred > base |
| Geo | Writer; checkout re-eval cannot overwrite bare user_preferred |
| Migration | **NONE** |
| Third plugin | **NONE** |
| Release / production | **NOT performed / UNTOUCHED** |

## Independent review

Initial FAIL for checkout geo overwriting user preference + empty Account fieldset. Fixed before merge (`allows_checkout_geo_reevaluation`, fieldset guard). Re-verified locally.

## DEV acceptance (`dev.biopentra.eu`)

Bind-mounted feature/main code exercised via WP-CLI:

- Both public APIs present and functional
- Save/get language + currency for customer user 98
- Composition hooks registered
- Admin editing other user leaves admin WC session unchanged
- Profile render: exactly one “Regional preferences” section with language + currency
- Physical UML/UMC deactivate matrix: bounded via integration tests (not run live to avoid shared-DEV disruption)

## Residual / deferred

- Floating language/currency selectors
- Login / default-entry language redirects
- CE7/CE8 consuming preferred language
- Plugin version bumps / tags / GitHub Releases

## Production / release

**Production untouched. Release not performed.**
