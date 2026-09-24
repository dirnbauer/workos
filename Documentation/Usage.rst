..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

..  _usage-frontend:

Frontend plugins
================

Add the content elements from the **WorkOS** group to pages:

-   :guilabel:`WorkOS Login` on a public page. Signed-out visitors get
    password sign-in, an email-code row, social buttons and a sign-up link;
    signed-in users see their profile and a sign-out button. WorkOS may
    demand email verification; the plugin then shows a code form.
-   :guilabel:`WorkOS Account Center` on a page for signed-in users:
    profile, password, TOTP two-factor enrollment, active sessions and
    organization memberships. A single failed WorkOS call only disables that
    card.
-   :guilabel:`WorkOS Team` on a page for organization admins: invitations
    (send, resend, revoke) and one-time Admin Portal links for SSO,
    Directory Sync, Audit Logs, Log Streams, Domain Verification and
    Certificate Renewal.

The Login plugin sends visitors back to the ``returnTo`` its page was
opened with, else to the page itself; its :guilabel:`Sign up` /
:guilabel:`Sign in` links keep that target without growing.

The plugins render a generic markup; project styling belongs in the
sitepackage, which can override the templates in
:file:`Resources/Private/Templates/Frontend/`.

Login URL parameters
--------------------

``/workos-auth/frontend/login`` redirects to hosted AuthKit and accepts:

..  list-table::
    :header-rows: 1

    *   -   Parameter
        -   Values
    *   -   ``screen``
        -   ``sign-in`` (default) or ``sign-up``
    *   -   ``provider``
        -   ``GoogleOAuth``, ``MicrosoftOAuth``, ``GitHubOAuth``,
            ``AppleOAuth`` (skips the hosted screen)
    *   -   ``login_hint``
        -   Email to pre-fill
    *   -   ``organization``
        -   WorkOS organization id (``org_...``)
    *   -   ``returnTo``
        -   Relative path or same-origin URL (kept as its path, at most
            2,048 characters); anything else falls back to
            ``frontendSuccessRedirect``

``/workos-auth/frontend/logout?returnTo=...`` ends the TYPO3 frontend
session.

Signing out of TYPO3 - frontend or backend - also ends the WorkOS session
the sign-in opened, so the next person at a shared computer is asked for
their credentials again instead of being signed in silently.

..  _usage-backend:

Backend login
=============

Backend users pick :guilabel:`Login with WorkOS` on the login screen. The
form uses the Core login markup: email and password with the orange
:guilabel:`Sign in` button, :guilabel:`Email me a sign-in code`,
:guilabel:`Continue with Google / Microsoft / GitHub / Apple`, and
:guilabel:`More sign-in options (SSO, passkey)` for the hosted AuthKit
screen. The classic username/password form stays available through the
Core provider switch.

Who may sign in:

-   An account that is already linked signs in, as long as the TYPO3
    account is active (not disabled, inside its start and end time).
-   ``backendLinkByEmail`` links an existing ``be_users`` account with the
    same email - only when WorkOS has verified that address, and only when
    exactly one active account uses it.
-   ``backendAutoCreateUsers`` creates a new, non-admin account - again
    only for a verified email, within ``backendAllowedDomains`` when that
    list is set.
-   A session a WorkOS administrator started through impersonation is
    refused for the backend (and logged); frontend impersonation is allowed
    and logged.

When WorkOS authenticates someone TYPO3 cannot place, the login screen says
why: not linked (with the email and WorkOS user id to link), email not
verified, account disabled or expired, several accounts with that email, or
an impersonated session. The message is stored on the server and shown
once; the login URL only carries a token for it.

..  _usage-modules:

Backend modules
===============

..  list-table::
    :header-rows: 1

    *   -   Module
        -   Purpose
    *   -   :guilabel:`Setup Assistant` (``/module/workos/setup``)
        -   Credentials, frontend and backend sign-in, hosted-login options,
            readiness warnings and the redirect URIs to register in WorkOS,
            each with a copy button. The API key field is write-only: the
            page shows a masked hint, and an empty field keeps the stored key.
    *   -   :guilabel:`User Management` (``/module/workos/users``)
        -   Embeds the WorkOS User Management widget scoped to the first
            active organization of the signed-in backend user; users without
            an organization can join an existing one or create one. The
            widget acts on behalf of a WorkOS user: an administrator who
            signed in with the TYPO3 password and is not linked yet gets a
            :guilabel:`Sign in with WorkOS` action that links the account
            and returns to the module.
    *   -   :guilabel:`MCP Server` (``/module/workos/mcp``)
        -   The MCP settings (edited only here), endpoint URLs per site with
            copy buttons, and the database schema status.

..  _usage-mcp:

TYPO3 MCP server
================

The endpoint accepts JSON-RPC 2.0 via ``POST`` (single messages and
batches) and supports ``initialize``, ``ping``, ``tools/list`` and
``tools/call``:

``workos.mcp_context``
    Authentication mode, WorkOS user id and email, linked ``fe_users`` /
    ``be_users`` uids with their group uids.

``workos.authorized_mcp_servers``
    WorkOS Connect applications the user has authorized, capped by
    ``mcpServerLimit``. WorkOS stays the source of truth; TYPO3 keeps no
    second list.

Discovery documents for MCP clients:
``/.well-known/oauth-protected-resource`` (points to the AuthKit domain) and
``/.well-known/oauth-authorization-server`` (proxies AuthKit's metadata).
Requests without a token in a WorkOS-protected mode get ``401`` with a
``WWW-Authenticate`` header that names the protected-resource document.

..  code-block:: bash
    :caption: Development context, no token needed

    curl -s https://example.ddev.site/workos-auth/mcp \
      -H 'Content-Type: application/json' \
      -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'

For production configure ``mcpAuthkitDomain``, enable AuthKit / Connect with
Client ID Metadata Document in the WorkOS Dashboard and register
``https://example.com/workos-auth/mcp`` as the protected resource. A user
must have signed in to TYPO3 through WorkOS once for the TYPO3 user and
group mapping to appear in ``workos.mcp_context``.

..  _usage-troubleshooting:

Troubleshooting
===============

"This is not a valid redirect URI"
    The callback URL is not registered in WorkOS. Copy it from the Setup
    Assistant; it must match exactly.

"Magic Auth is disabled." / "method not allowed"
    Enable the method or provider in the WorkOS Dashboard.

"Invalid or expired code."
    Codes expire after 10 minutes; request a new one.

"Security check failed" flash message
    The request token did not match the frontend session (for example after
    signing out and in again). Reload the page; never cache the Account
    Center or Team plugin output.

Backend login still shows WorkOS after disabling it
    The extension flushes the ``system`` cache group when saving in the
    module; after editing :file:`settings.php` manually run
    ``vendor/bin/typo3 cache:flush``.
