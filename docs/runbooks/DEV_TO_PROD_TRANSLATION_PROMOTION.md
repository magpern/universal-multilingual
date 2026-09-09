# DEV → PROD translation promotion — operator runbook

ADR: `docs/adr/0030-devprod-translation-promotion.md`. Applies from schema 10
(v1.14.0).

This moves *reviewed translations* between two independent WordPress installs.
It does not migrate content, media, settings, orders, or anything WordPress
core owns. The canonical objects (posts, products, terms) must already exist on
both sides.

## Preconditions

- Both sites run Universal Multilingual ≥ 1.14.0 (schema 10).
- The operator has `aiml_promote_translations` (administrator by default).
- The two environments have the same canonical content. For `post_content` /
  block-segment promotion, both sides must be block-identity parity (run
  `wp aiml block migrate` on both if unsure); title / slug / excerpt / SEO meta
  promote regardless.
- The target site already has the target languages, matched by **locale**
  (`sv_SE`, not just `sv`). A missing language blocks its rows — add it first.

## 1. Export (on the source)

Admin: **Universal Multilingual → Translation Promotion → Export**. Pick the
target language(s) and, optionally, a scope: post type(s), an id list,
"approved only", "published only", "changed since <date>". Click **Build
package** and keep the downloaded `.json`.

CLI:

```
wp aiml promotion export --lang=sv,de --approved-only --file=promotion.json
```

The export mints a UUID for every exported object on the source — this is
expected and is what makes future promotions rename-proof.

## 2. Dry-run (on the target)

Upload the `.json` and click **Validate & dry-run**. This writes nothing.
Review the category counts:

| Category | Meaning | Applied by |
|---|---|---|
| `new` / `update` | clean write | Safe / Selective / Force |
| `unchanged` | already identical | never (no-op) |
| `stale_source` | package translated against a source the target no longer has | Selective (ack) / Force |
| `conflict_target_modified` | the local translation was edited independently since the last promotion | Selective (ack) / Force |
| `conflict_both_changed` | source *and* local translation both moved | Force only |
| `route_conflict` | the translated slug would collide with another object's route | Selective (ack) / Force — the slug still imports as a candidate; fix the route on the target |
| `missing_source` | no local object matches | never — create the object first, or use `backfill-identity` |
| `missing_language` | no local language for this locale | never — add the language |
| `unsupported_type` / `unknown_field` | out of milestone-1 scope | never |
| `identity_conflict` / `ambiguous_source` | the UUID or natural key does not resolve unambiguously | never — needs a manual map (later milestone) |

CLI dry-run:

```
wp aiml promotion import promotion.json
```

## 3. Apply (on the target)

Choose a mode:

- **Safe only** (default) — `new` + `update`. Never clears an approved /
  published state.
- **Selective** — tick the exact rows to apply. `stale_source`,
  `conflict_target_modified` and `route_conflict` rows also need their per-row
  acknowledgement checkbox.
- **Force** — applies conflicts and stale rows (never identity / ambiguous).
  Each overwrite of a locally-edited translation is logged with the discarded
  hash.

The browser re-sends the package. If the target changed since the dry-run you
reviewed, the whole apply is refused with *"re-run the dry-run"* — do that.

By default promoted segments land **not submitted / unpublished** and must be
approved + published on the target through the normal workflow. If you hold
`aiml_trust_promoted_review_state`, the **"Carry review / publication state"**
checkbox promotes an approved+published DEV segment through the target's real
review / publication services.

CLI apply:

```
wp aiml promotion import promotion.json --apply --mode=safe_only
```

## 4. Verify

- The translated string renders on the target object's frontend.
- **History** tab (or `wp aiml promotion history`) shows the `export` and
  `import` rows.
- Re-running the same apply with no target changes is a no-op (all
  `unchanged`).

## Recovery

- Promotion never deletes. To revert a promoted translation, edit it on the
  target as normal.
- A partially-applied import (`result = partial`) lists the failed rows in the
  result report; fix the cause and re-import — it is idempotent.
- `wp aiml promotion backfill-identity` mints UUIDs for existing objects on
  either side; run it on the target before the first promotion if you renamed
  objects on the source beforehand.

## Two-site test (staging)

The Playwright acceptance suite simulates DEV and PROD as two content sets in
one install. To rehearse against genuinely separate sites: export from staging,
copy the `.json` to the second install, dry-run, apply in Safe mode, and
confirm the frontend string.
