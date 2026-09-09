import { defineConfig } from '@playwright/test';

export default defineConfig( {
	testDir: './tests',
	timeout: 90_000,
	retries: 0,
	use: {
		baseURL: process.env.AIML_PROMOTION_BASE_URL || 'http://127.0.0.1',
		headless: true,
	},
} );
