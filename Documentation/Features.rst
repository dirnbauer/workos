..  include:: /Includes.rst.txt

..  _features:

========
Features
========

A complete reference of what the extension exposes at runtime.

..  contents::
    :local:
    :depth: 2

..  _features-frontend-plugin:

Frontend plugin
===============

Add the :guilabel:`WorkOS Login` content element to any page.

..  _features-signed-out:

Signed-out state
----------------

The plugin renders a single card with:

..  list-table::
    :header-rows: 1

    *   -   Section
        -   Action
    *   -   Email + password
        -   POST to ``passwordAuth`` → calls WorkOS ``authenticateWithPassword``
    *   -   "Email code" row
        -   POST to ``magicAuthSend`` → shows a code-entry screen, POST
            to ``magicAuthVerify``
    *   -   Social buttons
        -   Link to AuthKit with ``?provider=GoogleOAuth`` etc.
    *   -   Sign-up link
        -   Opens the native registration form
    *   -   Continue with WorkOS
        -   Social buttons and the self sign-up link cover the hosted
            flows

Failed login attempts stay on the page and display a human-friendly
error (e.g. "Magic Auth is not enabled" when it is disabled in the
WorkOS Dashboard).

..  _features-sign-up:

Sign-up form
------------

Collects first name, last name, email and password (minimum 10
characters, passed through to WorkOS). Submits to ``signUpSubmit``
which:

#.  Calls WorkOS ``createUser``.
#.  Immediately authenticates the new user.
#.  Creates the TYPO3 ``fe_users`` record and logs them in.

On validation errors the form is re-rendered with the previously
entered values preserved (stored in the FE session under
``workos_signup_form``).

..  _features-signed-in:

Signed-in state
---------------

Shows the display name, avatar (if WorkOS returns
``profilePictureUrl``) and a :guilabel:`Sign out` button.

A collapsible :guilabel:`WorkOS profile data` panel exposes every
field returned by WorkOS for debugging or extension use, including:

-   ``id``, ``email``, ``emailVerified``
-   ``firstName``, ``lastName``
-   ``createdAt``, ``updatedAt``, ``lastSignInAt``
-   ``metadata`` — your own custom key-value pairs from the WorkOS
    dashboard

Custom metadata is fetched with a follow-up ``getUser`` call after
each sign-in, so it is always current.

..  _features-backend-login-provider:

Backend login provider
======================

``WorkosBackendLoginProvider`` adds a second tab to the TYPO3 backend
login form. It shows:

-   :guilabel:`Continue with WorkOS` — full AuthKit experience
    (password, magic, social, SSO)
-   Social buttons (Google, Microsoft, GitHub, Apple) — jump
    straight into a provider
-   :guilabel:`Email a login code` — magic auth, with a dedicated
    code-entry step
-   Error banner if a previous attempt failed

Magic-auth state between the send and verify steps is encoded into
a signed query parameter (``magicAuthState``) so no backend session
is needed before authentication.

Standard TYPO3 username + password login remains available via the
:guilabel:`Login with username and password` link provided by TYPO3
core.

..  _features-backend-endpoints:

Endpoints (HTTP POST)
---------------------

..  list-table::
    :header-rows: 1

    *   -   Path
        -   Purpose
    *   -   ``/workos-auth/backend/password-auth``
        -   Email + password authentication
    *   -   ``/workos-auth/backend/magic-auth-send``
        -   Start magic auth (sends the code)
    *   -   ``/workos-auth/backend/magic-auth-verify``
        -   Submit the six-digit code

..  _features-backend-setup-module:

Backend setup module
====================

Route: ``/module/system/workos-auth`` (admin-only).

See :ref:`Configuration <configuration>` for the walk-through.

Key features of the module:

-   Shows every callback URL that must be registered in WorkOS.
-   One-click :guilabel:`Copy all callback URLs` (via an ES module,
    CSP-safe).
