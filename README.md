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

## WorkOS authentication methods

The frontend login plugin supports several authentication methods.
Each method must be **enabled in the WorkOS Dashboard** before it works in TYPO3 — the extension only calls the WorkOS API, it does not control which methods are available.

### AuthKit (hosted login)

Enabled by default. Users are redirected to the WorkOS-hosted AuthKit page, which handles sign-in, sign-up, and social providers. No extra setup required beyond adding redirect URIs.

### Enabling authentication methods

All authentication methods are managed in the WorkOS Dashboard under **Authentication → Methods**.

![WorkOS Dashboard – Authentication Methods](Documentation/Images/workos-auth-methods.png)

Each method has an **Enable** / **Manage** button. Click it to open the configuration dialog, toggle it on, and click **Save changes**.

### Magic Auth (email code)

Sends a six-digit code to the user's email address. Codes expire after 10 minutes.

1. Open the [WorkOS Dashboard](https://dashboard.workos.com)
2. Go to **Authentication → Methods**
3. Find **Magic Auth** and click **Enable** (or **Manage** if already configured)
4. Toggle **Enable** on and click **Save changes**

![WorkOS Dashboard – Enable Magic Auth](Documentation/Images/workos-magic-auth-enable.png)

Once enabled, the "Email code" option on the TYPO3 login form will work immediately — no code changes needed.

### Email + Password

Users sign in with their email and password directly on the TYPO3 site.

1. Open the [WorkOS Dashboard](https://dashboard.workos.com)
2. Go to **Authentication → Methods**
3. Find **Email + Password** and click **Manage**
4. Configure password rules as needed

### Social login (Google, Microsoft, GitHub, Apple)

Social providers are configured in the WorkOS Dashboard and appear automatically on the TYPO3 login form.

1. Open the [WorkOS Dashboard](https://dashboard.workos.com)
2. Go to **Authentication → Providers**
3. Enable the providers you want (Google, Microsoft, GitHub, Apple)
4. Configure OAuth credentials for each provider as described in the WorkOS docs

Social login buttons redirect through AuthKit, so no additional redirect URIs are needed beyond the standard ones.

## Notes

- Backend auto-creation is intentionally off by default because granting TYPO3 backend access should be explicit.
- Frontend and backend logins use TYPO3 sessions after the WorkOS callback succeeds.
- Identity links are stored in `tx_workosauth_identity`.
