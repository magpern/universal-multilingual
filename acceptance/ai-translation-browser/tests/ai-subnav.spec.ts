import { test, expect } from '@playwright/test';

/**
 * AIT1 — shared internal admin navigation (`.aiml-ui-subnav`) across every
 * Universal Multilingual admin screen.
 */

const SECTIONS: Array<{ label: string; slug: string }> = [
  { label: 'Languages', slug: 'ai-multilingual' },
  { label: 'Settings', slug: 'aiml-settings' },
  { label: 'Limited Rollout', slug: 'aiml-rollout' },
  { label: 'SEO Diagnostics', slug: 'aiml-seo-diagnostics' },
  { label: 'Translate', slug: 'aiml-translate' },
  { label: 'Workspace', slug: 'aiml-translator' },
  { label: 'Glossary', slug: 'aiml-glossary' },
  { label: 'Translation Promotion', slug: 'aiml-promotion' },
];

test.describe('AIT1 — internal admin navigation', () => {
  test('the subnav is present on Settings with every section (admin)', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=aiml-settings', { waitUntil: 'domcontentloaded' });
    const nav = page.locator('nav.aiml-ui-subnav');
    await expect(nav).toBeVisible();
    for (const s of SECTIONS) {
      await expect(nav.getByRole('link', { name: s.label, exact: true })).toBeVisible();
    }
    // Settings is the active tab.
    await expect(nav.locator('a.aiml-ui-subnav__link--active')).toHaveText('Settings');
    await expect(nav.locator('a[aria-current="page"]')).toHaveCount(1);
  });

  test('clicking a tab navigates and the active tab updates', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=aiml-settings', { waitUntil: 'domcontentloaded' });

    for (const label of ['Glossary', 'Workspace', 'Languages']) {
      await page.locator('nav.aiml-ui-subnav').getByRole('link', { name: label, exact: true }).click();
      await page.waitForLoadState('domcontentloaded');
      await expect(page.locator('nav.aiml-ui-subnav a.aiml-ui-subnav__link--active')).toHaveText(label);
    }
  });

  test('every section screen renders the subnav with itself marked active', async ({ page }) => {
    for (const s of SECTIONS) {
      await page.goto(`/wp-admin/admin.php?page=${s.slug}`, { waitUntil: 'domcontentloaded' });
      const active = page.locator('nav.aiml-ui-subnav a.aiml-ui-subnav__link--active');
      await expect(active).toHaveText(s.label);
    }
  });

  test('the subnav wraps without horizontal page overflow at a narrow width', async ({ page }) => {
    await page.setViewportSize({ width: 700, height: 900 });
    await page.goto('/wp-admin/admin.php?page=aiml-translator', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('nav.aiml-ui-subnav')).toBeVisible();
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(1);
  });

  test('a restricted-capability user only sees the sections they can reach', async ({ browser }) => {
    // AIML_RESTRICTED_STATE (JSON {user,pass}) is provisioned by tools/run.sh:
    // a user with aiml_translate + aiml_workspace_access only.
    const raw = process.env.AIML_RESTRICTED_STATE;
    test.skip(!raw, 'restricted user not provisioned');
    const creds = JSON.parse(raw as string) as { user: string; pass: string };

    const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    const base = process.env.WP_BASE_URL ?? 'https://dev.biopentra.eu';
    await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await page.fill('#user_login', creds.user);
    await page.fill('#user_pass', creds.pass);
    await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);

    await page.goto(`${base}/wp-admin/admin.php?page=aiml-translator`, { waitUntil: 'domcontentloaded' });
    const nav = page.locator('nav.aiml-ui-subnav');
    await expect(nav).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Workspace', exact: true })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Settings', exact: true })).toHaveCount(0);
    await expect(nav.getByRole('link', { name: 'SEO Diagnostics', exact: true })).toHaveCount(0);
    await expect(nav.getByRole('link', { name: 'Limited Rollout', exact: true })).toHaveCount(0);
    await ctx.close();
  });
});
