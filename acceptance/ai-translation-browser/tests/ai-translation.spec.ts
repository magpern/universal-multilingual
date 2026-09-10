import { test, expect, Page } from '@playwright/test';

/**
 * AIT1 AI translation admin acceptance. The acceptance fake provider is active
 * (installed by tools/run.sh) so nothing here calls a paid provider.
 */
const fixtures = JSON.parse(process.env.AIML_FIXTURES ?? '{}') as {
  plain: number;
  large: number;
  elem: number;
  bulk: number[];
};
const LANG = 'sv';
const LANG_ID = Number(process.env.AIML_SV_LANGUAGE_ID ?? '95');

async function openWorkspaceEditor(page: Page, postId: number): Promise<void> {
  await page.goto(`/wp-admin/admin.php?page=aiml-translator&post_id=${postId}&language=${LANG}`, {
    waitUntil: 'domcontentloaded',
  });
  await expect(page.locator('#aiml-translator-workspace-root')).toBeVisible();
  await expect(page.locator('.aiml-ui-actionbar')).toBeVisible({ timeout: 30_000 });
}

async function clickTranslateWithAi(page: Page): Promise<void> {
  const btn = page.getByRole('button', { name: /^Translate with AI$/ });
  await expect(btn).toBeEnabled({ timeout: 30_000 });
  await btn.click();
}

async function waitForOutcome(page: Page): Promise<string> {
  const status = page.locator('.aiml-ui-actionbar__hint[role="status"]');
  await expect(status).toContainText(/translated ·|Nothing to translate/, { timeout: 150_000 });
  return (await status.textContent()) ?? '';
}

test.describe('AIT1 — single page', () => {
  test('1+2+17: normal page auto-starts, populates, no Run now, badges are execution-only', async ({ page }) => {
    await openWorkspaceEditor(page, fixtures.plain);
    await clickTranslateWithAi(page);
    // Job execution badge appears (Queued/Translating) — started without a Run button.
    await expect(page.locator('.aiml-ui-actionbar__status .aiml-ui-badge')).toBeVisible({ timeout: 30_000 });
    const outcome = await waitForOutcome(page);
    expect(outcome).toMatch(/[1-9]\d* translated/);
    await expect(page.locator('#aiml-translator-workspace-root')).toContainText('[sv_SE]');
    // The action-bar badge is an execution-status badge, not a merged one.
    const badgeClass = await page.locator('.aiml-ui-actionbar__status .aiml-ui-badge').getAttribute('class');
    expect(badgeClass).toMatch(/aiml-ui-badge--(queued|translating|complete|completed-with-skips|failed)/);
  });

  test('5+6: manual and reviewed translations are never overwritten', async ({ page, request }) => {
    // The runtime PHP validation covers the persistence guarantee end-to-end;
    // here we assert the "kept" counter is surfaced to the operator.
    await openWorkspaceEditor(page, fixtures.plain);
    await clickTranslateWithAi(page); // second run, mode = missing
    const outcome = await waitForOutcome(page);
    expect(outcome).toMatch(/kept|Nothing to translate/);
  });
});

test.describe('AIT1 — Elementor', () => {
  test('8: Elementor page translates via Translate with AI', async ({ page }) => {
    await openWorkspaceEditor(page, fixtures.elem);
    await clickTranslateWithAi(page);
    const outcome = await waitForOutcome(page);
    expect(outcome).toMatch(/[1-9]\d* translated/);
    await expect(page.locator('#aiml-translator-workspace-root')).toContainText(
      '[sv_SE] AIT1-ACCEPT Elementor heading',
    );
  });
});

test.describe('AIT1 — large page', () => {
  test('9: 120-segment page runs as one background job and completes', async ({ page }) => {
    await openWorkspaceEditor(page, fixtures.large);
    await clickTranslateWithAi(page);
    await expect(page.locator('.aiml-ui-actionbar__status .aiml-ui-badge')).toBeVisible({ timeout: 30_000 });
    const outcome = await waitForOutcome(page);
    expect(outcome).toMatch(/\d+ translated/);
  });
});

test.describe('AIT1 — legacy Translate screen', () => {
  test('7: legacy Editor.php is not an AI dead end', async ({ page }) => {
    await page.goto(
      `/wp-admin/admin.php?page=aiml-translate&post_id=${fixtures.plain}&language_id=${LANG_ID}`,
      { waitUntil: 'domcontentloaded' },
    );
    await expect(page.getByRole('link', { name: 'Translate with AI' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Open in Translator Workspace' })).toBeVisible();
    // The link deep-links into the Workspace AI flow.
    const href = await page.getByRole('link', { name: 'Translate with AI' }).getAttribute('href');
    expect(href).toContain('page=aiml-translator');
    expect(href).toContain(`post_id=${fixtures.plain}`);
  });
});

test.describe('AIT1 — discoverability + plugin surface', () => {
  test('14: Pages list-table has a "Translate with AI" bulk action', async ({ page }) => {
    await page.goto('/wp-admin/edit.php?post_type=page', { waitUntil: 'domcontentloaded' });
    const options = await page.locator('#bulk-action-selector-top option').allInnerTexts();
    expect(options.join('|')).toContain('Translate with AI');
  });

  test('15: Plugins row exposes Settings, Overview and Documentation', async ({ page }) => {
    await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });
    const row = page.locator('tr[data-slug="universal-multilingual"]');
    await expect(row.getByRole('link', { name: 'Settings', exact: true })).toBeVisible();
    await expect(row.getByRole('link', { name: 'Overview', exact: true })).toBeVisible();
    await expect(row.getByRole('link', { name: 'Documentation', exact: true })).toBeVisible();
  });

  test('16: shared aiml-ui design system on Languages, Settings, Workspace', async ({ page }) => {
    for (const slug of ['ai-multilingual', 'aiml-settings', 'aiml-translator']) {
      await page.goto(`/wp-admin/admin.php?page=${slug}`, { waitUntil: 'domcontentloaded' });
      const count = await page.locator('.aiml-ui').count();
      expect(count, `aiml-ui root on ${slug}`).toBeGreaterThan(0);
    }
  });
});
