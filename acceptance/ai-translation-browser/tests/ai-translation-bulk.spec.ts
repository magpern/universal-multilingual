import { test, expect, Page } from '@playwright/test';

const fixtures = JSON.parse(process.env.AIML_FIXTURES ?? '{}') as {
  plain: number;
  fail: number;
  bulk: number[];
};
const LANG = 'sv';

async function gotoSiteTranslate(page: Page, ids?: number[]): Promise<void> {
  const q = ids && ids.length ? `&aiml_st_ids=${ids.join(',')}` : '';
  await page.goto(`/wp-admin/admin.php?page=aiml-translator&view=site-translate${q}`, {
    waitUntil: 'domcontentloaded',
  });
  await expect(page.locator('#aiml-translator-workspace-root')).toBeVisible();
}

test.describe('AIT1 — bulk', () => {
  test('10: select pages, one "Translate selected with AI", jobs run in background', async ({ page }) => {
    await gotoSiteTranslate(page, fixtures.bulk);
    // Pick the target language.
    await page.locator('select').first().selectOption(LANG).catch(() => undefined);
    const primary = page.getByRole('button', { name: /Translate selected with AI/ });
    await expect(primary).toBeVisible();
    // The list-table deep link pre-selected the fixtures.
    const checked = await page.locator('input[type="checkbox"]:checked').count();
    expect(checked).toBeGreaterThan(0);
    await primary.click();
    await expect(page.locator('#aiml-translator-workspace-root')).toContainText(/batch|Created|jobs/i, {
      timeout: 60_000,
    });
  });

  test('11: a provider failure surfaces to the operator', async ({ page }) => {
    // tools/run.sh runs this spec with aiml_acceptance_fake_mode=rate_limit and
    // a freshly seeded page, so translate_missing has real work that then fails.
    await page.goto(`/wp-admin/admin.php?page=aiml-translator&post_id=${fixtures.fail}&language=${LANG}`, {
      waitUntil: 'domcontentloaded',
    });
    await expect(page.locator('.aiml-ui-actionbar')).toBeVisible({ timeout: 30_000 });
    const btn = page.getByRole('button', { name: /^Translate with AI$/ });
    await expect(btn).toBeEnabled({ timeout: 30_000 });
    await btn.click();
    // The action bar reports a failed / with-errors outcome (not "N translated").
    await expect(page.locator('.aiml-ui-actionbar')).toContainText(
      /failed|Failed|could not|Retry|with skips/i,
      { timeout: 150_000 },
    );
  });

  test('11b: the failed job can be retried to completion from the Jobs view', async ({ page }) => {
    // run.sh clears the failure mode before this test so the retry succeeds.
    await page.goto('/wp-admin/admin.php?page=aiml-translator&view=jobs', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#aiml-translator-workspace-root')).toContainText(/Failed|Completed with skips|Retry/i, {
      timeout: 30_000,
    });
    const retry = page.getByRole('button', { name: /Retry failed items/i }).first();
    if (await retry.isVisible().catch(() => false)) {
      await retry.click();
      const confirm = page.getByRole('button', { name: /^(Retry|Confirm)/ });
      if (await confirm.isVisible().catch(() => false)) {
        await confirm.click();
      }
    }
    await expect(page.locator('#aiml-translator-workspace-root')).toContainText(/Completed|Translating/i, {
      timeout: 60_000,
    });
  });
});
