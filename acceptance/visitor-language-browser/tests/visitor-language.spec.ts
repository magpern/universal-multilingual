import { test, expect, type Page, type BrowserContext } from '@playwright/test';
import { patchSettings, restoreSettings, snapshotSettings } from '../helpers/wp';

const COOKIE_NAME = 'aiml_visitor_lang';
const HOST = 'dev.biopentra.eu';
const SELECTOR = '.aiml-floating-selector';
const BANNER = '.aiml-visitor-suggest';
// Any query string bypasses the reverse-proxy cache ($bp_skip_query), same
// convention as acceptance/floating-selector-browser/tests — needed here so
// a just-patched setting is reflected on the next navigation instead of a
// cached response from before the patch.
const PROBE = 'uml_vl_probe=1';

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

// Static plugin assets are fetched by a stable ?ver=<AIML_VERSION> URL, which
// this iterating dev session never bumps between edits — Cloudflare's edge
// cache (in front of the reverse proxy tested elsewhere in this suite) can
// therefore serve a pre-edit copy for that exact URL. A real release always
// bumps AIML_VERSION, changing the URL and busting this naturally; here we
// force the same effect with a per-run cache-busting param so tests exercise
// the asset as currently deployed, not a stale edge-cached copy from earlier
// in the session.
const ASSET_BUST = String(Date.now());
test.beforeEach(async ({ context }) => {
  await context.route('**/wp-content/plugins/universal-multilingual/assets/frontend/**', async (route) => {
    const url = new URL(route.request().url());
    url.searchParams.set('cachebust', ASSET_BUST);
    await route.continue({ url: url.toString() });
  });
});

async function setVisitorCookie(context: BrowserContext, value: string) {
  await context.addCookies([
    {
      name: COOKIE_NAME,
      value,
      domain: HOST,
      path: '/',
      httpOnly: false,
      secure: true,
      sameSite: 'Lax',
    },
  ]);
}

async function readVisitorCookie(context: BrowserContext): Promise<string | undefined> {
  const cookies = await context.cookies();
  return cookies.find((c) => c.name === COOKIE_NAME)?.value;
}

async function openPublic(page: Page, path = '/') {
  await page.goto(path + (path.includes('?') ? '&' : '?') + PROBE, { waitUntil: 'domcontentloaded' });
}

test('explicit prefixed URL beats a conflicting visitor cookie', async ({ page, context }) => {
  patchSettings({ visitor_cookie_persist_enabled: true, visitor_autodetect_enabled: false });
  await setVisitorCookie(context, 'sv');

  await openPublic(page, '/de/');
  // Give the client bundle a moment to run, then assert no navigation away happened.
  await page.waitForTimeout(500);
  expect(new URL(page.url()).pathname).toBe('/de/');
});

