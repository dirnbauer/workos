# Configuration

All settings are configured in **System > WorkOS Auth** in the TYPO3
backend. You never need to edit PHP arrays by hand.

Settings are stored in TYPO3 extension configuration under
`EXTENSIONS.workos_auth` and end up in `config/system/settings.php` in
Composer-based projects.

---

## The setup assistant

The assistant has five sections. You can save at any time — the page
tells you which required values are still missing.

### 1. Redirect URIs

A read-only list of every callback URL TYPO3 will redirect to after a
WorkOS sign-in. One entry for the backend, plus one per site for the
frontend. Use the **Copy all callback URLs** button and paste them into
the WorkOS Dashboard.

![WorkOS Dashboard – Redirect URIs configuration](Images/workos-redirect-uris.png)

### 2. Credentials

| Field | Notes |
|---|---|
| **API key** | `sk_test_...` or `sk_live_...` from the WorkOS Dashboard |
| **Client ID** | `client_...` from the WorkOS Dashboard |
| **Cookie password** | Minimum 32 characters. Tick **Generate a fresh cookie password on save** to have TYPO3 create one for you |

### 3. Frontend login

| Field | Default | Purpose |
|---|---|---|
| **Enable frontend login** | on | Master toggle for the frontend plugin and middleware |
| **Auto-create missing frontend users** | on | Create an `fe_users` record the first time a WorkOS user signs in |
| **Link existing frontend users by email** | on | If an `fe_users` record with the same email already exists, link it instead of creating a new one |
| **Frontend storage PID** | 0 | Where auto-created `fe_users` are stored. Required when auto-creation is on |
| **Default frontend group UIDs** | – | Comma-separated list of `fe_groups` uids assigned to new users |
| **Login / callback / logout path** | `/workos-auth/frontend/login` etc. | Customise if you need different URLs |
| **Success redirect** | `/` | Where to land after login when no `returnTo` is given |

### 4. Backend login

| Field | Default | Purpose |
|---|---|---|
| **Enable backend login** | on | Shows the WorkOS provider on the TYPO3 backend login |
| **Auto-create missing backend users** | off | Off by default – backend access should be explicit |
| **Link existing backend users by email** | on | Match by email against existing `be_users` |
| **Default backend group UIDs** | – | Required when auto-create is enabled |
| **Allowed backend email domains** | – | Optional allow-list (e.g. `example.com,partner.com`). Empty = any domain |
| **Login / callback path** | `/workos-auth/backend/login` etc. | Customise if needed |
| **Success path** | `/main` | Backend route after successful login |

### 5. Optional AuthKit hints

Extension-level defaults applied to every authorization URL:

| Field | Purpose |
|---|---|
| **Organization ID** | Scope AuthKit to a specific WorkOS organization |
| **Connection ID** | Force a specific SSO connection |
| **Domain hint** | Pre-select a connection based on email domain |

These are optional. Per-request overrides are also possible via
[query parameters](Features.md#dynamic-authkit-parameters).

---

## All configuration keys

If you prefer editing `config/system/settings.php` directly, here are
every key with its default:

```php
'EXTENSIONS' => [
    'workos_auth' => [
        'apiKey' => '',
        'clientId' => '',
        'cookiePassword' => '',

        'frontendEnabled' => '1',
        'frontendAutoCreateUsers' => '1',
        'frontendLinkByEmail' => '1',
        'frontendStoragePid' => '0',
        'frontendDefaultGroupUids' => '',
        'frontendLoginPath' => '/workos-auth/frontend/login',
        'frontendCallbackPath' => '/workos-auth/frontend/callback',
        'frontendLogoutPath' => '/workos-auth/frontend/logout',
        'frontendSuccessRedirect' => '/',

        'backendEnabled' => '1',
        'backendAutoCreateUsers' => '0',
        'backendLinkByEmail' => '1',
        'backendDefaultGroupUids' => '',
        'backendAllowedDomains' => '',
        'backendLoginPath' => '/workos-auth/backend/login',
        'backendCallbackPath' => '/workos-auth/backend/callback',
        'backendSuccessPath' => '/main',

        'authkitOrganizationId' => '',
        'authkitConnectionId' => '',
        'authkitDomainHint' => '',
    ],
],
```

## Validation

The setup assistant shows warnings but still saves. Validation rules:

- `apiKey` and `clientId` are required when either frontend or backend login is enabled.
- `cookiePassword` must be at least 32 characters.
- Frontend auto-create requires `frontendStoragePid > 0`.
- Backend auto-create requires at least one entry in `backendDefaultGroupUids`.

## Workspaces

The extension is designed to be workspace-neutral.

- The identity mapping table `tx_workosauth_identity` is an
  authentication cache, not editorial content. Its TCA sets
  `versioningWS => false`, `adminOnly => true`, and `hideTable => true`.
  Records are managed exclusively by `IdentityService`; you never see
  them in the List module unless you explicitly enable the table.
- Authentication lookups against `fe_users` and `be_users` run with
  restrictions removed so they always see live records. Workspace-
  versioned user records are never used for login — this is
  intentional; a staging copy of a user account would be unsafe for
  authentication.
- The frontend middleware only reacts to the three configured paths
  (`login`, `callback`, `logout`). Workspace preview of a content page
  never enters the WorkOS flow.
- Publishing a workspace does not change identity records because the
  identity table is excluded from workspace versioning.
