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
        -   POST to ``magicAuthSend`` → ``magicAuthCode`` (code-entry
            screen) → POST to ``magicAuthVerify``
    *   -   Email verification step
        -   ``verifyEmail`` (renders the code form), POST to
            ``verifyEmailSubmit``, POST to ``verifyEmailResend`` for
            "didn't get the email?" — used when WorkOS reports
            ``email_verification_required``
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

All state-changing frontend auth forms (sign-up, password sign-in,
magic-auth send/verify, email verification submit/resend) require a
request token and sanitize ``returnTo`` through
``PathUtility::sanitizeReturnTo()`` before a TYPO3 session is created.

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

..  _features-auth-service-bridge:

TYPO3 auth service bridge
-------------------------

After WorkOS completes a login, the extension does not keep session
creation in custom code only. Instead it hands the resolved TYPO3 user
record back into TYPO3's own FE/BE authentication lifecycle through a
registered auth service.

The registration in :file:`ext_localconf.php` looks like this:

..  code-block:: php
    :caption: ext_localconf.php

    ExtensionManagementUtility::addService(
        'workos_auth',
        'auth',
        \WebConsulting\WorkosAuth\Authentication\WorkosTypo3AuthenticationService::class,
        [
            'title' => 'WorkOS TYPO3 Authentication Bridge',
            'description' => 'Authenticates TYPO3 FE and BE users after a successful WorkOS login flow.',
            'subtype' => 'getUserBE,getUserFE,authUserBE,authUserFE,processLoginDataBE,processLoginDataFE',
            'available' => true,
            'priority' => 85,
            'quality' => 80,
            'os' => '',
            'exec' => '',
            'className' => \WebConsulting\WorkosAuth\Authentication\WorkosTypo3AuthenticationService::class,
        ]
    );

Why six hooks? TYPO3 runs its auth services in three phases, and each
phase exists once for frontend users and once for backend users:

-   ``processLoginDataFE`` / ``processLoginDataBE``
-   ``getUserFE`` / ``getUserBE``
-   ``authUserFE`` / ``authUserBE``

They have distinct jobs in this extension:

-   ``processLoginData*`` injects placeholder login data so TYPO3
    treats the hand-off request as an active login.
-   ``getUser*`` returns the already-resolved local ``fe_users`` or
    ``be_users`` row that was linked or provisioned from the WorkOS
    identity.
-   ``authUser*`` confirms that this row is the user TYPO3 should
    authenticate.

Registering all six lets TYPO3 create the real FE/BE session itself
instead of bypassing its normal login flow. That keeps TYPO3 core
behaviour intact, including session fixation protection, login
logging/events, and backend MFA evaluation when TYPO3 MFA providers are
enabled.

..  _features-backend-endpoints:

Endpoints (HTTP POST)
---------------------

All POST endpoints require a valid TYPO3 backend request token
(``core/user-auth/be``) to defeat CSRF.

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
    *   -   ``/workos-auth/backend/email-verify``
        -   Submit the email-verification code that WorkOS demands
            after ``email_verification_required``
    *   -   ``/workos-auth/backend/email-verify-resend``
        -   Re-issue a fresh email-verification code

..  _features-backend-modules:

Backend modules
===============

The extension installs a top-level :guilabel:`WorkOS` menu in the
TYPO3 backend (registered next to :guilabel:`System`) with two
admin-only entries. Both modules are limited to the LIVE workspace
via ``workspaces => 'live'``.

Setup Assistant
---------------

-   Module identifier: ``workos_setup``
-   Path: ``/module/workos/setup``
-   Alias for upgrades from earlier versions: ``system_workosauth``
-   Controller: ``SetupAssistantController`` (``indexAction``,
    ``saveAction``)

See :ref:`Configuration <configuration>` for the walk-through.

Key features:

-   Shows every callback URL that must be registered in WorkOS.
-   One-click :guilabel:`Copy all callback URLs` (via an ES module,
    CSP-safe).
-   Inline screenshot explaining where to paste them in the WorkOS
    Dashboard.
-   Automatic cookie password generation on save.
-   Flash messages indicate whether the setup is ready or still
    incomplete.

User Management
---------------

-   Module identifier: ``workos_users``
-   Path: ``/module/workos/users``
-   Controller: ``UserManagementController`` (``indexAction``,
    ``tokenAction``, ``joinAction``, ``createOrganizationAction``)

