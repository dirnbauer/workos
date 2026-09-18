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
        -   Relative path or same-origin URL; anything else falls back to
            ``frontendSuccessRedirect``

``/workos-auth/frontend/logout?returnTo=...`` ends the TYPO3 frontend
session.

..  _usage-backend:

Backend login
=============

Backend users pick :guilabel:`WorkOS` on the login screen: email + password,
:guilabel:`Email a login code`, social buttons or
:guilabel:`More sign-in options` for the hosted AuthKit screen (SSO,
passkeys). The classic username/password form stays available through the
provider switcher.

If WorkOS authenticates someone without a matching ``be_users`` row and
``backendAutoCreateUsers`` is off, the login screen shows an error card with
the email and WorkOS user id. Either set that email on the intended backend
user (``backendLinkByEmail``), or enable auto-create together with
``backendDefaultGroupUids`` and - strongly recommended - a
``backendAllowedDomains`` allow-list.

..  _usage-modules:

Backend modules
===============

..  list-table::
    :header-rows: 1

    *   -   Module
        -   Purpose
    *   -   :guilabel:`Setup Assistant` (``/module/workos/setup``)
        -   All settings, validation warnings and the redirect URIs to
            register in WorkOS.
    *   -   :guilabel:`User Management` (``/module/workos/users``)
        -   Embeds the WorkOS User Management widget scoped to the first
            active organization of the signed-in backend user; users without
            an organization can join an existing one or create one.
    *   -   :guilabel:`MCP Server` (``/module/workos/mcp``)
        -   MCP settings, endpoint URLs per site, database schema status.

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
