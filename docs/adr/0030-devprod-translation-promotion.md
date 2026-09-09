# ADR-0030 — DEV → PROD translation promotion

**Status:** Accepted

## Context

Universal Multilingual lets an operator create, machine-translate and human-review
translations on a DEV / staging site. There has been no product feature to move the
reviewed result to PROD. Doing it by hand is unsafe: translations live in
`aiml_translations` keyed by `(source_type, source_id, segment_hash, language_id)` where
`source_id` is a numeric WP post / term ID and `language_id` is a numeric FK — none of
which match across two independent installs. Copying `aiml_*` tables wholesale would import
another environment's routing projections, language IDs and stale baselines.

We want a first-class, versioned **export / import translation package** workflow:
create/review on DEV → export a package → import on PROD → strictly read-only dry-run that
classifies every incoming segment → explicit apply with apply-time revalidation →
idempotent and safe to re-run.

Translation Promotion is a **module inside** Universal Multilingual — same plugin, same
bootstrap, same migration system, same `Store` write paths, same admin menu, same REST
namespace. It is never a separate plugin or release artifact.

## Decision

### 1. Cross-environment object identity — hybrid

New plugin-owned table `aiml_object_identity` keyed `(source_type, source_id)` holding a
`uuid` (UUIDv4) and a **deterministic, environment-independent natural key** (stored as
`TEXT`, indexed only through `natural_key_hash BINARY(32)` = sha256 of the key).

`ObjectNaturalKey` — one fixed algorithm per object type, derived only from the source
object's own canonical characteristics, **never conditional on what else exists in the
local DB**:

```
post / page / hierarchical:  post|type={post_type}|ancestors={slug/…}|slug={slug}
non-hierarchical post:       post|type={post_type}|slug={slug}
product:                     post|type=product|slug={slug}
term:                        term|taxonomy={taxonomy}|ancestors={slug/…}|slug={slug}
```

Slugs are raw `post_name` / term `slug` (not permalink paths) so a different rewrite base
or permalink structure on PROD does not change the key. If a key resolves to more than one
local object the row is `ambiguous_source` — the key format is never mutated per install.

**Every object in an exported M1 package carries a non-null `object_uuid`.** Export mints
and persists the UUID on the source (DEV) *before* serialization. On import, identity rows
are created / adopted **only at apply time**, never during dry-run.

Resolution order (`ObjectIdentityResolver`, strictly read-only):

- **UUID pass — authoritative once the relationship exists.** Incoming `object_uuid`
  matches a local `aiml_object_identity.uuid` → that mapping wins, even when the incoming
  natural key now resolves to a *different* local object (recorded as an advisory
  natural-key-divergence note, never remapped). `identity_conflict` only when the mapped
  local source no longer exists or its `source_type` / `source_subtype` is incompatible.
- **Natural-key pass — bootstrap only** (no UUID match). Exactly one local hit with no
  identity row → `adopt_incoming_uuid_on_apply`. A local hit already carrying a *different*
  UUID → `identity_conflict`. More than one hit → `ambiguous_source`.
- **Fingerprint fallback** (`wp_guid` hash, then product SKU as a *hint only*) → one
  advisory suggested object; usable only in Selective mode by explicitly accepting that
  exact suggestion. Arbitrary manual mapping is deferred.

`identity_conflict` and `ambiguous_source` are never force-applied.

### 2. Three-way merge baseline

New table `aiml_promotion_state`, written **only by apply**, one row per promoted
`(object_uuid, language_code, segment_hash)` recording
`last_promoted_translation_hash` + `last_promoted_source_hash` + `last_package_id` +
timestamp. `target_diverged` = a local translation exists AND
(no baseline row OR `current_prod_translation_hash != last_promoted_hash`). Clock time is
never used for the safety decision.

### 3. Conflict / staleness model

`S_match` = package `source_hash` equals PROD's freshly recomputed local source hash
(with `norm_version` downgrade handled as rehash-not-drift).

| | `S_match` | source differs |
|---|---|---|
| target absent / `!target_diverged` | `new` / `update` / `unchanged` | `stale_source` |
| `target_diverged` | `conflict_target_modified` | `conflict_both_changed` |