-   Inline screenshot explaining where to paste them in the WorkOS
    Dashboard.
-   Automatic cookie password generation on save.
-   Flash messages indicate whether the setup is ready or still
    incomplete.

..  _features-user-provisioning:

User provisioning
=================

Both frontend and backend flows share the same three-step
resolution:

#.  Identity lookup in ``tx_workosauth_identity`` (by
    ``login_context`` + ``workos_user_id``).
#.  Email link against an existing ``fe_users`` / ``be_users``
    record (if enabled).
#.  Auto-create a new user (if enabled and allowed).

For backend users an optional ``backendAllowedDomains`` list
protects against unexpected admin accounts.

On every sign-in the stored WorkOS profile JSON is refreshed, so
``findProfileByLocalUser()`` always returns the latest metadata.

..  _features-dynamic-parameters:

Dynamic AuthKit parameters
==========================

The frontend login URL (``/workos-auth/frontend/login`` by default)
accepts query parameters that are forwarded to AuthKit:

..  list-table::
    :header-rows: 1

    *   -   Query param
        -   Allowed values
        -   Effect
    *   -   ``screen``
        -   ``sign-in``, ``sign-up``
        -   Which AuthKit screen to open
    *   -   ``provider``
        -   ``GoogleOAuth``, ``MicrosoftOAuth``, ``GitHubOAuth``,
            ``AppleOAuth``
        -   Jump straight to a social provider
    *   -   ``login_hint``
        -   Any email
        -   Pre-fill the email input
    *   -   ``organization``
        -   WorkOS organization id (``org_...``)
        -   Scope the session to an organization
    *   -   ``returnTo``
        -   Relative URL
        -   Where to land after login (sanitised)

Examples:

..  code-block:: text
    :caption: Dynamic AuthKit examples

    # Open the sign-up screen
    /workos-auth/frontend/login?screen=sign-up

    # Jump to Google
    /workos-auth/frontend/login?provider=GoogleOAuth

    # Sign in to a specific organization
    /workos-auth/frontend/login?organization=org_01HXYZ

Extension-wide defaults (``authkitOrganizationId``,
``authkitConnectionId``, ``authkitDomainHint``) are applied
whenever a request does not pass its own override.

..  _features-data-model:

Data model
==========

Identity links are stored in ``tx_workosauth_identity``:

..  list-table::
    :header-rows: 1

    *   -   Column
        -   Purpose
    *   -   ``login_context``
        -   ``frontend`` or ``backend``
    *   -   ``workos_user_id``
        -   WorkOS ``user_...`` id
    *   -   ``email``
        -   Email at the time of last sign-in
    *   -   ``user_table``
        -   ``fe_users`` or ``be_users``
    *   -   ``user_uid``
        -   Local TYPO3 user uid
    *   -   ``workos_profile_json``
        -   Full WorkOS user record as JSON (including metadata)

Unique index: ``(login_context, workos_user_id)``.
Lookup index: ``(login_context, user_table, user_uid)``.

..  _features-localization:

Localization
============

The extension ships with English and German translations in
:file:`Resources/Private/Language/`:

..  code-block:: text
    :caption: Translation file layout

    locallang.xlf        # English source
    de.locallang.xlf     # German translations
    locallang_mod.xlf    # Module tab labels
    de.locallang_mod.xlf # German module labels

All files use XLIFF 1.2 and ICU MessageFormat for dynamic
placeholders. For example:

..  code-block:: xml
    :caption: locallang.xlf

    <trans-unit id="frontend.login.signedInAs">
        <source>Signed in as {name}</source>
    </trans-unit>

Called from Fluid as:

..  code-block:: html
    :caption: Fluid template

    <f:translate key="workos_auth.messages:frontend.login.signedInAs"
                 arguments="{name: displayName}" />

To add another language, create :file:`xx.locallang.xlf` alongside
the English source — TYPO3 picks it up automatically.
