..  include:: /Includes.rst.txt

..  _workos-dashboard:

=====================
WorkOS Dashboard setup
=====================

Every authentication method is enabled in the WorkOS Dashboard and
not in TYPO3. The extension only calls the WorkOS API, so a method
that is disabled in WorkOS cannot be used on your TYPO3 site — even
if the button appears on the login form.

Open the dashboard at https://dashboard.workos.com.

..  contents::
    :local:
    :depth: 2

..  _workos-dashboard-redirects:

1. Add the Redirect URIs
========================

Go to :guilabel:`Redirects` and add every callback URL listed in the
TYPO3 setup assistant. Use the :guilabel:`+ Add` button once per URL
and click :guilabel:`Save changes` at the bottom.

..  figure:: Images/workos-redirect-uris.png
    :alt: WorkOS Dashboard – Redirect URIs configuration
    :class: with-shadow with-border
    :zoom: lightbox

    Redirect URIs pasted into the WorkOS Dashboard.

..  tip::

    The TYPO3 setup assistant has a :guilabel:`Copy all callback URLs`
    button that copies every required URL to your clipboard.

..  _workos-dashboard-methods:

2. Enable authentication methods
================================

Go to :guilabel:`Authentication` -> :guilabel:`Methods`.

..  figure:: Images/workos-auth-methods.png
    :alt: WorkOS Dashboard – Authentication Methods overview
    :class: with-shadow with-border
    :zoom: lightbox

    Each method has an :guilabel:`Enable` or :guilabel:`Manage`
    button.

..  _workos-dashboard-magic-auth:

Magic Auth (email code)
-----------------------

Sends a six-digit code to the user's email address. Codes expire
after 10 minutes.

#.  Go to :guilabel:`Authentication` -> :guilabel:`Methods`.
#.  Find :guilabel:`Magic Auth` and click :guilabel:`Enable` (or
    :guilabel:`Manage` if already configured).
#.  Toggle :guilabel:`Enable` on and click :guilabel:`Save changes`.

..  figure:: Images/workos-magic-auth-enable.png
    :alt: WorkOS Dashboard – Enable Magic Auth
    :class: with-shadow with-border
    :zoom: lightbox

    Once enabled, the "Email code" option on the TYPO3 login form
    works immediately — no code changes needed.

..  _workos-dashboard-email-password:

Email + Password
----------------

#.  Go to :guilabel:`Authentication` -> :guilabel:`Methods`.
#.  Find :guilabel:`Email + Password` and click :guilabel:`Manage`.
#.  Configure the password rules to match your security policy
    (minimum length, complexity, breach detection).

..  note::

    If you change the minimum password length here, also update the
    hint text in the TYPO3 sign-up form template
    (:file:`Resources/Private/Language/locallang.xlf`, key
    ``frontend.signup.passwordHint``).

..  _workos-dashboard-social:

Social providers
----------------

#.  Go to :guilabel:`Authentication` -> :guilabel:`Providers`.
#.  Enable Google, Microsoft, GitHub and/or Apple.
#.  Configure each provider with its OAuth client credentials (the
    WorkOS docs link to provider-specific guides).

The TYPO3 login form shows a button for each provider the extension
supports (``GoogleOAuth``, ``MicrosoftOAuth``, ``GitHubOAuth``,
``AppleOAuth``). Buttons that point to providers you haven't enabled
will return a "method not allowed" error from WorkOS — either
enable them or hide the unused buttons by overriding the
``SocialButton`` partial.

..  _workos-dashboard-troubleshooting:

Troubleshooting
===============

See :ref:`Troubleshooting <troubleshooting>` for the common error
messages you might see after changes in the WorkOS Dashboard.