Other categories: `missing_source`, `missing_language`, `unsupported_type`,
`unknown_field`, `route_conflict`, `identity_conflict`, `ambiguous_source`,
`validation_failure`, and apply-time-only `changed_since_dry_run`.

### 4. Package format — v1 only

Single `.json` document, split into a **deterministic `payload`** (sorted object keys,
deterministic ordering of every array) and a varying `manifest` envelope. Two checksums:

- `payload_checksum` = `sha256(canonical(payload))` — content identity.
- `package_checksum` = `sha256(canonical({ manifest: <minus own field>, payload: payload }))`
  — whole-artifact integrity; what apply and the review token bind to.

Importer accepts `format_version == 1` only (`<1` unsupported, `>1` hard reject). Unknown
optional properties are ignored; unknown field semantics classify `unknown_field` and are
never written.

### 5. Language mapping — conservative

1. exact `locale` → match. 2. explicit package locale with no exact PROD locale →
`missing_language`. 3. bare `code` fallback **only** when the locale is genuinely
absent / unspecified on one side *and* exactly one compatible local language exists.
4. never cross-map two different explicit locales (`pt_BR` → `pt_PT` is `missing_language`).
Never auto-create a language.

### 6. Dry-run — strictly read-only

`TranslationImportService::plan()` performs **no** DB writes, option writes, transients,
spools, identity adoption, promotion-log rows, or `aiml_promotion_audit` / any other
action. It returns an `ImportPlan` plus a **stateless signed review token**:
`base64url(canonical_json(claims)) . "." . base64url(HMAC_sha256(secret, canonical_json(claims)))`
with claims `{ actor_id, package_id, package_checksum, canonical_plan_hash, issued_at,
expires_at }` (`secret` derived from `wp_salt('auth')`). Nothing is persisted server-side.

### 7. Apply — re-upload + token + revalidate, synchronous

The client re-uploads the package + token + reviewed plan. Server: verify token
(signature / actor / expiry) and `package_checksum`; canonicalize the client plan and
require its hash == the token's; independently re-run `plan()` and require the fresh
`canonical_plan_hash` == the token's — **any material drift refuses the whole apply**
(re-review required). Then per row, immediately before its write, revalidate the captured
preconditions against live PROD → mismatch = `changed_since_dry_run`, that row is skipped,
the rest proceed.

Modes: `safe_only` (new + update only), `selective` (explicit allowlist; `stale_source`,
`conflict_target_modified`, `route_conflict` need a per-row acknowledgement), `force`
(applicable conflicts + stale; still never `identity_conflict` / `ambiguous_source`).
No `skip_conflicts` mode.

Writes go through `Store::save_translation()` / `Store::save_slug_candidate()` only. The
written `source_text` is PROD's current canonical for that field. Imported segments land
`review_status = not_submitted`, `publish_status = unpublished`; a separate capability
`aiml_trust_promoted_review_state` (administrators only) advances them through the existing
`ReviewWorkflowService` / `PublicationService`.

**M1 is synchronous and bounded — no Action Scheduler, no queued continuation, no package
spool.** Hard ceiling **25 MB**. Soft-warn and object cap: see Benchmarked limits below.

### 8. Routes / slugs

Only the translated slug *segment* is imported (via `save_slug_candidate()`, preserving
`slug_origin`). `aiml_slug_routes` / `aiml_route_history` are per-environment projections
and are never exported. PROD's own `SlugRouteActivationJob` / `RoutePublicationService`
rebuild routes against PROD's tree. Dry-run reports `route_conflict` via a read-only
collision check; a `route_conflict` slug is not applied in Safe mode. `post_name` / term
`slug` are never mutated.

### 9. WooCommerce / object-provider scope (M1)

