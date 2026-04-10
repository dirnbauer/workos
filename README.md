# WorkOS Auth for TYPO3 14

`workos_auth` adds WorkOS AuthKit login flows for both TYPO3 frontend users and TYPO3 backend users.

## What it does

- Adds a frontend plugin with a WorkOS sign-in / sign-out button.
- Adds a TYPO3 backend login provider with a "Continue with WorkOS" button.
- Creates or links TYPO3 users from WorkOS identities.
- Provides a backend setup assistant so the extension can be configured without editing PHP arrays by hand.

## PHP and TYPO3 target

- TYPO3: `^14.0`
- PHP: `^8.2`

## Configuration decision

This extension stores its settings in TYPO3 extension configuration under `EXTENSIONS.workos_auth`.

For Composer-based TYPO3 14 projects that means the values are persisted into `config/system/settings.php`, which is the correct place for installation-wide auth settings and secrets like:

- `apiKey`
- `clientId`
- `cookiePassword`
- auto-provisioning rules
- frontend / backend callback paths

I deliberately did not put this into site config or TypoScript because frontend and backend authentication are installation-wide concerns, and the WorkOS secret values belong in system configuration.

## Installation

1. Require the extension and install it in TYPO3.
2. Open `System > WorkOS Auth`.
3. Enter the WorkOS API key, client ID, and a cookie password with at least 32 characters.
4. Decide whether frontend and backend users should be linked by email or auto-created.
5. For frontend auto-creation, set a storage PID.
6. For backend auto-creation, set at least one backend group UID.
7. Add the generated callback URLs from the setup assistant to your WorkOS application.
8. Add the `WorkOS Login` frontend plugin to a page where editors or users should start the frontend login flow.

## Notes

- Backend auto-creation is intentionally off by default because granting TYPO3 backend access should be explicit.
- Frontend and backend logins use TYPO3 sessions after the WorkOS callback succeeds.
- Identity links are stored in `tx_workosauth_identity`.
