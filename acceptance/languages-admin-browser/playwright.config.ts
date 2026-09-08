import { defineConfig } from '@playwright/test';
import path from 'path';

const artifactsDir = path.join(__dirname, 'artifacts');

export default defineConfig({
  testDir: './tests',
  timeout: 90_000,
  expect: { timeout: 20_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['json', { outputFile: path.join(artifactsDir, 'report.json') }]],
  use: {
    baseURL: process.env.WP_BASE_URL ?? 'https://dev.biopentra.eu',
    headless: true,
    screenshot: 'only-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'desktop-1440',
      use: { viewport: { width: 1440, height: 900 } },
    },
    {
      name: 'narrow-390',
      use: { viewport: { width: 390, height: 844 } },
    },
  ],
});