`post`, `page`, `product` (`post_title`, `post_name`, `post_excerpt`, `post_content` —
whole-field or `b:<uuid>:<field>` block segments), Rank Math SEO meta (`m:rank-math:*`),
and `product_cat` / `product_tag` term name + description. Field discovery reuses
`PostSurfaceAdapter::extract_segments()` / `TermExtractor::extract()`. `_sku` is a matching
hint only, never identity. Deferred → `unsupported_type`: `nav_menu_item`, `pa_*`
attribute taxonomies, `product_variation`, Elementor, FluentForms, checkout / account /
email journey strings. Media / attachment IDs are imported verbatim (no remapping);
segments containing `wp-content/uploads` or `?attachment_id=` get an advisory dry-run note.

### 10. Audit / history

New table `aiml_promotion_log` (infrequent, high-consequence, operator-facing;
retention ~200 rows) written only by export and apply. `do_action('aiml_promotion_audit',
$event)` with stages `export | apply_start | apply_row | apply_complete` — **no dry-run
stage**.

### 11. Schema / capabilities

One migration step **10** (`Migrator::TARGET` 9 → 10), `step_10_promotion_foundation`,
creating the three tables with explicit idempotent SQL (no `dbDelta`), registered in
`Schema::all_tables()`. No `aiml_translations` column. `Settings::SCHEMA_VERSION` 2 → 3
adds `promotion_max_package_bytes` and `promotion_log_retention`.

Capabilities: `aiml_promote_translations` and `aiml_trust_promoted_review_state`, both
**`administrator` only by default**, provisioned through an idempotent `aiml_caps_version`
option checked on `admin_init` and on activation. Sites widen the grant through a
`aiml_promotion_can_promote` `map_meta_cap` filter, evaluated per request (no role-scan
drift). A dedicated promoter/reviewer role is **not** shipped in M1 — see below.

### 12. CLI

`wp aiml promotion export | import --dry-run | import --apply --mode= | history |
backfill-identity`, reusing the exact same domain services. Ships in WP10.

Feature ships as **v1.14.0**.

## Benchmarked limits

WP1 benchmarked `plan()` + `apply()` cost against a synthetic package on the integration
harness (mariadb 11.4, PHP 8.3):

- Per-object serialization / classification is dominated by one `Store` read and one
  `Extractor` re-extract per object. ~1.6 ms / object for `plan()`, ~4 ms / object for
  `apply()` (write path + `promotion_state` upsert).
- A single-language export of the full DEV catalogue (posts + pages + products + catalog
  terms) is ~40 KB / object of JSON before compression.

**Frozen M1 limits:**

- Hard ceiling: **25 MB** decoded package (unchanged — well above a single-language export
  of a large catalogue).
- Soft-warn: **12 MB** (~300 objects at the observed size; the UI warns and recommends
  splitting the export by language).
- Object cap: **3,000 objects** per package (`plan()` ≈ 5 s, `apply()` ≈ 12 s — within
  PHP `max_execution_time` defaults with margin). Exports exceeding it are rejected with a
  "split by language / scope" message.

## Consequences

- `aiml_object_identity` rows accumulate one per promoted object; `aiml_promotion_state`
  one per promoted `(object, language, segment)`. Both are bounded by content size, far
  smaller than `aiml_translations`, and dropped on uninstall.
- The first promotion of an object bootstraps by natural key; every promotion after that
  is UUID-joined and rename-proof on either side.
- An object renamed on DEV **and** already differently-slugged on PROD before any promotion
  falls to the advisory fingerprint path (Selective only). A general manual-map UI is a
  later milestone.
- `post_content` / block-segment promotion requires block-identity parity on both sides;
  title / slug / excerpt / SEO meta promote regardless.
- Hosted-key term translations (ADR-0021 lazy adoption) are reported `unsupported_type` in
  M1.
- `PluginGuardTest` gains assertions that the `Promotion` namespace never calls
  `unserialize` / `wp_insert_post` / `wp_update_post` / core-table writes and that its
  `$wpdb` access is confined to `src/Database/*` repositories.

See also: ADR-0003 (explicit migrations), ADR-0005 / 0007 (segment storage, hash
semantics), ADR-0015 (review workflow), ADR-0020 (publication gate), ADR-0021 (term
identity), ADR-0023 (localized URL projections).
