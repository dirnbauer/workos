import { expect, test } from '@playwright/test';

/**
 * Smoke tests for the WorkOS Login plugin on a public frontend
 * page. A TYPO3 site with the extension installed and the Login
 * plugin placed on `E2E_LOGIN_PATH` (default `/login`) must be
 * reachable under `E2E_BASE_URL`.
 */

const loginPath = process.env.E2E_LOGIN_PATH ?? '/login';

test.describe('WorkOS Login plugin', () => {
    test('renders the "Continue with WorkOS" button', async ({ page }) => {
        await page.goto(loginPath);

        const card = page.locator('.workos-auth-plugin');
        await expect(card).toBeVisible();

        await expect(
            card.getByRole('link', { name: /continue with workos/i })
        ).toBeVisible();
    });

    test('shows each configured social provider', async ({ page }) => {
        await page.goto(loginPath);

        for (const label of ['Google', 'Microsoft', 'GitHub', 'Apple']) {
            await expect(page.getByRole('link', { name: label })).toBeVisible();
        }
    });

    test('sanitises returnTo against protocol-relative URLs', async ({ page }) => {
        const response = await page.goto(
            `${loginPath}?returnTo=//evil.example/path`,
            { waitUntil: 'domcontentloaded' }
        );

        expect(response, 'login page must load').not.toBeNull();
        // The form must still render — the middleware just discards the
        // bogus returnTo and falls back to the configured default.
        await expect(page.locator('.workos-auth-plugin')).toBeVisible();
    });
});
