import { execFileSync } from 'child_process';
import fs from 'fs';
import { Page } from '@playwright/test';

const DEV_WP = process.env.DEV_WP ?? '/opt/biopentra/scripts/dev-wp';
const CREDENTIALS = process.env.WP_CREDENTIALS_FILE ?? '/opt/biopentra/apps/wordpress/.admin-credentials';

/** Runs `wp eval` against the target install. */
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

/** Removes every non-default language row so each test starts clean. */
export function resetLanguages(): void {
  wpEval(`
    global $wpdb;
    $t = $wpdb->prefix . 'aiml_languages';
    $wpdb->query("DELETE FROM {$t} WHERE is_default = 0");
    wp_cache_flush();
    $epoch = (int) get_option('aiml_cache_version', 0);
    update_option('aiml_cache_version', $epoch + 1);
    echo 'ok';
  `);
}

/** The default language's WordPress locale (usually en_US). */
export function defaultLocale(): string {
  return wpEval(`
    global $wpdb;
    $t = $wpdb->prefix . 'aiml_languages';
    echo (string) $wpdb->get_var("SELECT locale FROM {$t} WHERE is_default = 1 LIMIT 1");
  `);
}

/** The stored row for a locale, as JSON, or "" when absent. */
export function languageRow(locale: string): Record<string, string> | null {
  const json = wpEval(`
    global $wpdb;
    $t = $wpdb->prefix . 'aiml_languages';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE locale = %s", ${JSON.stringify(locale)}), ARRAY_A);
    echo $row ? wp_json_encode($row) : '';
  `);
  return json ? (JSON.parse(json) as Record<string, string>) : null;
}

export async function login(page: Page): Promise<void> {
  const { user, password } = loadAdminUser();
  await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', password);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin/);
}

export async function openLanguages(page: Page): Promise<void> {
  await page.goto('/wp-admin/admin.php?page=ai-multilingual', { waitUntil: 'domcontentloaded' });
}
