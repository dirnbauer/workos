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
