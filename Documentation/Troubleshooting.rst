..  include:: /Includes.rst.txt

..  _troubleshooting:

===============
Troubleshooting
===============

..  contents::
    :local:
    :depth: 2

..  _troubleshooting-frontend-not-configured:

"WorkOS frontend login is not configured yet."
==============================================

Shown by the frontend plugin when the extension is active but not
configured. Open :guilabel:`WorkOS` -> :guilabel:`Setup Assistant`
and fill in API key, Client ID and cookie password. See
:ref:`Configuration <configuration>`.

..  _troubleshooting-backend-not-configured:

"WorkOS backend login is enabled, but not configured."
======================================================

Same cause on the backend login form. Click the :guilabel:`Open the
setup assistant` link next to the message.

..  _troubleshooting-backend-samesite-strict:

"TYPO3 backend cookies currently use SameSite strict"
=====================================================

``BE.cookieSameSite = strict`` is supported and recommended. You do
not need to change it to ``lax`` or ``none`` for WorkOS.

If you still see an older warning that says WorkOS requires
``lax`` or ``none``, clear TYPO3's system cache and make sure the
extension includes the strict-cookie continuation page described in
:ref:`configuration-backend-samesite`.

..  code-block:: bash
    :caption: Flush the system cache

    vendor/bin/typo3 cache:flush

Only change ``BE.cookieSameSite`` when another integration requires a
different TYPO3 backend cookie policy. WorkOS backend login itself
works with ``strict``.

..  _troubleshooting-invalid-redirect-uri:

"This is not a valid redirect URI"
==================================

WorkOS shows this when the callback URL TYPO3 is requesting was not
registered in the Dashboard.

..  tip::

    Copy the exact URL shown in the TYPO3 setup assistant (it knows
    the base path for each site) and paste it into
    :guilabel:`Redirects` in the WorkOS Dashboard. Don't hand-edit
    the URL — trailing slashes and paths must match exactly.

..  _troubleshooting-magic-auth-disabled:

"Magic Auth is disabled."
=========================

The WorkOS response returns ``authentication_method_not_allowed``
with ``error_description: Magic Auth is disabled.``.

Fix: open the WorkOS Dashboard ->
:guilabel:`Authentication` -> :guilabel:`Methods` ->
:guilabel:`Magic Auth` -> :guilabel:`Enable`. See the walk-through
in :ref:`workos-dashboard-magic-auth`.

..  _troubleshooting-invalid-credentials:

"Invalid email or password." on sign-up
=======================================

WorkOS enforces password policies configured in its dashboard. The
most common reasons this appears during sign-up are:

-   Password shorter than the configured minimum (default 10 in
    WorkOS).
-   Password too weak (no mix of letters, numbers, symbols).
-   Password has appeared in a data breach.
-   An account with the same email already exists.

The extension converts each of these into a specific message;
enable debug logging in ``LOG`` to see the original WorkOS error.

..  _troubleshooting-expired-code:

"Invalid or expired code. Please try again."
============================================

Magic-auth codes expire after 10 minutes. If the user entered the
code correctly but still sees this, they probably waited too long
or requested a new code in a separate tab.

..  _troubleshooting-frontend-code-final-step:

Frontend email-code login sends the email but does not finish
=============================================================

If the first step works (the user receives a six-digit email code) but
submitting that code returns to the login form with a generic
"login failed" message, verify that the extension includes the
frontend request-token handoff fix.

The email-code POST validates the plugin token first. After WorkOS
accepts the code, TYPO3 still performs its own active login check and
expects the core ``core/user-auth/fe`` token scope. Current versions
bridge that handoff only for a server-created pending WorkOS login and
never for an invalid token state.

..  code-block:: bash
    :caption: Clear compiled configuration after updating

    vendor/bin/typo3 cache:flush

If WorkOS accepts the magic-auth code but then requires email
verification, the flow should continue to the dedicated verification
screen. The ``pending_authentication_token`` stays in the TYPO3
frontend session and is not sent through a query parameter.

..  _troubleshooting-stuck-backend-workos:

Backend login keeps showing WorkOS even after I disable it
==========================================================

TYPO3 caches compiled configuration. After changing any value in
the setup assistant, the extension flushes the ``system`` cache
group for you. If you edit :file:`config/system/settings.php`
directly, run:

..  code-block:: bash
    :caption: Flush the system cache

    vendor/bin/typo3 cache:flush

..  _troubleshooting-users-not-created:

Users are not created automatically
===================================

Check:

-   **Frontend**: ``frontendAutoCreateUsers`` must be on AND
    ``frontendStoragePid`` must point to a real ``sys_folder``.
-   **Backend**: ``backendAutoCreateUsers`` must be on AND
    ``backendDefaultGroupUids`` must list at least one
    ``be_groups`` uid. If ``backendAllowedDomains`` is set, the
    user's WorkOS email domain must be in the list.

..  _troubleshooting-not-linked:

