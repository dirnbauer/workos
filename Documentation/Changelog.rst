..  include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

All notable changes to this extension are documented in this file.

..  _changelog-0-24-0:

0.24.0 — Level max and expanded coverage
========================================

Follow-up to 0.23.0. Still no user-facing behavior change; continued
internal hardening and test coverage uplift.

..  rubric:: Quality

-   PHPStan moves from level 9 to **level max** with zero errors.
-   New ``WebConsulting\WorkosAuth\Configuration\WorkosSettings``
    array-shape alias makes ``WorkosConfiguration::all()`` return a
    fully-typed settings record so getters no longer need to cast
    ``mixed`` values.
-   New ``Classes/Security/MixedCaster`` helper centralises
    mixed-to-scalar narrowing. Middlewares, controllers, login
    provider, services and event listener all flow query params,
    parsed bodies, session data, ``$GLOBALS['EXEC_TIME']`` and
    database row values through it.
-   ``WorkosAuthenticationService`` declares precise return shapes on
    ``handleCallback``, ``authenticateWithPassword``,
    ``authenticateWithMagicAuth``,
    ``authenticateWithEmailVerification``, ``sendMagicAuthCode``.
-   PHPStan stub for ``WorkOS\Widgets::getToken()`` accepts
    ``list<string>`` — matches the runtime contract, works with the
    string-const ``WidgetScope`` identifiers.
-   phpat architecture rules guard the
    Controllers -> Services -> Security boundary.
-   Infection mutation testing configured; GitHub Actions runs it
    against every push.

..  rubric:: Testing

-   77 unit tests (was 43). New coverage: ``MixedCaster`` scalar
    narrowing, extended ``PathUtility`` helpers,
    ``IdentityTableTcaTest`` locking in the workspace-exclusion
    contract.
-   Functional tests for ``IdentityService`` (round-trip, idempotent
    update, link by local user, JSON profile decode) and
    ``UserProvisioningService`` (create-or-link against real
    ``fe_users``).
-   Playwright E2E scaffold under :file:`Tests/E2E/` with a smoke
    test for the WorkOS Login plugin.

..  rubric:: Developer experience

-   :file:`.editorconfig` at project root.
-   :file:`Build/Scripts/runTests.sh` wraps PHPStan, PHPUnit and
    Infection behind a single ``-s`` flag (``phpstan``, ``unit``,
    ``functional``, ``mutation``, ``ci``).
-   Inline ``<style>`` block in ``Frontend/Login/Show.html``
    extracted to :file:`Resources/Public/Css/plugin-login.css` and
    loaded via ``<f:asset.css>``.
-   GitHub Actions workflow runs PHPStan level max, PHPUnit and
    ``composer audit`` on every push across PHP 8.2 / 8.3 / 8.4.

..  _changelog-0-23-0:

0.23.0 — Security hardening and level 9
=======================================

This release contains no user-facing behavioral changes. It bundles
the output of a full conformance, workspaces, security, and testing
cleanup pass.

..  rubric:: Security

-   CSRF tokens are now required on every state-changing action of
    the Account Center and Team frontend plugins (profile updates,
    password change, MFA enroll / verify / cancel, factor delete,
    session revoke; invitation send / resend / revoke; Admin Portal
    launch). Tokens are issued per frontend session and scoped per
    plugin, so a token minted for one dashboard cannot be replayed
    on the other.
-   Open-redirect fix in ``PathUtility::sanitizeReturnTo()``:
    protocol-relative candidates (``//evil.example/path``) and
    their slash/backslash permutations are no longer accepted as
    safe relative paths. Same-host absolute URLs still round-trip.
-   Secret redaction: ``SecretRedactor`` strips WorkOS API keys,
    client ids, bearer tokens, and bare JWTs from every log message
    the extension emits. Middleware no longer echoes raw
    ``Throwable::getMessage()`` into HTTP responses — the redacted
    original goes to the log; users see a translated generic error.
-   :file:`Configuration/TCA/tx_workosauth_identity.php` explicitly
    marks the identity mapping table as ``versioningWS=false``,
    ``adminOnly``, ``hideTable``. The table is authentication
    state, not editorial content; this closes an accidental-
    enabling path.

..  rubric:: Quality

-   PHPStan level 9 passes with zero errors. Wired via
    ``saschaegerer/phpstan-typo3 ^3.0``, ``phpstan/phpstan ^2.1``,
    ``phpstan-strict-rules``, ``phpstan-deprecation-rules``. Custom
    stubs under :file:`Build/phpstan/stubs/` teach PHPStan about
    the WorkOS SDK's magic-``__get`` resources.
-   43 unit tests covering the three security fixes, the new
    ``RequestBody`` / ``FrontendCsrfService`` / ``SecretRedactor``
    helpers, and configuration validation. ``composer test:unit``
    runs the suite.
-   ``composer phpstan`` / ``composer phpstan:baseline`` scripts
    added.
-   :file:`composer.json` gains TER-friendly metadata (homepage,
    authors, keywords, support URLs, sorted packages).

..  rubric:: Extension upgrade polish

-   :file:`ext_emconf.php` stops using the removed ``$_EXTKEY``
    superglobal; the extension key is now hard-coded.
-   :file:`ext_localconf.php` narrows
    ``$GLOBALS['TYPO3_CONF_VARS']`` before writing the login-
    provider entry.
-   ``Connection::lastInsertId()`` calls drop the table argument
    that Doctrine DBAL 4 / TYPO3 v14 removed.

..  rubric:: Internal

-   ``WorkosConfiguration::SUPPORTED_SOCIAL_PROVIDERS`` is the
    single source of truth for the social-provider allowlist;
    middlewares reference it instead of duplicating the list.
-   :file:`Classes/Service/RequestBody.php` centralises PSR-7
    parsed-body narrowing so controllers never cast ``mixed`` on
    their own.

..  _changelog-earlier:

Earlier releases
================

See ``git log`` for the 0.22.x and earlier history.