test('cookie-driven redirect fires only from the unprefixed default URL', async ({ page, context }) => {
  patchSettings({ visitor_cookie_persist_enabled: true, visitor_autodetect_enabled: false });
  await setVisitorCookie(context, 'sv');

  await openPublic(page);
  await page.waitForURL(/\/sv\//, { timeout: 10_000 });
  expect(new URL(page.url()).pathname).toBe('/sv/');
});

test('malformed cookie value never triggers a redirect', async ({ page, context }) => {
  patchSettings({ visitor_cookie_persist_enabled: true, visitor_autodetect_enabled: false });
  await setVisitorCookie(context, '../../etc/passwd');

  await openPublic(page);
  await page.waitForTimeout(500);
  expect(new URL(page.url()).pathname).toBe('/');
});

test('unsupported (well-formed but unrouted) cookie value never triggers a redirect', async ({ page, context }) => {
  patchSettings({ visitor_cookie_persist_enabled: true, visitor_autodetect_enabled: false });
  await setVisitorCookie(context, 'fr');

  await openPublic(page);
  await page.waitForTimeout(500);
  expect(new URL(page.url()).pathname).toBe('/');
});

test('cookie-driven redirect only reads the cookie, never rewrites it', async ({ page, context }) => {
  patchSettings({ visitor_cookie_persist_enabled: true, visitor_autodetect_enabled: false });
  await setVisitorCookie(context, 'sv');

  await openPublic(page);
  await page.waitForURL(/\/sv\//, { timeout: 10_000 });

  const cookies = await context.cookies();
  const cookie = cookies.find((c) => c.name === COOKIE_NAME);
  expect(cookie?.value).toBe('sv');
  // Host-only: Playwright reports the exact host with no leading dot.
  expect(cookie?.domain).toBe(HOST);
});

test('explicit selector click writes and refreshes the visitor cookie', async ({ page, context }) => {
  patchSettings({
    visitor_cookie_persist_enabled: true,
    visitor_autodetect_enabled: false,
    floating_selector_enabled: true,
    floating_selector_show_desktop: true,
    floating_selector_show_mobile: true,
  });
  await context.clearCookies();

  await openPublic(page);
  const nav = page.locator(SELECTOR);
  await expect(nav).toHaveCount(1);

  const enhanced = await nav.getAttribute('data-aiml-enhanced');
  if (enhanced === '1') {
    await nav.locator('.aiml-floating-selector__toggle').click();
  }

  const other = nav.locator('a[data-aiml-code]:not([aria-current="page"])').first();
  const code = await other.getAttribute('data-aiml-code');
  expect(code).toBeTruthy();

  await other.click();
  await page.waitForLoadState('domcontentloaded');

  const value = await readVisitorCookie(context);
  expect(value).toBe(code);
});

test('persistence disabled: selector click does not write a cookie', async ({ page, context }) => {
  patchSettings({
    visitor_cookie_persist_enabled: false,
    visitor_autodetect_enabled: false,
    floating_selector_enabled: true,
    floating_selector_show_desktop: true,
    floating_selector_show_mobile: true,
  });
  await context.clearCookies();

  await openPublic(page);
  const nav = page.locator(SELECTOR);
  const enhanced = await nav.getAttribute('data-aiml-enhanced');
  if (enhanced === '1') {
    await nav.locator('.aiml-floating-selector__toggle').click();
  }
  const other = nav.locator('a[data-aiml-code]:not([aria-current="page"])').first();
  await other.click();
  await page.waitForLoadState('domcontentloaded');

  const value = await readVisitorCookie(context);
  expect(value).toBeUndefined();
});

test('anonymous render never emits a Set-Cookie header for the visitor-language cookie', async ({ page, context }) => {
  patchSettings({ visitor_cookie_persist_enabled: true, visitor_autodetect_enabled: true, visitor_autodetect_browser_enabled: true, floating_selector_enabled: false });
  await context.clearCookies();

  const headers: string[] = [];
  page.on('response', (response) => {
    if (response.url().replace(/\?.*$/, '').endsWith('/')) {
      const raw = response.headers()['set-cookie'];
      if (raw) {
        headers.push(raw);
      }
    }
  });

  await openPublic(page);
  const cookieHeaders = headers.filter((h) => h.includes(COOKIE_NAME));
  expect(cookieHeaders).toEqual([]);
});

test('proxy cache is unaffected by the visitor-language cookie', async ({ page, context }) => {
  await context.clearCookies();
  const withoutCookie = await page.request.get('/');
  expect(withoutCookie.headers()['x-bp-cache']).toBeTruthy();

  await setVisitorCookie(context, 'sv');
  const withCookie = await page.request.get('/');
  expect(withCookie.headers()['x-bp-cache']).toBe('HIT');
});

test('browser suggestion: accepting writes the cookie as an explicit choice', async ({ page, context }) => {
  patchSettings({
    visitor_cookie_persist_enabled: true,
    visitor_autodetect_enabled: true,
    visitor_autodetect_browser_enabled: true,
    visitor_autodetect_geo_enabled: false,
    floating_selector_enabled: false,
  });
  await context.clearCookies();
  await page.addInitScript(() => {
    Object.defineProperty(window.navigator, 'language', { get: () => 'sv-SE' });
    Object.defineProperty(window.navigator, 'languages', { get: () => ['sv-SE'] });
  });

  await openPublic(page);
  const banner = page.locator(BANNER);
  await expect(banner).toHaveCount(1, { timeout: 10_000 });

  const accept = banner.locator('.aiml-visitor-suggest__accept');
  await expect(accept).toHaveAttribute('data-aiml-code', 'sv');
  await accept.click();
  await page.waitForLoadState('domcontentloaded');

  const value = await readVisitorCookie(context);
  expect(value).toBe('sv');
});

test('browser suggestion: dismissing does not create a preference', async ({ page, context }) => {
  patchSettings({
    visitor_cookie_persist_enabled: true,
    visitor_autodetect_enabled: true,
    visitor_autodetect_browser_enabled: true,
    visitor_autodetect_geo_enabled: false,
    floating_selector_enabled: false,
  });
  await context.clearCookies();
  await page.addInitScript(() => {
    Object.defineProperty(window.navigator, 'language', { get: () => 'sv-SE' });
    Object.defineProperty(window.navigator, 'languages', { get: () => ['sv-SE'] });
  });

  await openPublic(page);
  const banner = page.locator(BANNER);
  await expect(banner).toHaveCount(1, { timeout: 10_000 });

  await banner.locator('.aiml-visitor-suggest__dismiss').click();
  await expect(banner).toHaveCount(0);

  const value = await readVisitorCookie(context);
  expect(value).toBeUndefined();
});

test('autodetect disabled: no banner appears even with a matching browser language', async ({ page, context }) => {
  patchSettings({ visitor_autodetect_enabled: false, floating_selector_enabled: false });
  await context.clearCookies();
  await page.addInitScript(() => {
    Object.defineProperty(window.navigator, 'language', { get: () => 'sv-SE' });
    Object.defineProperty(window.navigator, 'languages', { get: () => ['sv-SE'] });
  });

  await openPublic(page);
  await page.waitForTimeout(500);
  await expect(page.locator(BANNER)).toHaveCount(0);
});
