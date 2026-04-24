..  include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

All notable changes to this extension are documented in this file.

..  _changelog-unreleased:

Unreleased
==========

-   Backend WorkOS login accepts TYPO3's default
    ``BE.cookieSameSite = strict`` again. After the external WorkOS
    callback, the extension returns a same-origin continuation page so
    the strict backend session cookie is sent on the final backend
    navigation.
-   Frontend email-code login now completes the TYPO3 auth-service
    handoff after the plugin POST token has been validated. The
    pending WorkOS login listener only swaps in TYPO3's
    ``core/user-auth/fe`` token for server-created pending-login
    requests and leaves invalid request-token states untouched.
-   Frontend magic-auth verification now follows the same
    email-verification continuation as password login. If WorkOS
    returns ``email_verification_required`` after a valid magic code,
    the extension clears the magic-auth state and stores the pending
    email-verification token in the frontend session instead of
    dropping back to the generic login form.
-   Documentation refreshed to describe the narrow TYPO3
    request-token handoff, server-side WorkOS pending-token storage,
    and the verified test-suite size after the frontend login fix.

..  _changelog-0-26-0:

0.26.0 — TYPO3 14 tooling + auth hardening
==========================================

Follow-up to 0.25.0. Keeps the extension TYPO3-14-only, hardens the
frontend auth/account flows, and aligns the quality tooling with the
official TYPO3 coding standards package.

..  rubric:: Security

-   The Team plugin now requires an active WorkOS role slug of
    ``admin`` or ``owner`` before invitation management and Admin
    Portal actions are allowed. Plain organization membership is no
    longer enough.
-   ``AccountController::deleteFactorAction()`` and
    ``revokeSessionAction()`` now verify that the submitted
    WorkOS factor/session id belongs to the currently linked WorkOS
    user before the API key is used.
-   All frontend auth POST flows now require a request token:
    password sign-in, native sign-up, magic-auth send/verify, email
    verification submit, and email-verification resend.
-   ``returnTo`` is sanitized consistently across the frontend auth
    flows before TYPO3 creates the session, closing the remaining
    open-redirect path in credential-based logins.
-   Backend WorkOS sign-in restored after a TYPO3 v14 request-token
    regression: ``BackendWorkosAuthMiddleware`` now wraps every POST
    endpoint (password, magic-auth send/verify, email-verify
    send/resend) in a private ``RequestTokenMiddleware`` invocation
    and revokes the consumed signing-secret so each token is
    single-use.

..  rubric:: Quality

-   PHPStan now runs at ``level: 9`` with
    ``saschaegerer/phpstan-typo3 ^3.0`` as the TYPO3-specific
    framework plugin. Request-attribute mappings and the generated
    TYPO3 container XML are configured explicitly in
    :file:`phpstan.neon`.
-   Official TYPO3 coding standards are now enforced via
    ``typo3/coding-standards ^0.8``. The repository ships a generated
    :file:`.php-cs-fixer.dist.php` and exposes ``composer cs:check`` /
    ``composer cs:fix``.
-   New ``composer ci`` script runs TYPO3 coding standards, PHPStan,
    unit tests, and functional tests in one command.
-   GitHub Actions static analysis now checks TYPO3 coding standards
    in addition to PHPStan.

..  rubric:: Fixes

-   ``WorkosAuthenticationService::resolveCurrentWorkosUserId`` now
    narrows ``$GLOBALS['BE_USER']`` defensively before reading the
    user record, removing a PHPStan ``mixed`` warning.
-   Backend identity rebinding: when a previously-linked WorkOS
    identity returns under a different email/locale, the existing
    ``be_users`` row is updated in place instead of producing a
    duplicate identity row.
-   User Management widget honours the active backend color scheme
    (light / dark) and skips invalid WorkOS membership status
    filters that the SDK now rejects.
-   The User Management widget is bound to the authenticated WorkOS
    session of the current backend user (not just the API-key
    scope), so admins can only see organizations they actually
    belong to.
-   Frontend callback URLs are built absolute even when a TYPO3 site
    base lacks a host; the WorkOS authorization URL was previously
    rejected for such sites.
-   Backend WorkOS callback bounces through a small HTML page so the
    TYPO3 session cookie survives ``SameSite=Strict``.

..  rubric:: UI

