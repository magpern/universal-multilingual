# USER REGIONAL PREFERENCES — Implementation Plan (FROZEN)

**Status:** FROZEN for implementation  
**External review:** PASS — READY TO FREEZE (STATE A)  
**Canonical authority:** this document in `magpern/universal-multilingual`  
**Peer reference:** UMC documents this path; UMC does not keep a second editable copy (`docs/plans/` is non-shipping there).

## Reconciled baselines (implementation start)

| Repo | `origin/main` SHA | Version | Schema / TARGET / inventory |
|---|---|---|---|
| universal-multilingual | `cc4c8c012d987b09131e86962b9f5be4e753ea23` | 1.11.1 | `Migrator::TARGET` **8**; settings schema **1** |
| universal-multicurrency | `fec5ec3565f731bf1a94f103c71191bcc44370fc` | 1.2.1 | `Settings::SCHEMA_VERSION` **8** (planning saw 7; compatible drift); `PersistedKeys::INVENTORY_VERSION` **11** → bump to **12** |

UMC display edge-pill selector work does not conflict with preference storage/resolver extension.

## Ownership

| Preference | Owner | Meta key | Value |
|---|---|---|---|
| Language | UML | `aiml_preferred_language` | UML language **code** |
| Currency | UMC | `umc_preferred_currency` | ISO 4217 uppercase code |

No hard UML↔UMC dependency. No third plugin. No DB migration. No user backfill.

## Public APIs

UML (`src/Extension/functions.php`):

- `aiml_get_preferred_language( int $user_id ): ?string`
- `aiml_get_preferred_language_state( int $user_id ): ?array`
- `aiml_set_preferred_language( int $user_id, ?string $code ): true|\WP_Error`

UMC (`src/api.php`):

- `umc_get_preferred_currency( int $user_id ): ?string`
- `umc_get_preferred_currency_state( int $user_id ): ?array`
- `umc_set_preferred_currency( int $user_id, ?string $code ): true|\WP_Error`

State shape: `available`, `editable`, `stored`, `effective`, `source` (`stored`|`fallback_default`|`invalid_fallback`), `label`, `options`, `unavailable_message`.

## Language runtime (v1)

**Storage + API + UI only.** Do not change `LanguageResolver` / `Router`. ADR-0024 unchanged. No cookie, Accept-Language, geo language, login redirect, or same-URL personalization.

When UML available: empty/invalid preference → UML `Languages::default()`.  
When UML unavailable (host RO row): site `WPLANG` option; empty → `en_US`. **Forbidden:** `get_locale()`, `determine_locale()`, user `locale`.

## Currency precedence

```text
explicit > session > cookie > user_preferred > base
```

Session/cookie intentionally outrank stored preference until replaced.  
Save for **current shopper** → meta + existing `CurrencySwitcher::persist()`.  
Edit **other user** → target meta only; editor session/cookie unchanged.  
Clear preference → delete meta; do not clear active session/cookie.  
Geo remains a writer; valid `user_preferred` counts as shopper source for geo gates.

## Composition (render ≠ write)

Hooks (string-stable; no shared package):

- `um_regional_preferences_render`
- `um_regional_preferences_save`
- `um_regional_preferences_claimed_slots` (filter)
- `um_regional_preferences_rendered` (once-flag action)

Host (UML prio 10, UMC prio 20 fallback): section chrome, fire render/save, RO fallback for **unclaimed** slots only.  
Provider: claim slot, own markup/nonce/POST/save/meta. Degraded owner still claims and renders own RO row.

## Surfaces

- WP Profile / Edit User  
- WooCommerce My Account → Account details (no new endpoint)  
- Woo absent: UML profile/API still work; account hooks skip

## Presence matrix

A both · B UML only · C UMC only · D neither (no UI) · E degraded claims RO · F/G deactivate/reactivate retain meta · H invalid retained with effective fallback

## Security / cache / migration

Caps: self or `edit_user`. Provider nonces. Fixed meta keys. No REST v1.  
Anonymous language cache unchanged. No new anonymous currency personalization.  
**Migration: NONE.**

## STOP conditions

Third plugin; hard UML↔UMC dep; LanguageResolver/Router change; anonymous language personalization; second currency resolver; DB/backfill migration; public API break; REST-only save; mandatory new My Account endpoint; host reconstructing peer editable fields/saves.

## Release boundary

Implementation ≠ release. No tag/ZIP/production. Floating selectors deferred.

## Implementation ladder

RP.API → RP.RUNTIME (UMC) → RP.UI composition → RP.VERIFY
