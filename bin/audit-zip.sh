#!/usr/bin/env bash
#
# Audits a release ZIP produced by bin/build-zip.sh.
#
# Usage: bin/audit-zip.sh [path-to-zip]
# Default: newest dist/universal-multilingual-*.zip
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP="${1:-}"

if [ -z "$ZIP" ]; then
    ZIP="$(ls -1t "$ROOT"/dist/universal-multilingual-*.zip 2>/dev/null | head -n1 || true)"
fi

if [ -z "$ZIP" ] || [ ! -f "$ZIP" ]; then
    echo "No release ZIP found. Build with bin/build-zip.sh first." >&2
    exit 1
fi

HEADER_VER="$(sed -n 's/^ \* Version: //p' "$ROOT/universal-multilingual.php" | tr -d '[:space:]')"
ZIP_BASENAME="$(basename "$ZIP")"
EXPECTED_NAME="universal-multilingual-${HEADER_VER}.zip"

python3 - "$ZIP" "$HEADER_VER" "$EXPECTED_NAME" <<'PY'
import sys
import zipfile
from pathlib import Path

zip_path = Path(sys.argv[1])
header_ver = sys.argv[2]
expected_name = sys.argv[3]

if zip_path.name != expected_name:
    print(f"FAIL: zip name {zip_path.name!r} does not match plugin header version ({expected_name!r}).", file=sys.stderr)
    sys.exit(1)

required = {
    "universal-multilingual/universal-multilingual.php",
    "universal-multilingual/uninstall.php",
    "universal-multilingual/readme.txt",
    "universal-multilingual/vendor/autoload.php",
    "universal-multilingual/assets/block-editor.js",
    "universal-multilingual/assets/glossary-admin/glossary-admin.js",
    "universal-multilingual/assets/term-slug-admin/term-slug-admin.js",
    "universal-multilingual/assets/promotion-admin/promotion-admin.js",
    "universal-multilingual/assets/promotion-admin/promotion-admin.css",
    "universal-multilingual/assets/translator-workspace/build/index.js",
    "universal-multilingual/assets/translator-workspace/build/index.asset.php",
    "universal-multilingual/assets/frontend/floating-selector.css",
    "universal-multilingual/assets/frontend/floating-selector.js",
    "universal-multilingual/src/Plugin.php",
}

forbidden_substrings = (
    "/.git/",
    "/.github/",
    "/tests/",
    "/docs/",
    "/node_modules/",
    "/vendor/phpunit/",
    "/vendor/wp-coding-standards/",
    "/phpunit",
    "/phpcs",
    ".env",
    "agent-transcript",
    "/plans/",
    "/aseo-evidence/",
)

forbidden_suffixes = (
    ".test.ts",
    ".test.tsx",
    ".test.js",
)

dev_workspace_markers = (
    "universal-multilingual/assets/translator-workspace/src/",
    "universal-multilingual/assets/translator-workspace/package.json",
    "universal-multilingual/assets/translator-workspace/package-lock.json",
    "universal-multilingual/assets/translator-workspace/tsconfig.json",
)

with zipfile.ZipFile(zip_path) as zf:
    names = zf.namelist()

tops = {n.split("/", 1)[0] for n in names if n}
if tops != {"universal-multilingual"}:
    print(f"FAIL: expected single top-level directory 'universal-multilingual', found {sorted(tops)}.", file=sys.stderr)
    sys.exit(1)

missing = sorted(required - set(names))
if missing:
    print("FAIL: required distributable paths missing:", file=sys.stderr)
    for path in missing:
        print(f"  - {path}", file=sys.stderr)
    sys.exit(1)

# Nested duplicate plugin directory.
if any(n.startswith("universal-multilingual/universal-multilingual/") for n in names):
    print("FAIL: nested duplicate plugin directory detected.", file=sys.stderr)
    sys.exit(1)

violations = []
for name in names:
    lowered = name.lower()
    for needle in forbidden_substrings:
        if needle in lowered:
            violations.append(f"{name} (matched {needle!r})")
            break
    else:
        for suffix in forbidden_suffixes:
            if name.endswith(suffix):
                violations.append(f"{name} (suffix {suffix!r})")
                break
        else:
            for marker in dev_workspace_markers:
                if name.startswith(marker) or name == marker.rstrip("/"):
                    violations.append(f"{name} (dev workspace marker)")
                    break

if violations:
    print("FAIL: forbidden or non-distributable paths present:", file=sys.stderr)
    for item in violations[:50]:
        print(f"  - {item}", file=sys.stderr)
    if len(violations) > 50:
        print(f"  … and {len(violations) - 50} more", file=sys.stderr)
    sys.exit(1)

# Confirm plugin header version inside the zip matches the filename / source.
with zipfile.ZipFile(zip_path) as zf:
    bootstrap = zf.read("universal-multilingual/universal-multilingual.php").decode("utf-8", errors="replace")
if f"* Version: {header_ver}" not in bootstrap and f"* Version: {header_ver}\n" not in bootstrap:
    # Allow flexible whitespace after Version:
    import re
    match = re.search(r"^\s*\*\s*Version:\s*(\S+)", bootstrap, re.M)
    found = match.group(1) if match else "(missing)"
    if found != header_ver:
        print(f"FAIL: zip bootstrap Version {found!r} != header {header_ver!r}.", file=sys.stderr)
        sys.exit(1)

size = zip_path.stat().st_size
print(f"PASS: {zip_path.name} ({size} bytes, {len(names)} entries)")
print(f"  version: {header_ver}")
print(f"  top-level: universal-multilingual/")
print("  required runtime paths present; no tests/docs/node_modules/dev workspace sources")
PY
