# ADR-0029 — Extended language URL-code grammar

**Status:** Accepted

## Context

Today a language's URL code — the first path segment, `/sv/…` — is validated by
`Languages::is_valid_code()` as `^[a-z]{2}(-[a-z]{2})?$`: a two-letter language,
optionally a two-letter region. That was sufficient while languages were added
by hand one region at a time.

ADR-0028 makes the plugin ship a curated registry of the full
WordPress-recognized locale set, including explicit formal / script /
orthography variants that WordPress itself treats as distinct runtime locales
(`de_DE_formal`, `pt_PT_ao90`). The two-segment grammar cannot represent these:

- `de_DE` and `de_DE_formal` on the same site would collide — the second would
  fall back to `de-de`, and a third same-region variant would have nowhere
  deterministic to go.
- `/de-de/` would not say *which* variant it routes.

The routing identifier must be at least as expressive as the registry it is
derived from.

## Decision

### Grammar

`Languages::is_valid_code()` accepts:

```
^[a-z]{2,3}(-[a-z]{2}(-[a-z0-9]+)?)?$
```

— `language` / `language-region` / `language-region-variant`, where `language`
is the registry `language_code` (2 **or** 3 lowercase ASCII letters), `region`
is a two-letter lowercased ISO-3166 alpha-2, and `variant` is the lowercased
trailing WordPress locale segment. Examples: `sv`, `pt-br`, `ceb`,
`de-de-formal`, `pt-pt-ao90`.

### Derivation (at creation only, then immutable)

`DerivedLanguageMetadata::derive()` parses the WordPress locale into
`language_code`, optional `region`, optional explicit `variant`, then:

- **Ordinary locale or plain regional variant:** first free candidate among the
  existing language identities (ignoring the row being edited) —
  (a) `language`, (b) `language-region`. `sv_SE` → `sv`; `pt_PT` → `pt`; then
  `pt_BR` → `pt-br` because `pt` is taken.
- **Explicit variant attached to a region:** the code is **fixed** at
  `language-region-variant` and is never shortened, even when the bare or
  `language-region` form is free. `de_DE_formal` → `de-de-formal`;
  `pt_PT_ao90` → `pt-pt-ao90`. Creation order therefore does not matter:
  adding `de_DE_formal` first yields `de-de-formal` (not `de`), and a later
  `de_DE` still gets `de`.
- **Variant-only locale with no region** that cannot fit the grammar
  (`art_xemoji`, `art_xpirate`): not first-class. The registry generator drops
  it; it is reachable only through custom mode.
- No numeric suffixing. If the deterministic code is already taken →
  `WP_Error('aiml_conflicting_variant', …)` naming the holder and whether it is
  the canonical same-group language or an unrelated row.

The code is derived once, at creation, and is immutable thereafter (ADR-0028).

### Storage

`aiml_languages.code` is widened `VARCHAR(12)` → `VARCHAR(20)` (matching
`locale`) under migration step 9, alongside the `UNIQUE KEY locale` from
ADR-0028.

### Routing is unchanged in principle

`LanguageResolver` already matches the first path segment as an opaque
lowercased string against each `$language->code`, so a three-segment code
routes with no resolver change. Anonymous resolution stays URL-authoritative —
`host + request_uri` alone decides the language, no cookie / `Accept-Language` /
geo (invariant 11, ADR-0024). The default language still owns the unprefixed
root.

## Consequences

- The routing contract documented in the `is_valid_code()` docblock and the Add
  form help text changes; both are updated.
- `RoutingTest` gains a three-segment-code case (`/de-de-formal/` resolves and
  strips correctly).
- Existing stored codes (all two-segment) remain valid under the wider grammar;
  no data migration of `code` values is needed beyond the column widening.
- Custom-mode rows may set any grammar-valid code by hand; the deterministic
  rule above governs only curated derivation.

See also: ADR-0028 (curated locale registry), ADR-0024 (anonymous cache
contract), ADR-0008 (language state model).
