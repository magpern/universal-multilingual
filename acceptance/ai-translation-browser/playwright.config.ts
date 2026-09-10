import { defineConfig, devices } from '@playwright/test';
import path from 'path';

const artifactsDir = path.join(__dirname, 'artifacts');

export default defineConfig({
  testDir: './tests',
  timeout: 180_000,
  expect: { timeout: 30_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [
    ['list'],
    ['json', { outputFile: path.join(artifactsDir, 'ai-translation-report.json') }],
  ],
  globalSetup: require.resolve('./global-setup'),
  use: {
    baseURL: process.env.WP_BASE_URL ?? 'https://dev.biopentra.eu',
    headless: true,
    ignoreHTTPSErrors: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    storageState: path.join(artifactsDir, 'storage-state.json'),
  },
  projects: [
    {
      name: 'ai-translation',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
    },
  ],
});