-   Social login buttons (frontend plugin and backend login) get a
    cleaner card style: white background, bold label, soft drop
    shadow, and a subtle lift on hover. The "Or sign in with"
    divider gets matching uppercase styling.
-   Backend login labels rewritten to be concise and use the formal
    German "Sie"-Form. The provider switcher is rendered as a
    chevron next to the heading.
-   Custom-metadata table layout in the signed-in profile panel
    tightened.
-   Frontend content elements grouped under a dedicated **WorkOS**
    group in the new content element wizard.

..  rubric:: Testing

-   Additional unit coverage for ``WorkosConfiguration``
    normalization branches that Infection flagged. CI is opted into
    Node 24 to silence upcoming GitHub Actions runtime warnings.
-   Unit coverage was expanded for configuration normalization,
    request-token validation, and translation parity. The skipped
    test waits for a German ``de.locallang_db.xlf`` to land.

..  _changelog-0-25-0:

0.25.0 — Authorization + workspaces polish
==========================================

Follow-up to 0.24.0. Third conformance / security / docs sweep.

..  rubric:: Security

-   **High:** close an authorization gap in the four Team plugin
    actions that accept ``organizationId`` / ``invitationId`` from
    POST bodies (``inviteAction``, ``resendInvitationAction``,
    ``revokeInvitationAction``, ``launchPortalAction``). Before this
    release, a logged-in frontend user could invite or revoke
    members of — or mint Admin Portal links for — any organization
    the app-scoped WorkOS API key could reach. A new
    ``WorkosTeamService::assertMemberOfOrganization()`` gate runs
    before every SDK call, and invitation actions first resolve the
    owning org via a new ``findInvitation()`` wrapper. A translated
    ``team.flash.forbidden`` surfaces the rejection.
-   ``UserManagementController::tokenAction`` now validates a
    scoped TYPO3 request token before minting a WorkOS widget token.
    The backend module passes the token via a new
    ``data-csrf-token`` attribute on the mount element.
-   All three POST routes in the backend User Management module
    (``token``, ``join``, ``createOrganization``) now call an
    explicit ``isCurrentBackendUserAdmin()`` guard in addition to
    the module's ``access => 'admin'`` gate.
-   ``sanitizeErrorMessage()`` in both backend middleware and
    ``LoginController`` now falls back to a new ``error.generic``
    translated message instead of echoing the raw WorkOS error
    string into the redirect URL. The original remains in the log
    via ``SecretRedactor::redact()``.

..  rubric:: Workspaces

-   WorkOS backend modules (``workos``, ``workos_users``,
    ``workos_setup``) register with ``workspaces => 'live'`` so they
    are only visible in the LIVE workspace. Configuration and
    widget-token actions are not meaningful inside a custom
    workspace.
-   TCA ctrl on ``tx_workosauth_identity`` carries a short intent
    comment explaining why ``versioningWS`` stays ``false``.
-   New ``Tests/Functional/Service/IdentityServiceWorkspaceTest``
    proves identity reads still succeed when a workspace aspect is
    active — the intended behaviour for an auth extension that must
    work under workspace preview.

..  rubric:: Conformance

-   ``de.locallang.xlf`` reaches parity with the English source:
    adds ``account.flash.csrfInvalid`` and ``team.flash.csrfInvalid``.
-   New ``de.locallang_mod_users.xlf`` and
    ``de.locallang_mod_setup.xlf`` translate the backend module
    metadata.
-   Large inline ``<style>`` blocks in
    :file:`Resources/Private/Templates/Frontend/Account/Dashboard.html`
    and :file:`Resources/Private/Templates/Frontend/Team/Dashboard.html`
    move to dedicated CSS files under
    :file:`Resources/Public/Css/Frontend/` and load via
    ``<f:asset.css>``.

..  rubric:: Testing

-   New ``Tests/Unit/Configuration/XliffParityTest`` fails when any
    translation key added to ``locallang.xlf`` forgets its German
    counterpart (or vice versa).
-   Unit suite moves from 77 tests / 139 assertions to 82 / 151
    (further uplifted to 83 / 166 in the post-0.25.0 mutation-
    coverage work — see :ref:`Unreleased <changelog-unreleased>`).

..  rubric:: Quality

-   PHPStan ``level: max`` remains clean after the level-max
    uplift. ``treatPhpDocTypesAsCertain: false`` keeps stub-based
    type hints pragmatic.

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
