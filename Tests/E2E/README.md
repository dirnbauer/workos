# E2E tests

Playwright smoke tests for the `workos_auth` frontend plugin.

The suite depends on a running TYPO3 instance where the extension
is installed, configured, and the Login plugin is placed on a
public page.

## Run locally (DDEV)

```bash
cd Tests/E2E
npm install
npx playwright install --with-deps chromium
E2E_BASE_URL=https://workos-auth.ddev.site E2E_LOGIN_PATH=/login npm test
```

## Run in CI

`E2E_BASE_URL` / `E2E_LOGIN_PATH` flow in via environment variables
from the workflow job. The job itself is not wired into
`.github/workflows/tests.yml` yet — bring your own site URL (for
example a preview deployment) before enabling it.

## Notes

- Tests never submit real credentials. They only verify that the
  rendered HTML exposes the expected elements.
- The `returnTo` smoke test hits the open-redirect regression that
  already has unit coverage; the E2E version proves the server
  actually applies the sanitiser in production mode.
