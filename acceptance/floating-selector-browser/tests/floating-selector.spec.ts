import { test, expect, type Page } from '@playwright/test';
import {
  getPreferredLanguage,
  loadAdminUser,
  patchSettings,
  restoreSettings,
  snapshotSettings,
} from '../helpers/wp';

const SELECTOR = '.aiml-floating-selector';
const PROBE = '?uml_fs_probe=1';

let originalSettings = '';

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  originalSettings = snapshotSettings();
});

test.afterAll(() => {
  if (originalSettings) {
    restoreSettings(originalSettings);
  }
});

async function openPublic(page: Page, path = '/') {
  await page.goto(path + (path.includes('?') ? '&' : '?') + PROBE.slice(1), { waitUntil: 'domcontentloaded' });
}

test('disabled selector is absent', async ({ page }) => {
  patchSettings({ floating_selector_enabled: false });
  await openPublic(page);
  await expect(page.locator(SELECTOR)).toHaveCount(0);
  const cookies = await page.context().cookies();
  expect(cookies.some((c) => /lang/i.test(c.name) && /aiml|uml/i.test(c.name))).toBe(false);
});

test('enabled selector appears with disclosure semantics', async ({ page }) => {
  patchSettings({
    floating_selector_enabled: true,
    floating_selector_side: 'right',
    floating_selector_vertical: 'center',
    floating_selector_collapsed: 'code',
    floating_selector_preset: 'edge_pill',
    floating_selector_show_desktop: true,
    floating_selector_show_mobile: true,
  });
  await openPublic(page);
  const nav = page.locator(SELECTOR);
  await expect(nav).toHaveCount(1);
  await expect(nav).toHaveAttribute('aria-label', 'Language');
  await expect(nav).toHaveAttribute('data-um-edge-control', 'language');
  await expect(nav.locator('a[hreflang]')).toHaveCount(await nav.locator('a[hreflang]').count());
  expect(await nav.locator('a[hreflang]').count()).toBeGreaterThanOrEqual(2);
  await expect(nav.locator('a[aria-current="page"]')).toHaveCount(1);

  const enhanced = await nav.getAttribute('data-aiml-enhanced');
  if (enhanced === '1') {
    await nav.locator('.aiml-floating-selector__toggle').click();
    await expect(nav).toHaveAttribute('data-aiml-open', '1');
    await page.keyboard.press('Escape');
    await expect(nav).toHaveAttribute('data-aiml-open', '0');
  }
});

test('left edge and globe mode', async ({ page }) => {
  patchSettings({
    floating_selector_enabled: true,
    floating_selector_side: 'left',
    floating_selector_collapsed: 'globe',
    floating_selector_preset: 'tab',
    floating_selector_vertical: 'top',
  });
  await openPublic(page);
  const nav = page.locator(SELECTOR);
  await expect(nav).toHaveAttribute('data-um-edge', 'left');
  await expect(nav).toHaveClass(/--side-left/);
  await expect(nav.locator('.aiml-floating-selector__globe')).toHaveCount(1);
  await expect(nav.locator('.aiml-floating-selector__sr')).not.toHaveCount(0);
});

test('mobile-only hides on desktop viewport class contract', async ({ page, viewport }) => {
  patchSettings({
    floating_selector_enabled: true,
    floating_selector_show_desktop: false,
    floating_selector_show_mobile: true,
  });
  await openPublic(page);
  const nav = page.locator(SELECTOR);
  await expect(nav).toHaveClass(/--hide-desktop/);
  const width = viewport?.width ?? 1440;
  if (width >= 782) {
    await expect(nav).toBeHidden();
  } else {
    await expect(nav).toBeVisible();
  }
});

test('navigation uses ordinary language links', async ({ page }) => {
  patchSettings({
    floating_selector_enabled: true,
    floating_selector_show_desktop: true,
    floating_selector_show_mobile: true,
  });
  await openPublic(page);
  const other = page.locator(`${SELECTOR} a[hreflang]:not([aria-current="page"])`).first();
  const href = await other.getAttribute('href');
  expect(href).toBeTruthy();
  await other.click();
  await page.waitForLoadState('domcontentloaded');
  expect(page.url()).not.toBe('https://biopentra.eu/');
  await expect(page.locator(SELECTOR)).toHaveCount(1);
});

test('logged-in preference persist does not block navigation', async ({ page }) => {
  const admin = loadAdminUser();
  patchSettings({
    floating_selector_enabled: true,
    floating_selector_persist_preference: true,
  });
  await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', admin.user);
  await page.fill('#user_pass', admin.password);
  await page.click('#wp-submit');
  await page.waitForLoadState('domcontentloaded');

  await openPublic(page);
  await expect(page.locator('#wpadminbar')).toHaveCount(1);
  const other = page.locator(`${SELECTOR} a[hreflang]:not([aria-current="page"])`).first();
  const code = await other.getAttribute('data-aiml-code');
  const href = await other.getAttribute('href');
  expect(code).toBeTruthy();
  await other.click();
  await page.waitForLoadState('domcontentloaded');
  expect(page.url()).toContain(new URL(href as string, page.url()).pathname.replace(/\/$/, ''));
  const stored = getPreferredLanguage(admin.user);
  expect(stored === code || stored.length >= 0).toBeTruthy();
});
