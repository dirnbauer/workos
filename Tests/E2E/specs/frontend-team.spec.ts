import { expect, test } from '@playwright/test';

/**
 * Smoke tests for the WorkOS Team plugin. Place the plugin on a page and
 * set E2E_TEAM_PATH to that path (default `/team`).
 */

const teamPath = process.env.E2E_TEAM_PATH ?? '/team';

test.describe('WorkOS Team plugin', () => {
    test('renders the plugin root and guest sign-in hint when anonymous', async ({
        page,
    }) => {
        const response = await page.goto(teamPath, {
            waitUntil: 'domcontentloaded',
        });
        expect(response, 'team page must load').not.toBeNull();
        expect(response!.status(), 'expect 200 from TYPO3 page').toBe(200);

        const root = page.locator('.workos-team');
        await expect(root).toBeVisible();

        await expect(
            page.getByText(/sign in to access the team workspace/i)
        ).toBeVisible();
    });
});
