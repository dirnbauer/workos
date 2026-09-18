..  include:: /Includes.rst.txt

..  _developer:

=========
Developer
=========

Architecture
============

..  list-table::
    :header-rows: 1

    *   -   Namespace
        -   Responsibility
    *   -   ``Domain``
        -   Enums ``LoginContext`` (frontend / backend with user table and
            token scope), ``SocialProvider``, ``McpAuthenticationMode``
    *   -   ``Configuration``
        -   ``WorkosConfiguration``: typed access, normalization, validation
            and persistence of the extension configuration
    *   -   ``Middleware``
        -   ``FrontendWorkosAuthMiddleware`` (login / callback / logout),
            ``BackendWorkosAuthMiddleware`` (login / callback and the five
            POST endpoints of the backend form), ``McpServerMiddleware``
    *   -   ``Service``
        -   ``WorkosClientFactory`` (one WorkOS SDK client per call),
            ``WorkosAuthenticationService``, ``WorkosAccountService``,
            ``WorkosTeamService``, ``UserProvisioningService``,
            ``IdentityService``, ``Typo3SessionService``, ``PathUtility``,
            ``RequestBody``
    *   -   ``Security``
        -   ``StateService`` (single-use, cookie-bound state),
            ``RequestTokenService``, ``SecretRedactor``,
            ``WorkosErrorMessageResolver``, ``MixedCaster``
    *   -   ``Authentication``
        -   ``WorkosTypo3AuthenticationService``: TYPO3 auth service that
            completes the pending login created by ``Typo3SessionService``
    *   -   ``Mcp``
        -   Token verification, JSON-RPC dispatcher, WorkOS Connect discovery

phpat rules in :file:`Tests/Architecture/LayeringTest.php` (evaluated by
PHPStan) keep the layering: nothing depends on controllers, ``Security`` is
self-contained, services never depend on controllers, middlewares, login
providers or event listeners.

Login handoff
=============

#.  A middleware or controller authenticates against WorkOS and calls
    ``UserProvisioningService::resolve(LoginContext, User)``.
#.  ``Typo3SessionService`` adds the ``workos_auth.pending_login`` request
    attribute and starts ``FrontendUserAuthentication`` /
    ``BackendUserAuthentication``.
#.  ``WorkosTypo3AuthenticationService`` (registered with
    ``processLoginData``, ``getUser`` and ``authUser`` for FE and BE) returns
    exactly that user row; ``AllowPendingWorkosLoginRequestTokenListener``
    issues the ``core/user-auth/fe|be`` token TYPO3 expects.

Development
===========

..  code-block:: bash

    composer install
    composer ci                     # validate, lint, cgl, phpstan, unit, functional
    Build/Scripts/runTests.sh -s unit|functional|phpstan|cs|mutation
    typo3DatabaseDriver=pdo_sqlite Build/Scripts/runTests.sh -s functional

-   PHPStan level 8 with ``saschaegerer/phpstan-typo3``, strict and
    deprecation rules; no baseline.
-   PHPUnit 12: unit tests in :file:`Tests/Unit`, functional tests
    (sqlite locally, MariaDB 10.11 in CI) in :file:`Tests/Functional`.
-   The User Management widget bundle is built with esbuild in
    :file:`Build/user-management-widget` (``npm ci && npm run build``); the
    output in :file:`Resources/Public/JavaScript/` is committed and checked
    by the CI ``assets`` job.
-   Playwright smoke specs against a running site live in :file:`Tests/E2E`.

Localization
============

Labels use XLIFF 2.0 with ICU placeholders in
:file:`Resources/Private/Language/` (``locallang.xlf`` + ``de.*``). A unit
test fails when the German file misses an English key.
