import { execFileSync } from 'child_process';
import fs from 'fs';

const DEV_WP = '/opt/biopentra/scripts/dev-wp';
const CREDENTIALS = process.env.WP_CREDENTIALS_FILE ?? '/opt/biopentra/apps/wordpress/.admin-credentials';

export function wpEval(php: string): string {
  return execFileSync(DEV_WP, ['wp', 'eval', php], { encoding: 'utf8' }).trim();
}

export function loadAdminUser(): { user: string; password: string } {
  if (process.env.WP_ADMIN_USER && process.env.WP_ADMIN_PASS) {
    return { user: process.env.WP_ADMIN_USER, password: process.env.WP_ADMIN_PASS };
  }
  const raw = fs.readFileSync(CREDENTIALS, 'utf8');
  const user = raw.match(/^user:\s*(\S+)/m)?.[1];
  const password = raw.match(/^password:\s*(\S+)/m)?.[1];
  if (!user || !password) {
    throw new Error('Could not parse admin credentials');
  }
  return { user, password };
}

export function snapshotSettings(): string {
  return wpEval('echo wp_json_encode(get_option("aiml_settings"));');
}

export function restoreSettings(json: string): void {
  const encoded = Buffer.from(json, 'utf8').toString('base64');
  wpEval(`update_option("aiml_settings", json_decode(base64_decode("${encoded}"), true)); echo "ok";`);
}

export function patchSettings(patch: Record<string, unknown>): void {
  const encoded = Buffer.from(JSON.stringify(patch), 'utf8').toString('base64');
  wpEval(`
    $s = get_option("aiml_settings");
    if (!is_array($s)) { $s = array(); }
    $p = json_decode(base64_decode("${encoded}"), true);
    if (is_array($p)) { $s = array_merge($s, $p); }
    update_option("aiml_settings", $s);
    echo "ok";
  `);
}

export function getPreferredLanguage(user: string): string {
  return wpEval(`
    $u = get_user_by("login", ${JSON.stringify(user)});
    echo $u ? (string) get_user_meta($u->ID, "aiml_preferred_language", true) : "";
  `);
}
