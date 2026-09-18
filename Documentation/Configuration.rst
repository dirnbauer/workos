..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

Configure the extension in :guilabel:`WorkOS` -> :guilabel:`Setup Assistant`
(admin only, LIVE workspace). Settings live in the extension configuration
``EXTENSIONS.workos_auth`` in :file:`config/system/settings.php`; saving in
the module flushes the ``system`` cache group.

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
            'backendAutoCreateUsers' => '0',      // off: backend access stays explicit
            'backendLinkByEmail' => '1',
            'backendDefaultGroupUids' => '',      // required when auto-create is on
            'backendAllowedDomains' => '',        // e.g. example.com,partner.com; empty = any
            'backendLoginPath' => '/workos-auth/backend/login',
            'backendCallbackPath' => '/workos-auth/backend/callback',
            'backendSuccessPath' => '/main',
            'widgetCorsAutoRegister' => '1',      // register the backend origin as WorkOS CORS origin
            'widgetCorsOrigins' => '',            // additional origins, comma separated

            'authkitOrganizationId' => '',        // optional AuthKit hints applied to every login
            'authkitConnectionId' => '',
            'authkitDomainHint' => '',

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
