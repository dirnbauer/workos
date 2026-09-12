# WorkOS Auth for TYPO3

`workos_auth` adds [WorkOS](https://workos.com) AuthKit sign-in to the TYPO3
**frontend** and **backend**: email + password, magic-auth codes, social
sign-in (Google, Microsoft, GitHub, Apple) and enterprise SSO, plus
self-service **Account Center** and **Team** plugins, a **User Management**
backend widget and a WorkOS-protected **TYPO3 MCP server**.

## What it is

| Area | Feature |
|---|---|
| Frontend plugins | **WorkOS Login** (password, email code, social buttons, sign-up, email verification), **Account Center** (profile, password, TOTP MFA, sessions, organizations), **Team** (invitations, one-time Admin Portal links) |
| Backend | "Continue with WorkOS" login provider with email-code and social sign-in; **WorkOS** menu with *Setup Assistant*, *User Management* widget and *MCP Server* modules |
| Provisioning | Creates or links `fe_users` / `be_users` from WorkOS identities (`tx_workosauth_identity`); TYPO3's own auth service creates the sessions |
| MCP | Streamable HTTP endpoint `/workos-auth/mcp`: anonymous in `Development`, AuthKit bearer tokens in `Production` |
| Localization | English and German, XLIFF 2.0 with ICU placeholders |

## Requirements

- TYPO3 `^14.3.6`, PHP `^8.4`
- `workos/workos-php` `^9.3` (installed by Composer)
- A WorkOS account with AuthKit enabled

## Install

```bash
composer require webconsulting/workos-auth
vendor/bin/typo3 extension:setup --extension=workos_auth
```

## Configure

1. Open **WorkOS → Setup Assistant** in the backend and enter the **API key**,
   **Client ID** and a **cookie password** (≥ 32 characters, or let TYPO3
   generate one).
2. Copy the listed **Redirect URIs** into the WorkOS Dashboard under
   *Redirects*.
3. In the Dashboard enable what you use: *Authentication → Methods*
   (Email + Password, Magic Auth) and *Authentication → Providers*
   (Google, Microsoft, GitHub, Apple). Methods are enabled in WorkOS, not in
   TYPO3.
4. Optional: organization / connection / domain hints, frontend storage PID
   and default groups, backend auto-create with a domain allow-list, MCP
   mode and AuthKit domain.

Dashboard walk-through with screenshots:
[Documentation/WorkosDashboard.rst](Documentation/WorkosDashboard.rst).
Every key, default and validation rule:
[Documentation/Configuration.rst](Documentation/Configuration.rst).

## Use

- Add the **WorkOS Login** content element to a page. Signed-in users see
  their WorkOS profile. The login URL accepts `screen`, `provider`,
  `login_hint`, `organization` and `returnTo` query parameters
  ([dynamic AuthKit parameters](Documentation/Features.rst)).
- Add **WorkOS Account Center** to a "My account" page and **WorkOS Team**
  to a page reserved for organization admins.
- Backend users pick **WorkOS** on the login screen; the classic
  username/password form stays available.
- Point an MCP client at `https://example.com/workos-auth/mcp`
  ([Documentation/Mcp.rst](Documentation/Mcp.rst)).

## Develop

```bash
composer install
composer ci          # validate, lint, cgl, phpstan, unit, functional, architecture
Build/Scripts/runTests.sh -s unit|functional|phpstan|architecture|mutation
typo3DatabaseDriver=pdo_sqlite Build/Scripts/runTests.sh -s functional   # local sqlite run
```

- PHPStan policy: green at level 8 or higher (`phpstan.neon` runs `max`);
  phpat rules keep the Controllers → Services → Security layering intact.
- The backend widget bundle is built in `Build/user-management-widget`
  (`npm install && npm run build`); the output under
  `Resources/Public/JavaScript/` is committed.
- Playwright end-to-end specs live in `Tests/E2E/`.

## Docs

- [Configuration](Documentation/Configuration.rst) — Setup Assistant, all keys, validation, workspaces
- [Features](Documentation/Features.rst) — login flows, auth-service bridge, plugins, backend modules, dynamic AuthKit parameters, security guarantees, data model
- [TYPO3 MCP server](Documentation/Mcp.rst) — endpoint, WorkOS protection, application discovery
- [WorkOS Dashboard](Documentation/WorkosDashboard.rst) — redirect URIs and authentication methods
- [Troubleshooting](Documentation/Troubleshooting.rst) — common errors and fixes
- [Changelog](Documentation/Changelog.rst) · [CHANGELOG.md](CHANGELOG.md) · security audit snapshots in [docs/audits/](docs/audits/)

## License

GPL-2.0-or-later — © [webconsulting](https://webconsulting.at).
Source: [github.com/dirnbauer/workos](https://github.com/dirnbauer/workos).