"This WorkOS account is not linked to a TYPO3 user" (backend login)
===================================================================

    **EN:** *This WorkOS account is not linked to a TYPO3 user — We
    authenticated* ``<email>`` *with WorkOS, but no matching backend
    user exists in TYPO3 and automatic provisioning is turned off.*

    **DE:** *Dieses WorkOS-Konto ist keinem TYPO3-Benutzer
    zugeordnet —* ``<email>`` *wurde bei WorkOS erfolgreich
    authentifiziert, aber in TYPO3 existiert kein passender
    Backend-Benutzer und das automatische Anlegen ist deaktiviert.*

The error card also shows the e-mail and the WorkOS user id (e.g.
``user_01KPCXE067SSBQV010XGP2A5R4``) that WorkOS returned.

..  _troubleshooting-not-linked-meaning:

What it means
-------------

The OAuth/AuthKit handshake with WorkOS succeeded, so credentials
are fine. The middleware then tried to map the WorkOS user onto a
TYPO3 backend user and failed:

#.  There is no row in ``tx_workosauth_identity`` linking that
    WorkOS user id to a ``be_users`` record, **and**
#.  No existing ``be_users`` row has the same e-mail (or
    ``backendLinkByEmail`` is disabled), **and**
#.  ``backendAutoCreateUsers`` is off, so the extension is not
    allowed to create one on the fly.

..  _troubleshooting-not-linked-fix:

Fix — choose one
----------------

**A. Link the WorkOS account to an existing backend user (recommended)**

#.  Sign in to TYPO3 with the standard username/password login as
    an administrator (use the *"Login with username and
    password"* switcher on the login screen).
#.  Open :guilabel:`System` -> :guilabel:`Backend Users`, find the
    user that should be allowed to sign in via WorkOS, and set its
    :guilabel:`E-Mail` field to exactly the address shown in the
    error card (e.g. ``dirnbauer@me.com``).
#.  Save and try the WorkOS sign-in again. As long as
    ``backendLinkByEmail`` is enabled in :guilabel:`WorkOS` ->
    :guilabel:`Setup Assistant` -> :guilabel:`Backend login`, the
    next successful WorkOS login will write the identity mapping
    into ``tx_workosauth_identity`` automatically.

**B. Enable automatic backend provisioning**

Click :guilabel:`Open WorkOS setup assistant` in the error card,
then in :guilabel:`Backend login`:

#.  Turn on :guilabel:`Auto-create missing backend users`
    (``backendAutoCreateUsers``).
#.  Add at least one UID to :guilabel:`Default backend group UIDs`
    (``backendDefaultGroupUids``). New users will inherit these
    groups.
#.  *(Optional but strongly recommended in production)* Restrict
    who can sign up via :guilabel:`Allowed backend email domains`
    (``backendAllowedDomains``) — for example
    ``webconsulting.at, your-company.com``. Leave empty to allow
    every domain.
#.  Save and retry. The next WorkOS login from a matching domain
    will create the ``be_users`` record and link it.

..  warning::

    With auto-create enabled and no domain allowlist, anyone who can
    log in to your WorkOS tenant will get a TYPO3 backend user.
    Always combine ``backendAutoCreateUsers`` with
    ``backendAllowedDomains`` unless your WorkOS tenant is already
    locked down to your own organization.

**C. Manually insert the identity mapping**

If you want to keep auto-create off and link by id instead of by
e-mail, insert a row directly. The schema matches the TCA in
:file:`Configuration/TCA/tx_workosauth_identity.php`:

..  code-block:: sql
    :caption: Manual backup link

    INSERT INTO tx_workosauth_identity
        (login_context, workos_user_id, email,
         user_table, user_uid,
         workos_profile_json, crdate, tstamp)
    VALUES
        ('backend', 'user_01KPCXE067SSBQV010XGP2A5R4',
         'dirnbauer@me.com',
         'be_users', <be_user_uid>,
         '{}', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

Replace ``<be_user_uid>`` with the ``uid`` of the backend user
that should own that WorkOS account. The next successful WorkOS
sign-in refreshes ``workos_profile_json`` automatically, so the
``'{}'`` placeholder is fine. For frontend identities use
``login_context = 'frontend'`` and ``user_table = 'fe_users'``.

..  _troubleshooting-not-linked-verify:

How to verify the fix worked
----------------------------

After signing in successfully via WorkOS once, the mapping should
be visible:

..  code-block:: sql
    :caption: Verify the identity mapping

    SELECT login_context, user_table, user_uid,
           workos_user_id, email
      FROM tx_workosauth_identity
     WHERE login_context = 'backend'
       AND workos_user_id = 'user_01KPCXE067SSBQV010XGP2A5R4';

If a row exists, the next sign-in goes straight through and the
error stops appearing.

..  _troubleshooting-csrf:

"Security check failed. Please reload the page and try again."
==============================================================

Shown as a red flash message on the Account Center or Team
dashboard.

**Cause.** Each dashboard render stamps a CSRF token into a hidden
form field (``csrfToken``). The corresponding controller action
verifies the token before talking to WorkOS. A mismatch means the
page was loaded with one frontend session and the form was
submitted under a different one — usually because the user signed
out and back in between opening the page and submitting.

**Fix.** Reload the page. The new render ships a fresh token. If
the problem persists in a single session, check that you are not
caching the Account Center / Team plugin output via TYPO3's
content cache: CSRF tokens must be rendered per request.