Embeds the official **WorkOS User Management widget** so admins can
invite teammates, change roles, and remove users without leaving
TYPO3. The module is bound to the signed-in WorkOS session of the
current backend user; if the user is not yet a member of any
organization, the module offers a "join existing" or "create new"
flow before mounting the widget.

All POST routes (``token``, ``join``, ``createOrganization``) are
protected by an explicit ``isCurrentBackendUserAdmin()`` check (in
addition to the module's ``access => 'admin'`` gate) and validate a
``FormProtectionFactory`` token before talking to WorkOS.

..  _features-account-center:

Account Center plugin
=====================

Add the :guilabel:`WorkOS Account Center` content element to a page
that signed-in frontend users can reach (typically ``/my-account``).
The plugin is implemented by ``AccountController`` and renders four
cards backed by the WorkOS API:

..  list-table::
    :header-rows: 1

    *   -   Card
        -   Action(s)
    *   -   Profile
        -   ``updateProfile`` — first/last name, mirrored to WorkOS via
            ``UserManagement::updateUser``
    *   -   Password
        -   ``changePassword`` — translates WorkOS errors for
            too-short, too-weak, breached, or already-existing passwords
    *   -   Two-factor authentication
        -   ``startMfaEnrollment`` → ``verifyMfaEnrollment`` (TOTP),
            ``cancelMfaEnrollment``, ``deleteFactor``
    *   -   Active sessions
        -   ``revokeSession`` per WorkOS session id

A "directory sync" badge appears on organization memberships that
are managed by an external IdP. Each card degrades gracefully: a
single failed WorkOS call disables only that card and shows a
translated message.

All state-changing actions require a CSRF token issued per
frontend session and scoped per plugin (mismatch shows
``account.flash.csrfInvalid``).
``deleteFactor`` and ``revokeSession`` additionally verify that the
posted WorkOS factor/session id belongs to the currently linked
WorkOS user before calling the API.

..  _features-team-plugin:

Team plugin
===========

Add the :guilabel:`WorkOS Team` content element to a page reserved
for organization admins. The plugin is implemented by
``TeamController`` and exposes the WorkOS B2B feature set:

..  list-table::
    :header-rows: 1

    *   -   Section
        -   Action(s)
    *   -   Organization switcher
        -   ``dashboard`` — sticky session selection when the user
            belongs to more than one active WorkOS organization
    *   -   Send invitation
        -   ``invite`` — email and optional role slug, dispatched via
            WorkOS
    *   -   Pending invitations
        -   ``resendInvitation``, ``revokeInvitation``
    *   -   Admin Portal launcher
        -   ``launchPortal`` for one of the supported intents:
            ``sso``, ``dsync``, ``audit_logs``, ``log_streams``,
            ``domain_verification``, ``certificate_renewal``

Every state-changing action verifies — via
``WorkosTeamService::assertMemberOfOrganization()`` — that the
signed-in WorkOS user is an active organization admin/owner of the
target organization before calling the SDK. Cross-tenant invite,
revoke, or Admin-Portal-link mints via crafted POST bodies are
rejected with ``team.flash.forbidden``; regular members cannot use
the admin workflow either. Each form additionally carries a CSRF
token tied to the frontend session.

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
        -   Relative path or same-host absolute URL
        -   Where to land after login. Sanitised by
            ``PathUtility::sanitizeReturnTo()``: protocol-relative
            (``//evil.example``) and cross-host candidates fall back
            to the configured default redirect

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
:file:`Resources/Private/Language/`. All files use XLIFF 1.2 and
ICU MessageFormat for dynamic placeholders. For example:

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

Bundled XLIFF files:

..  code-block:: text
    :caption: Translation file inventory

    locallang.xlf            # English source (frontend + backend)
    de.locallang.xlf         # German translations
    locallang_db.xlf         # Database labels (TCA)
    locallang_mod.xlf        # WorkOS top-level module
    de.locallang_mod.xlf     # German module label
    locallang_mod_setup.xlf  # Setup Assistant module
    de.locallang_mod_setup.xlf
    locallang_mod_users.xlf  # User Management module
    de.locallang_mod_users.xlf

To add another language, create :file:`xx.locallang.xlf` alongside
the English source — TYPO3 picks it up automatically.

A unit test (``Tests/Unit/Configuration/XliffParityTest``) fails the
build when an English translation key is missing its German
counterpart (or vice versa).
