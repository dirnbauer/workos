# Troubleshooting

## "WorkOS frontend login is not configured yet."

Shown by the frontend plugin when the extension is active but not
configured. Open **System > WorkOS Auth** and fill in API key, Client
ID and cookie password. See [Configuration](Configuration.md).

## "WorkOS backend login is enabled, but not configured."

Same cause on the backend login form. Click the **Open the setup
assistant** link next to the message.

## "This is not a valid redirect URI"

WorkOS shows this when the callback URL TYPO3 is requesting was not
registered in the Dashboard.

Fix: copy the exact URL shown in the TYPO3 setup assistant (it knows
the base path for each site) and paste it into **Redirects** in the
WorkOS Dashboard. Don't hand-edit the URL — trailing slashes and paths
must match exactly.

## "Magic Auth is disabled."

The WorkOS response returns `authentication_method_not_allowed` with
`error_description: Magic Auth is disabled.`.

Fix: open the WorkOS Dashboard → **Authentication → Methods** →
**Magic Auth** → **Enable**. See the walk-through in
[`WorkosDashboard.md`](WorkosDashboard.md#magic-auth-email-code).

## "Invalid email or password." on sign-up

WorkOS enforces password policies configured in its dashboard. The
most common reasons this appears during sign-up are:

- Password shorter than the configured minimum (default 10 in WorkOS).
- Password too weak (no mix of letters, numbers, symbols).
- Password has appeared in a data breach.
- An account with the same email already exists.

The extension converts each of these into a specific message; enable
debug logging in `LOG` to see the original WorkOS error.

## "Invalid or expired code. Please try again."

Magic-auth codes expire after 10 minutes. If the user entered the code
correctly but still sees this, they probably waited too long or
requested a new code in a separate tab.

## Backend login keeps showing WorkOS even after I disable it

TYPO3 caches compiled configuration. After changing any value in the
setup assistant, the extension flushes the `system` cache group for
you. If you edit `config/system/settings.php` directly, run:

```bash
vendor/bin/typo3 cache:flush
```

## Users are not created automatically

Check:

- **Frontend**: `frontendAutoCreateUsers` must be on AND `frontendStoragePid` must point to a real `sys_folder`.
- **Backend**: `backendAutoCreateUsers` must be on AND `backendDefaultGroupUids` must list at least one `be_groups` uid. If `backendAllowedDomains` is set, the user's WorkOS email domain must be in the list.

## "This WorkOS account is not linked to a TYPO3 user" (backend login)

> **EN:** *This WorkOS account is not linked to a TYPO3 user — We authenticated `<email>` with WorkOS, but no matching backend user exists in TYPO3 and automatic provisioning is turned off.*
>
> **DE:** *Dieses WorkOS-Konto ist keinem TYPO3-Benutzer zugeordnet — `<email>` wurde bei WorkOS erfolgreich authentifiziert, aber in TYPO3 existiert kein passender Backend-Benutzer und das automatische Anlegen ist deaktiviert.*

The error card also shows the **e-mail** and the **WorkOS user id** (e.g. `user_01KPCXE067SSBQV010XGP2A5R4`) that WorkOS returned.

### What it means

The OAuth/AuthKit handshake with WorkOS succeeded, so credentials are fine. The middleware then tried to map the WorkOS user onto a TYPO3 backend user and failed:

1. There is no row in `tx_workosauth_identity` linking that WorkOS user id to a `be_users` record, **and**
2. No existing `be_users` row has the same e-mail (or `backendLinkByEmail` is disabled), **and**
3. `backendAutoCreateUsers` is **off**, so the extension is not allowed to create one on the fly.

### Fix — choose one

**A. Link the WorkOS account to an existing backend user (recommended)**

1. Sign in to TYPO3 with the standard username/password login as an administrator (use the *"Login with username and password"* switcher on the login screen).
2. Open **System → Backend Users**, find the user that should be allowed to sign in via WorkOS, and set its **E-Mail** field to exactly the address shown in the error card (e.g. `dirnbauer@me.com`).
3. Save and try the WorkOS sign-in again. As long as `backendLinkByEmail` is enabled in **System → WorkOS Auth → Backend login**, the next successful WorkOS login will write the identity mapping into `tx_workosauth_identity` automatically.

**B. Enable automatic backend provisioning**

Click **WorkOS-Einrichtungsassistent öffnen / Open WorkOS setup assistant** in the error card, then in **Backend login**:

1. Turn on **Auto-create missing backend users** (`backendAutoCreateUsers`).
2. Add at least one UID to **Default backend group UIDs** (`backendDefaultGroupUids`). New users will inherit these groups.
3. *(Optional but strongly recommended in production)* Restrict who can sign up via **Allowed backend email domains** (`backendAllowedDomains`) — for example `webconsulting.at, your-company.com`. Leave empty to allow every domain.
4. Save and retry. The next WorkOS login from a matching domain will create the `be_users` record and link it.

> **Security note:** With auto-create enabled and no domain allowlist, anyone who can log in to your WorkOS tenant will get a TYPO3 backend user. Always combine `backendAutoCreateUsers` with `backendAllowedDomains` unless your WorkOS tenant is already locked down to your own organization.

**C. Manually insert the identity mapping**

If you want to keep auto-create off and link by id instead of by e-mail, insert a row directly:

```sql
INSERT INTO tx_workosauth_identity
    (be_user, workos_user_id, email, created, updated)
VALUES
    (<be_user_uid>, 'user_01KPCXE067SSBQV010XGP2A5R4', 'dirnbauer@me.com', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
```

Replace `<be_user_uid>` with the `uid` of the backend user that should own that WorkOS account. After this, future WorkOS logins for that id will resolve directly without depending on the e-mail field.

### How to verify the fix worked

After signing in successfully via WorkOS once, the mapping should be visible:

```sql
SELECT be_user, workos_user_id, email FROM tx_workosauth_identity
 WHERE workos_user_id = 'user_01KPCXE067SSBQV010XGP2A5R4';
```

If a row exists, the next sign-in goes straight through and the error stops appearing.
