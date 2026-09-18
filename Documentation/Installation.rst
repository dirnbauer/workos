..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

Requirements
============

..  list-table::
    :header-rows: 1

    *   -   Component
        -   Version
    *   -   TYPO3
        -   ``^14.3.7``
    *   -   PHP
        -   ``^8.4``
    *   -   ``workos/workos-php``
        -   ``^9.4`` (installed by Composer)
    *   -   WorkOS
        -   An account with AuthKit enabled

Install
=======

..  code-block:: bash

    composer require webconsulting/workos-auth
    vendor/bin/typo3 extension:setup --extension=workos_auth

``extension:setup`` creates ``tx_workosauth_identity``. The
:guilabel:`WorkOS` -> :guilabel:`MCP Server` module also offers a
:guilabel:`Database schema` card that applies pending changes of this table
through TYPO3's schema migrator.

..  _installation-dashboard:

WorkOS Dashboard
================

Authentication methods are enabled in WorkOS, not in TYPO3. Open
https://dashboard.workos.com and:

#.  :guilabel:`Redirects`: add every callback URL listed in
    :guilabel:`WorkOS` -> :guilabel:`Setup Assistant` (one for the backend,
    one per site for the frontend; :guilabel:`Copy all callback URLs`
    copies them). URLs must match exactly, including trailing slashes.

    ..  figure:: Images/workos-redirect-uris.png
        :alt: WorkOS Dashboard - Redirect URIs
        :class: with-shadow with-border
        :zoom: lightbox

#.  :guilabel:`Authentication` -> :guilabel:`Methods`: enable
    :guilabel:`Email + Password` and :guilabel:`Magic Auth` (six-digit code,
    10 minutes valid) as needed.

    ..  figure:: Images/workos-auth-methods.png
        :alt: WorkOS Dashboard - Authentication methods
        :class: with-shadow with-border
        :zoom: lightbox

    ..  figure:: Images/workos-magic-auth-enable.png
        :alt: WorkOS Dashboard - Enable Magic Auth
        :class: with-shadow with-border
        :zoom: lightbox

#.  :guilabel:`Authentication` -> :guilabel:`Providers`: enable Google,
    Microsoft, GitHub and/or Apple. Buttons for disabled providers return
    "method not allowed"; hide them by overriding the ``SocialButton``
    partial.

..  note::

    WorkOS decides the password policy. The extension only pre-checks a
    minimum of 10 characters in the sign-up and password-change forms.
