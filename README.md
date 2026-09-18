# WorkOS Auth for TYPO3

[![CI](https://github.com/dirnbauer/workos/actions/workflows/ci.yml/badge.svg)](https://github.com/dirnbauer/workos/actions/workflows/ci.yml)
[![TYPO3 14](https://img.shields.io/badge/TYPO3-14.3-orange.svg)](https://get.typo3.org/version/14)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777bb4.svg)](https://www.php.net/)
[![License GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

`workos_auth` adds [WorkOS](https://workos.com) AuthKit sign-in to the TYPO3
frontend and backend, plus self-service Account Center and Team plugins, a
User Management backend module and a WorkOS-protected TYPO3 MCP server.

## What it is

| Area | Feature |
|---|---|
| Frontend plugins | **WorkOS Login** (password, email code, social sign-in, sign-up, email verification), **Account Center** (profile, password, TOTP, sessions, organizations), **Team** (invitations, Admin Portal links) |
| Backend | "Continue with WorkOS" login provider; **WorkOS** menu with *Setup Assistant*, *User Management* widget and *MCP Server* modules |
| Provisioning | Links or creates `fe_users` / `be_users` from WorkOS identities (`tx_workosauth_identity`); TYPO3's own auth service creates the session |
| MCP | Streamable HTTP endpoint `/workos-auth/mcp`: anonymous in `Development`, AuthKit bearer tokens in `Production` |

## Requirements

| Component | Version |
|---|---|
| TYPO3 | `^14.3.7` |
| PHP | `^8.4` |
| `workos/workos-php` | `^9.4` (via Composer) |
| WorkOS | Account with AuthKit enabled |

## Install

```bash
composer require webconsulting/workos-auth
vendor/bin/typo3 extension:setup --extension=workos_auth
```

## Configure

1. **WorkOS → Setup Assistant**: enter the WorkOS **API key** and **Client ID**.
2. Copy the listed **redirect URIs** into the WorkOS Dashboard (*Redirects*).
3. Enable the methods you use in the Dashboard (*Authentication → Methods /
   Providers*). Methods are enabled in WorkOS, not in TYPO3.
4. Optional: frontend storage PID and default groups, backend auto-create
   with a domain allow-list, AuthKit hints, MCP mode and AuthKit domain.

## Use

- Add the **WorkOS Login** content element to a page; the login URL accepts
  `screen`, `provider`, `login_hint`, `organization` and `returnTo`.
- Add **WorkOS Account Center** to a "My account" page and **WorkOS Team**
  to a page for organization admins.
- Backend users pick **WorkOS** on the login screen; the classic form stays
  available.
- Point an MCP client at `https://example.com/workos-auth/mcp`.

## Develop

```bash
composer install
composer ci          # validate, lint, cgl, phpstan (level 8), unit, functional
typo3DatabaseDriver=pdo_sqlite Build/Scripts/runTests.sh -s functional
cd Build/user-management-widget && npm ci && npm run build   # widget bundle
```

## Docs

[Documentation/](Documentation/Index.rst) — Introduction, Installation,
Configuration, Usage, Developer, Changelog. Release notes: [CHANGELOG.md](CHANGELOG.md).

## License

GPL-2.0-or-later — © [webconsulting](https://webconsulting.at).
Source: [github.com/dirnbauer/workos](https://github.com/dirnbauer/workos).
