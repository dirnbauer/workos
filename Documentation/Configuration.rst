..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

Configure the extension in :guilabel:`WorkOS` -> :guilabel:`Setup Assistant`
and, for the MCP server, :guilabel:`WorkOS` -> :guilabel:`MCP Server` (admin
only, LIVE workspace). Settings live in the extension configuration
``EXTENSIONS.workos_auth`` in :file:`config/system/settings.php`; saving in
either module flushes the ``system`` cache group, and each module only
changes the settings it shows.

..  _configuration-keys:

Keys and defaults
=================

..  code-block:: php
    :caption: config/system/settings.php

    'EXTENSIONS' => [
        'workos_auth' => [
            'apiKey' => '',                       // sk_test_... / sk_live_...
            'clientId' => '',                     // client_...

            'frontendEnabled' => '1',
            'frontendAutoCreateUsers' => '1',     // create fe_users on first sign-in
            'frontendLinkByEmail' => '1',         // link an existing fe_users row by email
            'frontendStoragePid' => '0',          // required when auto-create is on
            'frontendDefaultGroupUids' => '',     // comma separated fe_groups uids
            'frontendLoginPath' => '/workos-auth/frontend/login',
            'frontendCallbackPath' => '/workos-auth/frontend/callback',
            'frontendLogoutPath' => '/workos-auth/frontend/logout',
            'frontendSuccessRedirect' => '/',     // when no returnTo is given

            'backendEnabled' => '1',
            'backendRequireMfa' => '1',           // additionally require TYPO3 MFA
            'backendAutoCreateUsers' => '0',      // off: backend access stays explicit
            'backendLinkByEmail' => '1',
            'backendDefaultGroupUids' => '',      // required when auto-create is on
            'backendAllowedDomains' => '',        // e.g. example.com,partner.com; empty = any
            'backendLoginPath' => '/workos-auth/backend/login',
            'backendCallbackPath' => '/workos-auth/backend/callback',
            'backendSuccessPath' => '/main',
            'widgetCorsAutoRegister' => '1',      // register the backend origin as WorkOS CORS origin
            'widgetCorsOrigins' => '',            // additional origins, comma separated

            'authkitOrganizationId' => '',        // pins the hosted AuthKit login to one organization
            'authkitConnectionId' => '',          // sends the hosted login straight to one SSO connection
            'authkitDomainHint' => '',            // suggests the SSO connection of an email domain

            'mcpEnabled' => '1',
            'mcpServerPath' => '/workos-auth/mcp',
            'mcpAuthenticationMode' => 'auto',    // auto | workos | anonymous
            'mcpAuthkitDomain' => '',             // https://your-project.authkit.app
            'mcpWorkosDiscovery' => '1',          // list WorkOS-authorized Connect applications
            'mcpServerLimit' => '10',             // 1-10
            'mcpVerboseLogging' => '0',
        ],
    ],

Paths are normalized to a leading slash without a trailing slash;
``widgetCorsOrigins`` is reduced to ``scheme://host[:port]`` origins.

WorkOS accepts one connection selector per login: a social provider chosen
on the login screen wins, then ``authkitConnectionId``, then the hosted
AuthKit screen (optionally pinned by ``authkitOrganizationId``).

..  _configuration-backend-policy:

Backend authentication policy
=============================

``backendRequireMfa`` defaults to enabled: WorkOS sign-ins follow TYPO3's
normal MFA requirements. Disable it in the Setup Assistant when WorkOS
should own the authentication policy, for example to allow email-code-only
login. Successful WorkOS logins then skip both TYPO3 authenticator setup
and challenges for existing authenticators, for that session only. This
does not claim that an email code constitutes multiple factors, change
WorkOS policies, remove enrolled authenticators, or change the MFA policy
for classic TYPO3 password logins. Existing sessions must sign in again.

The backend login screen presents a separate email-code form first. Password
login is available in an expandable section with its own email field.

..  _configuration-validation:

Validation
==========

The Setup Assistant saves and then warns about:

-   missing ``apiKey`` / ``clientId`` while frontend or backend login is
    enabled;
-   frontend auto-create without ``frontendStoragePid``;
-   backend auto-create without ``backendDefaultGroupUids``;
-   ``BE.cookieSameSite`` outside ``strict`` / ``lax`` / ``none`` (keep the
    default ``strict``: the backend callback lands on a same-origin
    continuation page first, so the strict cookie is sent);
-   MCP enabled with an effective WorkOS requirement but no
    ``mcpAuthkitDomain``.

..  _configuration-mcp-mode:

MCP authentication mode
=======================

..  list-table::
    :header-rows: 1

    *   -   Mode
        -   Behaviour
    *   -   ``auto``
        -   Anonymous in TYPO3 ``Development`` / ``Testing``, WorkOS bearer
            token required in ``Production``.
    *   -   ``workos``
        -   Bearer token always required.
    *   -   ``anonymous``
        -   Never requires a token, in every context.

Tokens are verified against ``mcpAuthkitDomain``: JWKS signature, issuer,
exact resource audience (the absolute MCP endpoint URL) and expiration.
