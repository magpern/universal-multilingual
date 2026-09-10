import { chromium, FullConfig } from '@playwright/test';
import fs from 'fs';
import path from 'path';

/**
 * Form-login once as the DEV admin and persist the session so every spec
 * starts authenticated. Credentials come from env (set by tools/run.sh from
 * /opt/biopentra/apps/wordpress/.admin-credentials).
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
  const baseURL = process.env.WP_BASE_URL ?? 'https://dev.biopentra.eu';
  const user = process.env.WP_ADMIN_USER;
  const pass = process.env.WP_ADMIN_PASS;
  if (!user || !pass) {
    throw new Error('WP_ADMIN_USER / WP_ADMIN_PASS not set');
  }

  const artifactsDir = path.join(__dirname, 'artifacts');
  fs.mkdirSync(artifactsDir, { recursive: true });

  const browser = await chromium.launch();
  const page = await browser.newPage({ ignoreHTTPSErrors: true });
  await page.goto(`${baseURL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([
    page.waitForURL(/wp-admin/, { timeout: 60_000 }),
    page.click('#wp-submit'),
  ]);
  await page.context().storageState({ path: path.join(artifactsDir, 'storage-state.json') });
  await browser.close();
}
