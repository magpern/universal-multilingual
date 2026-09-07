# Extension API v1

Public extension surface for third-party **registered meta**, **custom Gutenberg block adapters**, **visitor translation lookup**, and **source invalidation notification**.

**ADR:** [0022-public-extension-boundary-and-registration-lifecycle.md](adr/0022-public-extension-boundary-and-registration-lifecycle.md)  
**Plan:** [TSC6_PUBLIC_EXTENSION_SEO_STABILIZATION_IMPLEMENTATION_PLAN.md](plans/TSC6_PUBLIC_EXTENSION_SEO_STABILIZATION_IMPLEMENTATION_PLAN.md)  
**Related:** [Integration API v1](INTEGRATION_API_V1.md) — authoritative for `p:` plugin integrations (separate hook)

## API version and stability

- **Version:** `v1`
- **Namespace:** `AIMultilingual\Extension\`
- **Block namespace:** `AIMultilingual\Extension\Block\`
- Deprecation policy: minimum one minor release warning before removal unless security requires otherwise (ADR-0022).

## Registration timing

1. WordPress loads AIML during `plugins_loaded`.
2. **`aiml_register_extensions`** fires once per request while registries are open.
3. Extensions call `ExtensionRegistrar::register_extension()` and nested registrations on the returned handle.
4. Registries **seal** immediately after the hook completes.
5. **`aiml_register_integrations`** remains a **separate** hook for Integration API v1.

Late registration after seal is rejected.

## Root extension ownership

```php
add_action( 'aiml_register_extensions', static function ( \AIMultilingual\Extension\ExtensionRegistrar $registrar ): void {
	$handle = $registrar->register_extension(
		new \AIMultilingual\Extension\ExtensionManifest(
			extension_id: 'my_vendor_plugin',
			version: '1.0.0',
			owned_namespaces: array( 'my_vendor' ),
		)
	);

	$handle->register_meta( /* ExtensionMetaDefinition */ );
	$handle->register_block_adapter( /* ExtensionBlockAdapter */ );
} );
```

**`ExtensionManifest`:**

| Field | Requirement |
|---|---|
| `extension_id` | Lowercase `[a-z0-9_]+`, max 32, unique |
| `version` | Semver string |
| `owned_namespaces` | One or more `m:` namespace tokens this extension may use |
| `requires_plugins` | Optional diagnostics metadata only |

Duplicate `extension_id`, namespace theft, or collision with core-owned identities → **fail closed**.

## Public meta (`ExtensionMetaDefinition`)

Supported:

- `namespace`, `source_type` (`post`\|`term`), exact `meta_key`
- `label`, `text_format` (`plain`\|`html`)
- optional `admitted_subtypes`
- `provider_allowed` — default **false**
- optional `activation` callable

**Not supported in v1:** `overlay_capable`, overlay ownership tokens, wildcards, `external_p`, options/usermeta/theme_mods, serialized/object meta.

Identity: `m:{namespace}:{meta_key}`

### Activation semantics

| State | Behavior |
|---|---|
| **ACTIVE** | Normal extract/provider/resolver |
| **INACTIVE** | Definition registered; **CASE B retain** existing Store rows; no provider/resolver overlay |
| **REMOVED** | Definition absent → next-sync orphan/retirement |

Activation is evaluated **once** at registry seal and cached. `Throwable` → INACTIVE.

**Uninstall limitation:** If extension code is entirely absent, AIML cannot distinguish temporary vs permanent removal. Retain-on-dependency-loss requires the extension to remain loaded and register an **INACTIVE stub definition**.

## Public block adapter (`ExtensionBlockAdapter`)

Narrow public contract — **not** internal `TranslatableBlockAdapter`.

```php
interface ExtensionBlockAdapter {
	public function get_block_names(): array;
	public function get_supported_fields(): array;
	public function is_translatable_instance( array $block ): bool;
	public function extract_field( array $block, string $field_id ): ?string;
	public function apply_field( array $block, string $field_id, string $translated_text ): array;
	public function get_text_format( string $field_id ): string;
}
```

AIML owns internally: `b:` UUID identity, structural validation, sanitization, `AdapterRegistry` bridge.

Hard rules: no core block collision, no JSON-path API, no canonical `post_content` mutation, HTML → structural guard, feature flags not bypassed.

## Visitor resolver

```php
$resolver->resolve(
	new SourceSegmentReference( $source_type, $source_id, $segment_key ),
	new LanguageReference( $language_code )
): ?ResolvedTranslation;
```

- **`SourceSegmentReference`:** complete source identity (segment keys are unique only within a source object).
- **`LanguageReference`:** stable URL **language code** — not database language id.
- **`ResolvedTranslation`:** `text`, `format`, `available` — no Store row exposure.

Resolver enforces TI.7 internally. Source/default language → `null`. No `force` option.

### Chrome / host-independent `p:` resolve (M5-A / 1.7.0)

For integration-owned private CPT chrome (see Integration API companion `DeclaresChromeOwnedSurfaces`):

- Resolve by explicit `SourceSegmentReference` source post ID — **independent of the queried page/shop host**.
- Eligibility is **Extension-strict**: stale → `null`; missing/unpublished/ineligible/invalid identity/unsupported → `null`.
- Private-CPT source must have `post_status=publish` or resolve returns `null`.
- Do **not** use `IntegrationFrontendBridge` / `register_output_hooks` for site-wide chrome. FrontendBridge remains host-bound and keeps **I7** (stale may overlay when publication-eligible) for existing page-anchored consumers.

### Public visitor language context

```php
aiml_visitor_language(): ?\AIMultilingual\Extension\VisitorLanguageContext
```

Returns `{ code, is_default }` from AIML URL/host resolution (ADR-0024). Returns `null` when unavailable or too early (before request language context is established). Does not read cookies, geo, or `Accept-Language`.

Visitor overlay pattern for meta:

1. Register meta via public API.
2. Hook your plugin's official output seam.
3. Call resolver; apply in-memory only.

## Invalidation helper

```php
aiml_mark_source_dirty( string $source_type, int $source_id ): bool;
```

Marks dirty only via request-local coordinator; coalesces; **no immediate sync**. Shutdown @ 20 remains sole sync authority.

## Provider default deny

`provider_allowed` defaults **false**. Even when true, TI.6 remains authority; extensions cannot invoke providers directly or bypass policy.

## Failure isolation (honest tiers)

| Tier | Guarantee |
|---|---|
| **A — Registrar validation** | Malformed DTO → reject; no partial registration |
| **B — AIML-invoked callback** | `Throwable` caught; fail closed; bounded diagnostic |
| **C — Hook callback itself** | Normal WordPress/PHP semantics if thrown outside AIML methods |

## Diagnostics (WP-CLI)

```bash
wp aiml extensions list
wp aiml extensions status <extension_id>
```

Bounded safe facts only — no callbacks, values, secrets, or Store rows.

Site Health / admin diagnostics UI: **Deferred**.

## Deferred / unsupported (v1)

- Public Elementor widget registration
- CPT/taxonomy slug-only admission filters
- Yoast adapter (product backlog)
- Generic overlay registration API
- Public Store access / direct writes / publication mutation
- REST registration endpoints
- Runtime admin field UI

## Preferred language (authenticated)

Public helpers in `src/Extension/functions.php` (ADR-0026):

- `aiml_get_preferred_language( int $user_id ): ?string`
- `aiml_get_preferred_language_state( int $user_id ): ?array`
- `aiml_set_preferred_language( int $user_id, ?string $code ): true|\WP_Error`

Storage: user meta `aiml_preferred_language` (language code). Does **not** change URL/host language resolution. See [USER_REGIONAL_PREFERENCES_IMPLEMENTATION_PLAN.md](plans/USER_REGIONAL_PREFERENCES_IMPLEMENTATION_PLAN.md).

## Anti-patterns

- Importing `Store`, `RegisteredMetaRegistry`, `AdapterRegistry`, `SurfaceRegistry`, or OTL/TI internals
- Concatenating segment keys manually for `p:` integrations (use Integration API v1)
- Expecting AIML to retain INACTIVE rows after full plugin uninstall without a stub registration
- Registering core blocks or Rank Math keys through Extension API v1

## Worked example

See black-box fixture: `tests/Fixtures/ReferenceExtension/` (tests only; excluded from production ZIP).
