# WorkOS Auth for TYPO3 14

`workos_auth` adds [WorkOS](https://workos.com) authentication to both the
TYPO3 **frontend** and the TYPO3 **backend**. It supports the full WorkOS
AuthKit feature set: email + password, passwordless magic auth, and
social sign-in with Google, Microsoft, GitHub, and Apple.

Requirements: TYPO3 `^14.0`, PHP `^8.2`.

---

## What you get

| Area | Feature |
|---|---|
| Frontend plugin | Email + password form, magic-auth form, social buttons, self sign-up |
| Frontend plugin | Shows the current WorkOS profile (including custom metadata) when signed in |
| Backend login | "Continue with WorkOS" button, email-code login, social sign-in |
| Backend module | Setup assistant at `System > WorkOS Auth` (no PHP editing required) |
| Provisioning | Create or link TYPO3 users from WorkOS identities (frontend & backend) |
| Storage | Identity mapping table `tx_workosauth_identity` (with full WorkOS profile JSON) |
| Localization | English and German out of the box, XLIFF 1.2 with ICU MessageFormat |

## Quick install

```bash
composer require webconsulting/workos-auth
```

1. Activate the extension in TYPO3.
2. Open **System > WorkOS Auth** in the backend.
3. Enter your **API key**, **Client ID**, and a **cookie password** (≥ 32 characters).
4. Copy all **Redirect URIs** from the setup assistant into the WorkOS Dashboard.
5. In the WorkOS Dashboard, enable the authentication methods you need (Magic Auth, Email + Password, Social providers).
6. Add the **WorkOS Login** content element to a frontend page.

A detailed walk-through is in [`Documentation/Configuration.md`](Documentation/Configuration.md).

## Frontend login

Place the **WorkOS Login** plugin on a page and users get a ready-to-go card:

- Email + password form
- "Email me a login code" (magic auth, six-digit code)
- One-tap social buttons (Google, Microsoft, GitHub, Apple)
- Link to the native sign-up form

Signed-in users see their WorkOS profile, including any **custom metadata**
stored on the WorkOS user record.

Detailed feature guide: [`Documentation/Features.md`](Documentation/Features.md).

## Backend login

TYPO3's backend login gains a WorkOS section with:

- "Continue with WorkOS" (full AuthKit experience)
- Social sign-in buttons
- An email field that sends a six-digit magic-auth code
- A visible code-entry step for verification

Standard TYPO3 username + password login keeps working in parallel via
the "Login with username and password" switcher.

## WorkOS Dashboard setup

All authentication methods (AuthKit, Magic Auth, Email + Password, Social
providers) are **enabled in the WorkOS Dashboard**, not in TYPO3.

See [`Documentation/WorkosDashboard.md`](Documentation/WorkosDashboard.md)
for step-by-step screenshots of:

- Adding Redirect URIs
- Enabling Magic Auth
- Enabling social providers

## Dynamic AuthKit parameters

The frontend login URL accepts optional query parameters that customise
the AuthKit experience without changing any TYPO3 configuration:

| Query param | Value | Effect |
|---|---|---|
| `screen` | `sign-in` or `sign-up` | Open AuthKit on the given screen |
| `provider` | `GoogleOAuth`, `MicrosoftOAuth`, `GitHubOAuth`, `AppleOAuth` | Jump directly to one social provider |
| `login_hint` | Any email | Pre-fill the email field |
| `organization` | WorkOS organization id | Scope the login to an organization |
| `returnTo` | Target URL | Where to land after login |

Example — open the hosted sign-up screen pre-filled with an email:

```
/workos-auth/frontend/login?screen=sign-up&login_hint=jane@example.com
```

## Configuration location

Settings live in TYPO3 extension configuration under
`EXTENSIONS.workos_auth`. In a Composer-based TYPO3 14 project they are
persisted to `config/system/settings.php`, which is the right place for
installation-wide auth secrets.

The full list of keys is in
[`Documentation/Configuration.md`](Documentation/Configuration.md#all-configuration-keys).

## Documentation

- [Configuration](Documentation/Configuration.md) – Setup assistant, every config key
- [Features](Documentation/Features.md) – Frontend/backend flows, profile display, dynamic parameters
- [WorkOS Dashboard](Documentation/WorkosDashboard.md) – Redirect URIs and enabling auth methods
- [Troubleshooting](Documentation/Troubleshooting.md) – Common errors and fixes

## Licence

GPL-2.0-or-later
