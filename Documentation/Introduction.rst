..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

WorkOS is the identity provider; TYPO3 keeps its own users and sessions.
After WorkOS authenticates someone, the extension resolves or provisions the
local ``fe_users`` / ``be_users`` record, links it in
``tx_workosauth_identity`` and hands the row to TYPO3's own authentication
service, which creates the session. Session fixation protection, login
logging and backend MFA stay TYPO3 core behaviour.

..  list-table::
    :header-rows: 1

    *   -   Area
        -   What you get
    *   -   Frontend plugins
        -   **WorkOS Login** (password, email code, social buttons, sign-up,
            email verification), **Account Center** (profile, password, TOTP,
            sessions, organizations), **Team** (invitations, one-time Admin
            Portal links)
    *   -   Backend
        -   "Login with WorkOS" provider in Core login markup; **WorkOS** menu with
            *Setup Assistant*, *User Management* widget and *MCP Server*
            modules (admin only, LIVE workspace)
    *   -   Provisioning
        -   Link by identity, then by email, then auto-create (per context
            configurable; backend auto-create supports a domain allow-list)
    *   -   MCP
        -   Streamable HTTP endpoint ``/workos-auth/mcp``: anonymous in
            ``Development``/``Testing``, AuthKit bearer tokens in
            ``Production``
    *   -   Localization
        -   English and German, XLIFF 2.0 with ICU placeholders

Security model
==============

-   Every state-changing action carries a scoped TYPO3 request token; the
    swap to TYPO3's ``core/user-auth/fe|be`` scope happens only for a
    server-created pending login and never for an invalid token state.
-   Multi-step state (OAuth ``state``, backend magic auth, email
    verification) is stored server-side and bound to an HttpOnly cookie;
    WorkOS pending tokens never appear in URLs.
-   ``returnTo`` accepts only relative paths or same-origin URLs;
    protocol-relative and backslash variants fall back to the default.
-   Team actions verify that the user is an active admin/owner of the target
    organization; Account Center checks that factors and sessions belong to
    the linked WorkOS user.
-   Every log line passes through ``SecretRedactor`` (API keys, client ids,
    bearer tokens, JWTs); WorkOS error text is mapped to translated keys.
-   ``tx_workosauth_identity`` is ``adminOnly``, ``hideTable`` and
    ``versioningWS = false``; the backend modules are ``workspaces => 'live'``.
