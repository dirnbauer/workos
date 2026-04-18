..  include:: /Includes.rst.txt

..  _start:

==========
WorkOS Auth
==========

:Extension key:
    workos_auth

:Package name:
    webconsulting/workos-auth

:Version:
    |release|

:Language:
    en

:Author:
    webconsulting <office@webconsulting.at>

:License:
    GPL-2.0-or-later

:Rendered:
    |today|

----

`WorkOS Auth <https://workos.com>`_ for TYPO3 adds
WorkOS-powered authentication to both the TYPO3 frontend and the
backend. It supports the full AuthKit feature set — email +
password, passwordless magic auth, and social sign-in with Google,
Microsoft, GitHub and Apple — plus self-service Account Center and
Team management plugins for signed-in users.

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: :ref:`Configuration <configuration>`

        Every configuration key, the setup assistant walk-through,
        and how the extension behaves under TYPO3 Workspaces.

    ..  card:: :ref:`Features <features>`

        Frontend and backend login flows, the Account Center and
        Team plugins, profile display, and dynamic AuthKit query
        parameters.

    ..  card:: :ref:`WorkOS Dashboard <workos-dashboard>`

        Adding redirect URIs and enabling the authentication methods
        your TYPO3 site needs.

    ..  card:: :ref:`Troubleshooting <troubleshooting>`

        Common error messages and how to fix them — including the
        backend "account not linked" screen and the new CSRF flash.

..  _toc:

Table of contents
=================

..  toctree::
    :maxdepth: 2
    :titlesonly:

    Configuration
    Features
    WorkosDashboard
    Troubleshooting
    Changelog
