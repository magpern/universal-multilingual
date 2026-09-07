<?php
/**
 * ADR-0026 — Authenticated preferred language (storage + UI only)
 *
 * @package AIMultilingual
 */

## Status

Accepted.

## Context

ADR-0018 deferred non-order customer emails (CE7/CE8) until a deterministic
user-level language source exists. Operators also need a Regional Preferences
surface for logged-in users. ADR-0024 forbids anonymous same-URL language
personalization (cookies, Accept-Language, geo).

## Decision

Universal Multilingual owns `aiml_preferred_language` user meta (language
**code** only) with public helpers:

- `aiml_get_preferred_language`
- `aiml_get_preferred_language_state`
- `aiml_set_preferred_language`

v1 is **storage + API + profile/My Account UI only**. Preferred language MUST
NOT alter `LanguageResolver` / `Router` / URL-host authority. Explicit URL
language remains authoritative for render.

Regional Preferences composition uses shared action/filter **names** with
Universal Multicurrency (`um_regional_preferences_*`). UML hosts at priority 10.
UML owns language field render/save only; it never writes currency meta.

Site-default fallback when UML is absent uses WordPress `WPLANG` (empty →
`en_US`), never `get_locale()` / `determine_locale()` / user locale.

## Consequences

- PluginGuard / RoutingTest continue to forbid anonymous language cookies and
  same-URL visitor-specific resolution.
- Future login redirects, default-entry navigation, floating selectors, and
  CE7/CE8 may consume this API under separate ADRs.
- Invalid stored codes are retained and fall back effectively.
