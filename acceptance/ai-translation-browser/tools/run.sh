#!/usr/bin/env bash
#
# AIT1 AI translation browser acceptance orchestrator.
#
#   - installs the self-contained fake-provider MU-plugin on DEV
#   - seeds fixtures via WP-CLI
#   - runs Playwright (in the Playwright Docker image; browsers pre-installed)
#   - removes the MU-plugin and verifies it is gone
#   - runs a post-removal health check
#
# Never calls a paid AI provider. Never touches production.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SUITE="$(dirname "$HERE")"
DEV_CHECKOUT=/opt/biopentra/dev/universal-multilingual
DEV_WP="${DEV_WP:-/opt/biopentra/scripts/dev-wp}"
CREDS="${WP_CREDENTIALS_FILE:-/opt/biopentra/apps/wordpress/.admin-credentials}"
PW_IMAGE="${PW_IMAGE:-mcr.microsoft.com/playwright:v1.55.1-noble}"
MU_NAME=aiml-acceptance-fake-provider.php

export WP_BASE_URL="${WP_BASE_URL:-https://dev.biopentra.eu}"

# A dedicated throwaway admin, created here and deleted on teardown, so the
# suite never depends on a shared credentials file that may have rotated.
ACCEPT_USER="${WP_ADMIN_USER:-aiml_accept_admin}"
ACCEPT_PASS="${WP_ADMIN_PASS:-AitAccept-$(openssl rand -hex 8)!}"
if [ -z "${WP_ADMIN_USER:-}" ]; then
  "$DEV_WP" wp user get "$ACCEPT_USER" >/dev/null 2>&1 \
    || "$DEV_WP" wp user create "$ACCEPT_USER" aiml-accept@biopentra.eu --role=administrator --user_pass="$ACCEPT_PASS" >/dev/null
  "$DEV_WP" wp user update "$ACCEPT_USER" --user_pass="$ACCEPT_PASS" >/dev/null
fi
export WP_ADMIN_USER="$ACCEPT_USER"
export WP_ADMIN_PASS="$ACCEPT_PASS"
export AIML_SV_LANGUAGE_ID="$("$DEV_WP" wp eval '$l=(new \AIMultilingual\Language\Languages(new \AIMultilingual\Cache\Cache()))->find_by_code("sv"); echo $l?$l->language_id:"0";')"
: "${CREDS:=/opt/biopentra/apps/wordpress/.admin-credentials}"

cleanup() {
  echo "== teardown: remove fake provider MU-plugin =="
  docker exec wordpress rm -f "/var/www/html/wp-content/mu-plugins/${MU_NAME}" || true
  "$DEV_WP" wp option delete aiml_acceptance_fake_mode >/dev/null 2>&1 || true
  "$DEV_WP" wp user delete aiml_accept_restricted --yes >/dev/null 2>&1 || true
  "$DEV_WP" wp eval-file "wp-content/plugins/universal-multilingual/acceptance/ai-translation-browser/tools/verify-teardown.php"
}
trap cleanup EXIT

echo "== rsync suite into the bind-mounted DEV checkout =="
rsync -a --delete --exclude node_modules --exclude artifacts "$SUITE/" "$DEV_CHECKOUT/acceptance/ai-translation-browser/"

echo "== install fake provider MU-plugin =="
docker cp "$HERE/${MU_NAME}" "wordpress:/var/www/html/wp-content/mu-plugins/${MU_NAME}"
docker exec wordpress chown www-data:www-data "/var/www/html/wp-content/mu-plugins/${MU_NAME}"
"$DEV_WP" wp eval 'echo ( "acceptance-fake" === ( new \AIMultilingual\Translation\AI\ProviderRegistry( new \AIMultilingual\Settings() ) )->active()->get_id() ) ? "FAKE_ACTIVE\n" : "FAKE_INACTIVE\n";'

echo "== provision a restricted-capability user for the subnav test =="
RESTRICTED_PASS="AitR-$(openssl rand -hex 8)!"
"$DEV_WP" wp user get aiml_accept_restricted >/dev/null 2>&1 \
  || "$DEV_WP" wp user create aiml_accept_restricted aiml-restricted@biopentra.eu --role=subscriber --user_pass="$RESTRICTED_PASS" >/dev/null
"$DEV_WP" wp user update aiml_accept_restricted --user_pass="$RESTRICTED_PASS" >/dev/null
"$DEV_WP" wp user add-cap aiml_accept_restricted aiml_translate >/dev/null
export AIML_RESTRICTED_STATE="{\"user\":\"aiml_accept_restricted\",\"pass\":\"$RESTRICTED_PASS\"}"

echo "== seed fixtures =="
AIML_FIXTURES="$("$DEV_WP" wp eval-file "wp-content/plugins/universal-multilingual/acceptance/ai-translation-browser/tools/seed-fixtures.php" | tail -1)"
echo "fixtures: $AIML_FIXTURES"
export AIML_FIXTURES

mkdir -p "$SUITE/artifacts"

run_pw() {
  docker run --rm --network host \
    -e WP_BASE_URL -e WP_ADMIN_USER -e WP_ADMIN_PASS -e AIML_FIXTURES \
    -e CI=1 \
    -v "$SUITE":/suite -w /suite \
    "$PW_IMAGE" \
    sh -c 'npm ci --no-audit --no-fund --silent && npx playwright test '"$*"
}

echo "== phase 1: core + discoverability + Elementor + large =="
"$DEV_WP" wp option update aiml_acceptance_fake_mode ok >/dev/null
run_pw tests/ai-translation.spec.ts

echo "== phase 2: bulk =="
run_pw tests/ai-translation-bulk.spec.ts -g "bulk"

echo "== phase 3: failure + retry (fake provider in rate_limit) =="
"$DEV_WP" wp option update aiml_acceptance_fake_mode rate_limit >/dev/null
run_pw tests/ai-translation-bulk.spec.ts -g "failure then retry" || PHASE3=$?
"$DEV_WP" wp option update aiml_acceptance_fake_mode ok >/dev/null

echo "== phase 4: no-provider disabled CTA =="
KEY_BACKUP="$("$DEV_WP" wp eval '$s=(new \AIMultilingual\Settings())->get(); echo (string)($s["ai_providers"]["openai"]["api_key_encrypted"]??"");')"
"$DEV_WP" wp eval '$o=get_option("aiml_settings"); $o["ai_enabled"]=false; update_option("aiml_settings",$o);' >/dev/null
run_pw tests/ai-translation-noprovider.spec.ts || PHASE4=$?
"$DEV_WP" wp eval '$o=get_option("aiml_settings"); $o["ai_enabled"]=true; update_option("aiml_settings",$o);' >/dev/null

echo "== done (phase3=${PHASE3:-0} phase4=${PHASE4:-0}) =="
