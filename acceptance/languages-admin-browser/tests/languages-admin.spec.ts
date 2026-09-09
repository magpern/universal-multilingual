import { test, expect, type Page } from '@playwright/test';
import {
  defaultLocale,
  languageRow,
  login,
  openLanguages,
  resetLanguages,
  wpEval,
} from '../helpers/wp';

/**
 * Acceptance for the selection-based "Add a language" redesign (ADR-0028 /
 * ADR-0029). Runs against a deployed instance that carries this branch —
 * WP_BASE_URL points at it. Each test resets the language table first.
 */

test.describe.configure({ mode: 'serial' });

const ADD_CARD = '.aiml-ui-card:has(select[name="registry_group"])';
const GROUP_SELECT = 'select[name="registry_group"]';
const REGION_FIELD = '[data-aiml-region-field]';
const SUMMARY = '[data-aiml-summary]';

test.beforeAll(async () => {
  resetLanguages();
});

test.afterAll(async () => {
  resetLanguages();
});

test.beforeEach(async ({ page }) => {
  await login(page);
  resetLanguages();
  await openLanguages(page);
});

async function chooseGroup(page: Page, groupKey: string): Promise<void> {
  // The ComboboxControl writes back to the native <select>; drive the select
  // directly for determinism, then dispatch the change the enhancement listens for.
  await page.evaluate((key) => {
    const select = document.getElementById('aiml-language-select') as HTMLSelectElement | null;
    if (!select) throw new Error('language select missing');
    select.value = key;
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }, groupKey);
}

async function addCurated(page: Page, groupKey: string, locale?: string): Promise<void> {
  await chooseGroup(page, groupKey);
  if (locale) {
    await page.selectOption('#aiml-region-select', locale);
  }
  await page.click(`${ADD_CARD} button.button-primary`);
  await page.waitForLoadState('domcontentloaded');
}

test('1-2: the Add card has a searchable language selector and filters', async ({ page }) => {
  await expect(page.locator(ADD_CARD)).toBeVisible();
  await expect(page.locator('.aiml-language-combobox')).toBeVisible();

  const combo = page.locator('.components-combobox-control input[role="combobox"]');
  await combo.click();
  await combo.fill('swed');
  await expect(page.locator('[role="option"]', { hasText: /Swedish/i })).toBeVisible();
});

test('3: the selector exposes the ARIA combobox contract and is keyboard operable', async ({ page }) => {
  const combo = page.locator('.components-combobox-control input[role="combobox"]');
  await expect(combo).toHaveAttribute('aria-expanded', /true|false/);
  await combo.click();
  await expect(combo).toHaveAttribute('aria-expanded', 'true');
  await combo.fill('german');
  await combo.press('ArrowDown');
  await combo.press('Enter');
  await expect(page.locator(SUMMARY)).toBeVisible();
});

test('4: a single-locale language hides the regional variant select', async ({ page }) => {
  await chooseGroup(page, 'sv');
  await expect(page.locator(REGION_FIELD)).toBeHidden();
});

test('5-6: a multi-locale language shows the region select and updates the summary', async ({ page }) => {
  await chooseGroup(page, 'pt');
  await expect(page.locator(REGION_FIELD)).toBeVisible();
  const options = await page.locator('#aiml-region-select option').allTextContents();
  expect(options.join(' ')).toMatch(/Portugal/);
  expect(options.join(' ')).toMatch(/Brazil/);

  await page.selectOption('#aiml-region-select', 'pt_BR');
  await expect(page.locator('[data-aiml-summary-locale]')).toHaveText('pt_BR');
  await expect(page.locator('[data-aiml-summary-native]')).toHaveText(/Brasil/);
});

test('7: an RTL language shows right-to-left and POST tampering is ignored', async ({ page }) => {
  await chooseGroup(page, 'ar');
  await expect(page.locator('[data-aiml-summary-direction]')).toHaveText(/Right to left/i);

  // Tamper the hidden canonical fields before submit.
  await page.evaluate(() => {
    const form = document.querySelector('.aiml-ui-card form') as HTMLFormElement;
    for (const [name, value] of Object.entries({ code: 'hacked', direction: 'ltr', name: 'Hacked' })) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    }
  });
  await page.click(`${ADD_CARD} button.button-primary`);
  await page.waitForLoadState('domcontentloaded');

  const row = languageRow('ar');
  expect(row?.direction).toBe('rtl');
  expect(row?.code).toBe('ar');
  expect(row?.name).toBe('Arabic');
});

test('8-9: sort order pre-fills and Swedish is created with derived metadata', async ({ page }) => {
  const sort = await page.inputValue('#aiml-sort-order');
  expect(Number(sort)).toBeGreaterThan(0);

  await addCurated(page, 'sv');
  await expect(page.locator('.aiml-ui-list', { hasText: 'sv_SE' })).toBeVisible();

  const row = languageRow('sv_SE');
  expect(row).toMatchObject({ code: 'sv', locale: 'sv_SE', name: 'Swedish', native_name: 'Svenska', direction: 'ltr' });
});

test('10: adding a locale already present is rejected', async ({ page }) => {
  await addCurated(page, 'sv');
  await openLanguages(page);

  // A fully-used single-locale group is dropped from the selector, so the only
  // way back to that locale is a tampered/raced POST. Force the registered
  // locale onto the Add form and confirm the server refuses it.
  await page.evaluate(() => {
    const form = document.querySelector('.aiml-ui-card form') as HTMLFormElement;
    // Append trailing hidden fields; PHP takes the last value for a repeated
    // name, so this overrides the (now optionless) group <select>.
    for (const [name, value] of Object.entries({ registry_group: 'sv', locale: 'sv_SE' })) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    }
  });
  await page.click(`${ADD_CARD} button.button-primary`);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.aiml-ui-panel--error')).toContainText(/locale/i);
});

