import { expect, test } from '@playwright/test';

/**
 * Smoke tests for the WorkOS Account Center plugin. Place the plugin on
 * a page and set E2E_ACCOUNT_PATH to that path (default `/account`).
 */

const accountPath = process.env.E2E_ACCOUNT_PATH ?? '/account';

test.describe('WorkOS Account Center plugin', () => {
    test('renders the plugin root and guest sign-in hint when anonymous', async ({
        page,
    }) => {
        const response = await page.goto(accountPath, {
            waitUntil: 'domcontentloaded',
        });
        expect(response, 'account page must load').not.toBeNull();
        expect(response!.status(), 'expect 200 from TYPO3 page').toBe(200);

        const root = page.locator('.workos-account');
        await expect(root).toBeVisible();

        await expect(
            page.getByText(/sign in to manage your workos account/i)
        ).toBeVisible();
    });
});
