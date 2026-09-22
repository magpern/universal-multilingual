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

/**
 * The site's cookie-consent banner overlays the page and can intercept
 * clicks (e.g. on "Add to cart") until dismissed. Clearing cookies (as most
 * tests in this suite do, to start from a clean anonymous state) also
 * clears any prior consent choice, so the banner reappears. Best-effort:
 * does nothing if the banner isn't present.
 */
async function dismissCookieConsentIfPresent(page: Page): Promise<void> {
  const accept = page.locator('.cky-btn-accept').first();
  try {
    await accept.click({ timeout: 3_000 });
  } catch (e) {
    // Not present, or already dismissed — nothing to do.
  }
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
  // Host-only cookie proof: Playwright's CDP-backed cookie API reports the
  // exact host with no leading dot only for a genuinely host-only cookie —
  // a cookie with an explicit Domain attribute (even Domain=dev.biopentra.eu)
  // is reported with a leading dot by the same API, so this assertion does
  // distinguish the two. A true cross-host non-delivery test (proving the
  // browser never sends this cookie to a different real host) would need a
  // second live hostname pointed at this deployment; none exists (the SWAG
  // vhost is `server_name _;`, a catch-all, not a dedicated second domain),
  // and inventing one is out of scope for a test. Combined with the
  // implementation never setting `Domain` (verified directly in both
  // visitor-language.js's writeCookie() and VisitorLanguageCookie::
  // write_cookie()'s option array, neither of which includes a 'domain'
  // key), this is treated as verified-by-construction rather than an open item.
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

test('geo fallback: suggests a mapped language only when browser produced no match', async ({ page, context }) => {
  patchSettings({
    visitor_cookie_persist_enabled: true,
    visitor_autodetect_enabled: true,
    visitor_autodetect_browser_enabled: true,
    visitor_autodetect_geo_enabled: true,
    geo_language_map: { SE: 'sv' },
    floating_selector_enabled: false,
  });
  await context.clearCookies();
  // 'fr-FR' matches no enabled language, so browser detection must fall
  // through to geo, per the frozen precedence (browser beats geo).
  await page.addInitScript(() => {
    Object.defineProperty(window.navigator, 'language', { get: () => 'fr-FR' });
    Object.defineProperty(window.navigator, 'languages', { get: () => ['fr-FR'] });
  });
  await context.route('**/wp-json/universal-geo-context/v1/context**', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ country_code: 'SE' }) });
  });

  await openPublic(page);
  const banner = page.locator(BANNER);
  await expect(banner).toHaveCount(1, { timeout: 10_000 });

  const accept = banner.locator('.aiml-visitor-suggest__accept');
  await expect(accept).toHaveAttribute('data-aiml-code', 'sv');
});

test('geo fallback: an unmapped country produces no suggestion', async ({ page, context }) => {
  patchSettings({
    visitor_cookie_persist_enabled: true,
    visitor_autodetect_enabled: true,
    visitor_autodetect_browser_enabled: true,
    visitor_autodetect_geo_enabled: true,
    geo_language_map: { SE: 'sv' },
    floating_selector_enabled: false,
  });
  await context.clearCookies();
  await page.addInitScript(() => {
    Object.defineProperty(window.navigator, 'language', { get: () => 'fr-FR' });
    Object.defineProperty(window.navigator, 'languages', { get: () => ['fr-FR'] });
  });
  // Country not present in geo_language_map.
  await context.route('**/wp-json/universal-geo-context/v1/context**', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ country_code: 'FR' }) });
  });

  await openPublic(page);
  await page.waitForTimeout(2000);
  await expect(page.locator(BANNER)).toHaveCount(0);
});