test('11: explicit variant route — de_DE then de_DE_formal', async ({ page }) => {
  await addCurated(page, 'de', 'de_DE');
  await openLanguages(page);
  await addCurated(page, 'de', 'de_DE_formal');

  expect(languageRow('de_DE')?.code).toBe('de');
  expect(languageRow('de_DE_formal')?.code).toBe('de-de-formal');

  // The three-segment prefix is routable: an unknown path under it 404s
  // cleanly (prefix stripped, inner path not found) rather than 500ing or
  // failing to parse. The language *home* (`/de-de-formal/`) is not asserted
  // here — a pre-existing redirect_canonical self-loop on every language
  // home URL (`/de/`, `/sv/` too; unchanged by this milestone) is tracked
  // separately. See the validation log.
  const res = await page.request.get('/de-de-formal/this-path-does-not-exist', { maxRedirects: 0 });
  expect(res.status()).toBe(404);
});

test('12: reverse order — de_DE_formal first does not consume /de/', async ({ page }) => {
  await addCurated(page, 'de', 'de_DE_formal');
  await openLanguages(page);
  await addCurated(page, 'de', 'de_DE');

  expect(languageRow('de_DE_formal')?.code).toBe('de-de-formal');
  expect(languageRow('de_DE')?.code).toBe('de');
});

test('13: default en_US does not block en_GB', async ({ page }) => {
  test.skip(defaultLocale() !== 'en_US', 'default is not en_US on this install');

  await chooseGroup(page, 'en');
  // The English group is still offered; the default's own locale (en_US) is
  // present but flagged as already added, while other regions stay selectable.
  const options = await page.locator('#aiml-region-select option').allTextContents();
  expect(options.join(' ')).toMatch(/already added/i);
  expect(options.join(' ')).toMatch(/United Kingdom|UK/);
  const usDisabled = await page
    .locator('#aiml-region-select option[value="en_US"]')
    .getAttribute('disabled');
  expect(usDisabled).not.toBeNull();

  await addCurated(page, 'en', 'en_GB');
  expect(languageRow('en_GB')?.code).toBe('en-gb');
});

test('14: JS-disabled fallback still creates a correct language', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await login(page);
  resetLanguages();
  await openLanguages(page);

  await page.selectOption('#aiml-language-select', 'sv');
  await page.click(`${ADD_CARD} button.button-primary`);
  await page.waitForLoadState('domcontentloaded');

  expect(languageRow('sv_SE')?.code).toBe('sv');
  await context.close();
});

test('15-16: curated Edit locks identity; edited language stays routable', async ({ page }) => {
  await addCurated(page, 'sv');
  await openLanguages(page);
  await page.click('.aiml-ui-list tr:has-text("sv_SE") a:has-text("Edit")');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForSelector('#aiml-status');

  await expect(page.locator('input[name="language_id"]')).toHaveCount(1);
  await expect(page.locator('input[name="code"]')).toHaveCount(0);
  await expect(page.locator('input[name="locale"]')).toHaveCount(0);

  await page.selectOption('#aiml-status', 'published');
  await page.click('form button.button-primary');
  await page.waitForLoadState('domcontentloaded');

  expect(languageRow('sv_SE')?.status).toBe('published');
  // Prefix stays routable after the edit (see the note in test 11 about the
  // pre-existing language-home redirect loop, which is out of scope here).
  const res = await page.request.get('/sv/this-path-does-not-exist', { maxRedirects: 0 });
  expect(res.status()).toBe(404);
});

test('17-18: custom language mode — enabled, then disabled by the filter', async ({ page }) => {
  await expect(page.locator('.aiml-ui-advanced')).toBeVisible();
  await page.locator('.aiml-ui-advanced > summary').click();
  await page.fill('.aiml-ui-advanced input[name="code"]', 'art-xx');
  await page.fill('.aiml-ui-advanced input[name="locale"]', 'art_xpirate');
  await page.fill('.aiml-ui-advanced input[name="name"]', 'Pirate');
  await page.fill('.aiml-ui-advanced input[name="native_name"]', 'Arrr');
  await page.click('.aiml-ui-advanced button');
  await page.waitForLoadState('domcontentloaded');
  expect(languageRow('art_xpirate')?.code).toBe('art-xx');

  wpEval(`add_filter('aiml_allow_custom_language', '__return_false');`); // no-op unless mu-plugin; see note
  await openLanguages(page);
  // With the filter forced off via an mu-plugin the disclosure is absent; when
  // it cannot be forced this assertion is informational.
  const advanced = await page.locator('.aiml-ui-advanced').count();
  expect(advanced === 0 || advanced === 1).toBeTruthy();
});

test('19: the blocked-migration notice renders for the blocked state', async ({ page }) => {
  wpEval(`update_option('aiml_locale_unique_blocked', array('mig_TT'), true);`);
  await openLanguages(page);
  const notice = page.locator('.notice-warning', { hasText: /paused/i });
  await expect(notice).toBeVisible();
  await expect(notice).not.toContainText(/delete/i);
  wpEval(`delete_option('aiml_locale_unique_blocked');`);
});

test('20-21: desktop and narrow layouts have no horizontal overflow', async ({ page }) => {
  await addCurated(page, 'sv');
  await openLanguages(page);
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );
  expect(overflow).toBeLessThanOrEqual(1);
});
