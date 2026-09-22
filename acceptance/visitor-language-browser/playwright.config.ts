import { defineConfig, devices } from '@playwright/test';
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
      // WebKit/Safari engine coverage. `devices['iPhone 12']` implies
      // WebKit as its browser engine. Requires WebKit's system shared
      // libraries; environments without them (and no passwordless sudo to
      // install them) cannot run this project — that is a WebKit-engine
      // environmental limitation, not a mobile-viewport gap, which
      // `mobile-375-chromium` below covers independently.
      name: 'mobile-375',
      use: { ...devices['iPhone 12'], viewport: { width: 375, height: 812 } },
    },
    {
      // Mobile-viewport coverage on Chromium, so 375px responsive UI/behavior
      // is exercised even where WebKit cannot launch. Deliberately does NOT
      // spread a `devices[...]` mobile preset (those default to WebKit) —
      // `isMobile`/`hasTouch` give Chromium's own mobile emulation without
      // changing the browser engine, matching this suite's other
      // Chromium-only, plain-viewport projects (`tablet-768`, `desktop-1440`).
      name: 'mobile-375-chromium',
      use: { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true },
    },
    {
      name: 'tablet-768',
      use: { viewport: { width: 768, height: 1024 } },
    },
    {
      name: 'desktop-1440',
      use: { viewport: { width: 1440, height: 900 } },
    },
  ],
});