test('same tab: a second explicit language change still redirects from a later unprefixed visit', async ({ page, context }) => {
  // Reproduces the reported sequence: cookie=sv redirects /->/sv/, the
  // visitor then explicitly changes to de (cookie becomes de), and a LATER
  // unprefixed visit in the same tab must still redirect — to /de/, not be
  // silently suppressed by a stale "already redirected once" guard.
  patchSettings({ visitor_cookie_persist_enabled: true, visitor_autodetect_enabled: false });
  await setVisitorCookie(context, 'sv');

  await openPublic(page);
  await page.waitForURL(/\/sv\//, { timeout: 10_000 });

  // Simulate the visitor's explicit selector click changing the cookie to
  // 'de' (the click-persist mechanics themselves are covered by the
  // "explicit selector click" test above; this test is specifically about
  // the redirect guard, not re-testing the click).
  await setVisitorCookie(context, 'de');

  await openPublic(page);
  await page.waitForURL(/\/de\//, { timeout: 10_000 });
  expect(new URL(page.url()).pathname).toBe('/de/');
});

test('cookie-driven redirect preserves multiple query parameters and a hash losslessly', async ({ page, context }) => {
  patchSettings({ visitor_cookie_persist_enabled: true, visitor_autodetect_enabled: false });
  await setVisitorCookie(context, 'sv');

  // Bracketed array notation (filter[0]=a&filter[1]=b), not bare repeated
  // keys (filter=a&filter=b). This proves distinct query keys (including
  // the array-style form WooCommerce filter widgets actually use) and a
  // hash survive the redirect losslessly.
  //
  // Bare repeated identical keys are NOT covered by an end-to-end test
  // here, and cannot meaningfully be: WordPress core's own
  // redirect_canonical() 301s ?filter=a&filter=b to a single ?filter=b
  // BEFORE any client-side script (this plugin's or otherwise) ever sees
  // it — confirmed directly against the live site, and true for every
  // WordPress request, not something specific to this plugin. A real
  // visitor's browser therefore can never actually hand this plugin's
  // script a same-key-repeated query string on this stack; there is
  // nothing to lose losslessly in practice. The implementation still
  // preserves duplicates correctly BY CONSTRUCTION if it ever did receive
  // them — withCurrentQueryAndHash() copies window.location.search
  // through as a raw string and never re-serializes it via
  // URLSearchParams.set() (the specific operation that collapses
  // duplicates) — verified by inspection, not restated here as an
  // unreachable/untestable live-navigation assertion.
  await page.goto(`/?filter%5B0%5D=a&filter%5B1%5D=b&${PROBE}#section`, { waitUntil: 'domcontentloaded' });
  await page.waitForURL(/\/sv\//, { timeout: 10_000 });

  const url = new URL(page.url());
  expect(url.pathname).toBe('/sv/');
  expect(url.searchParams.getAll('filter[0]')).toEqual(['a']);
  expect(url.searchParams.getAll('filter[1]')).toEqual(['b']);
  expect(url.hash).toBe('#section');
});

test('WooCommerce: cart contents survive an explicit language switch', async ({ page, context }) => {
  patchSettings({
    visitor_cookie_persist_enabled: true,
    visitor_autodetect_enabled: false,
    floating_selector_enabled: true,
    floating_selector_show_desktop: true,
    floating_selector_show_mobile: true,
  });
  await context.clearCookies();

  // 'networkidle', not 'domcontentloaded': the theme's add-to-cart click
  // handler is attached by async-loaded JS chunks, so a click issued right
  // after domcontentloaded can silently do nothing (confirmed directly
  // against the live site — no add-to-cart request fires at all).
  await page.goto(`/product/bpc-157/?${PROBE}`, { waitUntil: 'networkidle' });
  await dismissCookieConsentIfPresent(page);
  await page.locator('.single_add_to_cart_button').click();
  await page.waitForTimeout(2_000);

  await page.goto(`/cart/?${PROBE}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.woocommerce-cart-form')).toBeVisible({ timeout: 10_000 });

  const nav = page.locator(SELECTOR);
  await expect(nav).toHaveCount(1);
  const enhanced = await nav.getAttribute('data-aiml-enhanced');
  if (enhanced === '1') {
    await nav.locator('.aiml-floating-selector__toggle').click();
  }
  const other = nav.locator('a[data-aiml-code]:not([aria-current="page"])').first();
  await other.click();
  await page.waitForLoadState('domcontentloaded');

  // Still on a /cart/ page (now language-prefixed), with the item intact.
  expect(new URL(page.url()).pathname).toMatch(/\/cart\/?$/);
  await expect(page.locator('.woocommerce-cart-form')).toBeVisible({ timeout: 10_000 });
});

test('geo fallback fails safe on a malformed/errored response', async ({ page, context }) => {
  patchSettings({
    visitor_cookie_persist_enabled: true,
    visitor_autodetect_enabled: true,
    visitor_autodetect_browser_enabled: true,
    visitor_autodetect_geo_enabled: true,
    geo_language_map: { SE: 'sv' },
    floating_selector_enabled: false,
  });
  await context.clearCookies();
  await page.addInitScript(() => {
    Object.defineProperty(window.navigator, 'language', { get: () => 'fr-FR' });
    Object.defineProperty(window.navigator, 'languages', { get: () => ['fr-FR'] });
  });
  await context.route('**/wp-json/universal-geo-context/v1/context**', async (route) => {
    await route.fulfill({ status: 500, contentType: 'text/plain', body: 'error' });
  });

  await openPublic(page);
  await page.waitForTimeout(2000);
  await expect(page.locator(BANNER)).toHaveCount(0);
});
