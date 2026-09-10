import { test, expect } from '@playwright/test';

const fixtures = JSON.parse(process.env.AIML_FIXTURES ?? '{}') as { plain: number };
const LANG = 'sv';

test('12+13: with AI disabled the CTA is visible but disabled with a Configure link', async ({ page }) => {
  await page.goto(`/wp-admin/admin.php?page=aiml-translator&post_id=${fixtures.plain}&language=${LANG}`, {
    waitUntil: 'domcontentloaded',
  });
  const btn = page.getByRole('button', { name: /^Translate with AI$/ });
  await expect(btn).toBeVisible();
  await expect(btn).toBeDisabled();
  await expect(page.locator('.aiml-ui-actionbar__hint')).toContainText(/not configured/i);
  await expect(page.getByRole('link', { name: 'Configure AI settings' })).toBeVisible();
});
