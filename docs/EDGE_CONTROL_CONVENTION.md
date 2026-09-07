# Edge-control convention

Minimal documented interoperability for optional edge-docked visitor controls.
This is **not** a shared package, runtime discovery protocol, or collision engine.

Universal Multilingual implements the language control. Universal Multicurrency
may later implement a currency control independently. Neither plugin should
read the other's private attributes.

## Root attributes

| Attribute | UML language (this plugin) | Future UMC currency (not implemented here) |
|---|---|---|
| `data-um-edge-control` | `language` | `currency` |
| `data-um-edge` | `left` or `right` (physical) | `left` or `right` |
| `data-um-edge-slot` | `1` | `2` |
| `data-um-edge-priority` | `10` | `20` |

## Shared CSS variables (optional)

Plugins MAY set these on their own root. They are conventions, not a required runtime API.

| Variable | Suggested default | Purpose |
|---|---|---|
| `--um-edge-control-size` | `2.75rem` (~44px) | Minimum pointer target |
| `--um-edge-stack-gap` | `0.5rem` | Gap if two controls share an edge |
| `--um-edge-z-index` | `1000` | Below the WordPress admin bar |

Do **not** attempt to outrank the admin bar (`--wp-admin--admin-bar--height`).

## Private attributes (do not reuse)

- UML may use `data-aiml-*` and `.aiml-floating-selector*` privately.
- UMC may use `data-umc-*` and `--umc-switcher-*` privately.

This milestone does not implement UMC stacking, dynamic collision avoidance,
or a shared npm/PHP package.
