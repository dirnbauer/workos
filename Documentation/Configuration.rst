..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

All settings are configured in :guilabel:`System` -> :guilabel:`WorkOS Auth`
in the TYPO3 backend. You never need to edit PHP arrays by hand.

Settings are stored in TYPO3 extension configuration under
``EXTENSIONS.workos_auth`` and end up in :file:`config/system/settings.php`
in Composer-based projects.

..  contents::
    :local:
    :depth: 2

..  _configuration-setup-assistant:

The setup assistant
===================

The assistant has five sections. You can save at any time — the page
tells you which required values are still missing.

..  _configuration-redirect-uris:

1. Redirect URIs
----------------

A read-only list of every callback URL TYPO3 will redirect to after a
WorkOS sign-in. One entry for the backend, plus one per site for the
frontend. Use the :guilabel:`Copy all callback URLs` button and paste
them into the WorkOS Dashboard.

..  figure:: Images/workos-redirect-uris.png
    :alt: WorkOS Dashboard – Redirect URIs configuration
    :class: with-shadow with-border
    :zoom: lightbox

    Redirect URIs are pasted into the WorkOS Dashboard.

..  _configuration-credentials:

2. Credentials
--------------

..  list-table::
    :header-rows: 1

    *   -   Field
        -   Notes
    *   -   :guilabel:`API key`
        -   ``sk_test_...`` or ``sk_live_...`` from the WorkOS Dashboard
    *   -   :guilabel:`Client ID`
        -   ``client_...`` from the WorkOS Dashboard
    *   -   :guilabel:`Cookie password`
        -   Minimum 32 characters. Tick :guilabel:`Generate a fresh cookie
            password on save` to have TYPO3 create one for you.

..  _configuration-frontend:

3. Frontend login
-----------------

..  list-table::
    :header-rows: 1

    *   -   Field
        -   Default
        -   Purpose
    *   -   Enable frontend login
        -   on
        -   Master toggle for the frontend plugin and middleware
    *   -   Auto-create missing frontend users
        -   on
        -   Create an ``fe_users`` record the first time a WorkOS user
            signs in
    *   -   Link existing frontend users by email
        -   on
        -   If an ``fe_users`` record with the same email already
            exists, link it instead of creating a new one
    *   -   Frontend storage PID
        -   0
        -   Where auto-created ``fe_users`` are stored. Required when
            auto-creation is on
    *   -   Default frontend group UIDs
        -   –
        -   Comma-separated list of ``fe_groups`` uids assigned to new
            users
    *   -   Login / callback / logout path
        -   ``/workos-auth/frontend/login`` etc.
        -   Customise if you need different URLs
    *   -   Success redirect
        -   ``/``
        -   Where to land after login when no ``returnTo`` is given

..  _configuration-backend:

4. Backend login
----------------

..  list-table::
    :header-rows: 1

    *   -   Field
        -   Default
        -   Purpose
    *   -   Enable backend login
        -   on
        -   Shows the WorkOS provider on the TYPO3 backend login
    *   -   Auto-create missing backend users
        -   off
        -   Off by default — backend access should be explicit
    *   -   Link existing backend users by email
        -   on
        -   Match by email against existing ``be_users``
    *   -   Default backend group UIDs
        -   –
        -   Required when auto-create is enabled
    *   -   Allowed backend email domains
        -   –
        -   Optional allow-list (e.g. ``example.com,partner.com``).
            Empty = any domain
    *   -   Login / callback path
        -   ``/workos-auth/backend/login`` etc.
        -   Customise if needed
    *   -   Success path
        -   ``/main``
        -   Backend route after successful login

..  _configuration-authkit:

5. Optional AuthKit hints
-------------------------

Extension-level defaults applied to every authorization URL:

..  list-table::
    :header-rows: 1

    *   -   Field
        -   Purpose
    *   -   Organization ID
        -   Scope AuthKit to a specific WorkOS organization
    *   -   Connection ID
        -   Force a specific SSO connection
    *   -   Domain hint
        -   Pre-select a connection based on email domain

These are optional. Per-request overrides are also possible via
:ref:`query parameters <features-dynamic-parameters>`.

..  _configuration-all-keys:

All configuration keys
======================

If you prefer editing :file:`config/system/settings.php` directly, here
are every key with its default:

..  code-block:: php
    :caption: config/system/settings.php

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

..  _configuration-validation:

Validation
==========

The setup assistant shows warnings but still saves. Validation rules:

-   ``apiKey`` and ``clientId`` are required when either frontend or
    backend login is enabled.
-   ``cookiePassword`` must be at least 32 characters.
-   Frontend auto-create requires ``frontendStoragePid > 0``.
-   Backend auto-create requires at least one entry in
    ``backendDefaultGroupUids``.

..  _configuration-workspaces:

Workspaces
==========

The extension is designed to be workspace-neutral.

-   The identity mapping table ``tx_workosauth_identity`` is an
    authentication cache, not editorial content. Its TCA sets
    ``versioningWS => false``, ``adminOnly => true``, and
    ``hideTable => true``. Records are managed exclusively by
    ``IdentityService``; you never see them in the List module unless
    you explicitly enable the table.
-   Authentication lookups against ``fe_users`` and ``be_users`` run
    with restrictions removed so they always see live records.
    Workspace-versioned user records are never used for login — this
    is intentional; a staging copy of a user account would be unsafe
    for authentication.
-   The frontend middleware only reacts to the three configured paths
    (``login``, ``callback``, ``logout``). Workspace preview of a
    content page never enters the WorkOS flow.
-   Publishing a workspace does not change identity records because
    the identity table is excluded from workspace versioning.
