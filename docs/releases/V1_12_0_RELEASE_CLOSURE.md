# Universal Multilingual v1.12.0 — Release Closure

**Status:** **TAGGED / GITHUB RELEASE PUBLISHED** — published artifact independently verified  
**Version:** 1.12.0  
**Schema TARGET:** **8** (unchanged)  
**Settings schema:** **2** (option-shape marker only)  
**Migration:** **NONE**  
**Release-ready commit (tagged):** `e1d80bc05da0395cc7e58422bb91ce4c52d49207`  
**Annotated tag:** `v1.12.0`  
**GitHub Release:** https://github.com/magpern/universal-multilingual/releases/tag/v1.12.0  
**Previous release:** `v1.11.1` @ `cc4c8c012d987b09131e86962b9f5be4e753ea23` (unmoved)

## Preflight

| Field | Value |
|---|---|
| Starting `origin/main` | `e5e167ef53ff82f97099034b8d4166b7d89eaab7` |
| Latest published tag before this release | `v1.11.1` |
| Working tree at start | Clean; `main` == `origin/main` |
| Plugin version at start | 1.11.1 |
| Chosen next version | **1.12.0** (MINOR: user-facing Regional Preferences + floating selector since `v1.11.1`) |
| Open PRs | none |
| `Migrator::TARGET` | **8** |
| `Settings::SCHEMA_VERSION` | **2** |
| Migration / `step_9` | Absent / **NONE** |

## Scope included

- User Regional Preferences (preferred language API, WordPress profile, Woo Account details, UMC composition hooks, `WPLANG` fallback). No language routing change.
- Optional floating language selector (default off): edge docking, code/name/globe, presets, desktop/mobile CSS, progressive enhancement, accessibility, authenticated keepalive persist.
- Operator notes `docs/releases/v1.12.0.md` and user-manual Regional Preferences / floating selector sections.

## Tag

| Field | Value |
|---|---|
| Tag | `v1.12.0` |
| Type | Annotated |
| Message | `Universal Multilingual v1.12.0` |
| Target commit | `e1d80bc05da0395cc7e58422bb91ce4c52d49207` |
| Tag object | `da5f2559c7dd129e65587428a19e6e9321dc5344` |
| Push | SUCCESS — origin `refs/tags/v1.12.0` |

**Do not move this tag** for later docs-only closure commits.

## Release automation

| Workflow | Run | Result |
|---|---|---|
| Release (`.github/workflows/release.yml`) | [34167294784](https://github.com/magpern/universal-multilingual/actions/runs/34167294784) | **SUCCESS** |
| Publish release package | [34167294705](https://github.com/magpern/universal-multilingual/actions/runs/34167294705) | **SUCCESS** |
| Main CI (release-prep push) | [34167291947](https://github.com/magpern/universal-multilingual/actions/runs/34167291947) | **SUCCESS** |

GitHub Release created by the Release workflow (`generate_release_notes: true`). No duplicate manual release.

## Published GitHub Release asset (source of truth)

Independently downloaded from the GitHub Release (not the local prep ZIP).

| Field | Value |
|---|---|
| Filename | `universal-multilingual-1.12.0.zip` |
| Source | GitHub Release `v1.12.0` asset |
| Byte size | **1012403** |
| SHA-256 | `88ed510d79c6bde52224969d0a81998525a7b1b627a46aa90e46de479bc7efd5` |
| Archive entries | **635** |
| Plugin header Version | **1.12.0** |
| `AIML_VERSION` | **1.12.0** |
| `Migrator::TARGET` | **8** |
| `Settings::SCHEMA_VERSION` | **2** |
| Package audit (`bin/audit-zip.sh`) | **PASS** |
| Frontend selector CSS/JS | Present |
| Forbidden paths (tests/docs/dev) | None |
| Biopentra naming in runtime PHP | None |
| Hard UMC dependency | None |

Local prep ZIP (`5b39410a89569423449464d7fdac911ece70b7c18282643ad87516f67b10bb8c`, 1012383 bytes) differs only as expected from CI `composer install` metadata. **Published digest is authoritative.**

## Validation gates

| Gate | Result |
|---|---|
| PHPCS | PASS |
| Unit | PASS — 941 tests, 3110 assertions (2 skipped) |
| Integration | PASS — 951 tests (1 leftover `1.11.1` PluginGuard pin fixed in release-prep; PluginGuard + RoutingTest + FloatingSelector **88/88** re-run PASS). GitHub integration job green. |
| JS / frontend build | Vanilla selector CSS/JS; workspace bundle already in tree; zip audit requires frontend assets. Playwright Chromium pin **deferred**. |
| PluginGuard | PASS |
| RoutingTest | PASS |
| i18n / POT | N/A — no POT in repo; not a CI job |
| quality:validate | PASS (C1.3 corpus warnings unchanged / non-blocking) |
| Package / ZIP audit | PASS |
| Release audit | PASS (Release workflow zip audit) |

## DEV acceptance (`dev.biopentra.eu` only)

Bind-mounted main; no ZIP install. Production not contacted.

| Check | Result |
|---|---|
| Profile Regional Preferences (language + UMC currency composition) | PASS — one section; both fields |
| Preferred-language save (user 98 as self) | PASS (`sv` then restored) |
| Woo My Account Account details fieldset | PASS |
| Selector default OFF | PASS (absent on public HTML) |
| Temporarily enabled | PASS (right / center / edge_pill / code) |
| Desktop product URL | PASS — `en` control; links `/product/nacl-water/` ↔ `/sv/product/nacl-water/` |
| Mobile viewport (390×844) | PASS — `Svenska` control still present on `/sv/product/nacl-water/` |
| Navigation | PASS via emitted localized product/page URLs. **Did not use `/sv/` homepage.** |
| Anonymous language cookie | PASS — none (`Set-Cookie` empty on anonymous curl; `document.cookie` has no UML language cookie) |
| Authenticated persist | PASS — `handle_prefer_request` for `bp_manager` wrote `sv` |
| Selector restored OFF | PASS |

Bounded limitation: in-browser click on the Svenska link did not complete navigation in the automation session (cookie banner / overlay); curl and direct navigation of the same href succeeded. Pre-existing `/sv/` homepage 301 loop was not used and was not re-opened as a routing project.

## Production

**UNTOUCHED.** No production WP-CLI, settings, ZIP install, or tests.

## Deferred / non-blocking

- Optional `LanguageRelationshipService::for_path()` memoization
- Pre-existing `/sv/` homepage redirect loop on DEV
- Playwright Chromium pin mismatch
- Future UMC currency edge-control alignment

## Tag vs closure

The tag **`v1.12.0` remains on `e1d80bc05da0395cc7e58422bb91ce4c52d49207`** and is not moved for this closure documentation commit.

## Verdict

UNIVERSAL MULTILINGUAL v1.12.0: RELEASED — PASS

USER REGIONAL PREFERENCES: INCLUDED  
FLOATING LANGUAGE SELECTOR: INCLUDED  
FLOATING SELECTOR DEFAULT: OFF  
LANGUAGE URL AUTHORITY: UNCHANGED  
ANONYMOUS CACHE CONTRACT: UNCHANGED  
MIGRATION: NONE  
PUBLISHED ARTIFACT: VERIFIED  
PRODUCTION: UNTOUCHED
