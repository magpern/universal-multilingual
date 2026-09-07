#!/usr/bin/env bash
#
# Builds an installable plugin zip into dist/.
# Run `composer install --no-dev` first so vendor/ contains only the autoloader.
#
# Usage: bin/build-zip.sh [version]   (defaults to the plugin header version)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(sed -n 's/^ \* Version: //p' "$ROOT/universal-multilingual.php" | tr -d '[:space:]')}"

if [ -z "$VERSION" ]; then
    echo "Could not determine plugin version." >&2
    exit 1
fi

if [ -d "$ROOT/vendor/phpunit" ]; then
    echo "vendor/ contains dev dependencies — run 'composer install --no-dev' before building." >&2
    exit 1
fi

DIST="$ROOT/dist"
BUILD="$DIST/universal-multilingual"

rm -rf "$DIST"
mkdir -p "$BUILD"

cp "$ROOT/universal-multilingual.php" "$ROOT/uninstall.php" "$BUILD/"
[ -f "$ROOT/readme.txt" ] && cp "$ROOT/readme.txt" "$BUILD/"
[ -f "$ROOT/LICENSE" ] && cp "$ROOT/LICENSE" "$BUILD/"
cp -R "$ROOT/src" "$BUILD/src"
cp -R "$ROOT/vendor" "$BUILD/vendor"

# Runtime assets only. Translator Workspace is consumed from build/; TypeScript
# sources, Jest tests, package manifests, and node_modules are development-only.
mkdir -p "$BUILD/assets"
[ -f "$ROOT/assets/block-editor.js" ] && cp "$ROOT/assets/block-editor.js" "$BUILD/assets/"
[ -d "$ROOT/assets/glossary-admin" ] && cp -R "$ROOT/assets/glossary-admin" "$BUILD/assets/glossary-admin"
[ -d "$ROOT/assets/term-slug-admin" ] && cp -R "$ROOT/assets/term-slug-admin" "$BUILD/assets/term-slug-admin"
[ -d "$ROOT/assets/frontend" ] && cp -R "$ROOT/assets/frontend" "$BUILD/assets/frontend"
if [ -d "$ROOT/assets/translator-workspace/build" ]; then
    mkdir -p "$BUILD/assets/translator-workspace"
    cp -R "$ROOT/assets/translator-workspace/build" "$BUILD/assets/translator-workspace/build"
fi

if ! [ -f "$BUILD/assets/translator-workspace/build/index.js" ]; then
    echo "Missing workspace bundle at assets/translator-workspace/build/index.js" >&2
    exit 1
fi

ZIP_PATH="$DIST/universal-multilingual-${VERSION}.zip"
if command -v zip >/dev/null 2>&1; then
    ( cd "$DIST" && zip -rq "universal-multilingual-${VERSION}.zip" universal-multilingual )
else
    python3 - "$DIST" "$ZIP_PATH" <<'PY'
import sys, zipfile
from pathlib import Path
dist, zip_path = Path(sys.argv[1]), Path(sys.argv[2])
root = dist / "universal-multilingual"
with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
    for path in sorted(root.rglob("*")):
        if path.is_file():
            zf.write(path, path.relative_to(dist).as_posix())
PY
fi

echo "dist/universal-multilingual-${VERSION}.zip"
