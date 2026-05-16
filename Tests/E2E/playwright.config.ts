import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for workos_auth E2E tests.
 *
 * The suite requires a running TYPO3 site with the extension
 * installed and configured. Point E2E_BASE_URL at that site before
 * running the tests. When left unset we default to the DDEV
 * convention (`https://workos-auth.ddev.site`).
 */
export default defineConfig({
    testDir: './specs',
    outputDir: '../../var/playwright',
    timeout: 30_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'https://workos-auth.ddev.site',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
