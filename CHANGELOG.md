# Changelog

## 2.3.2 - 2026-09-24

### Fixed

- **Sign-in / sign-up links grew until `414 URI Too Long`:** the Login plugin put the whole current URL, its own `returnTo` included, into the `returnTo` of the "Sign up" and "Sign in" links, so every toggle nested the previous URL; a link crawl found links of about 8,700 characters. Return targets are now canonical same-site paths: a nested `returnTo`, the arguments of the WorkOS plugins, one-shot state tokens, the login hint and a `cHash` that no longer matches are dropped. Toggling any number of times yields the same link, and links printed by 2.3.1 are flattened when opened.
- **A requested return target got lost:** the plugin read `returnTo` only as a plain query parameter, while its own links and hidden form fields send it as `tx_workosauth_login[returnTo]`. A `returnTo` given to the login page now survives the toggle and is honoured by the password, email-code and sign-up forms.
- **Admin Portal return:** the Team plugin gave WorkOS the URL of its `launchPortal` POST as return URL, which answered the way back with an error. The portal now returns to the organization's dashboard.
- **Backend login provider:** "Login with WorkOS" passed the route TYPO3 asked the login screen to open (`redirect=web_layout`) as `returnTo`, which the login endpoint refused. It now continues with that route through `/typo3/main?redirect=…&redirectParams=…`, as Core does; the user management sign-in from 2.3.1 uses the same helper.

### Security

- `returnTo` stays a same-host target (2.3.0 validation unchanged) and is stored and embedded as a path: a same-origin URL is reduced to its path, a same-origin URL whose path starts with `//` is refused, and a target longer than 2,048 characters falls back to the default, so a crafted link cannot produce over-long URLs either.

### Changed

- Without a requested `returnTo`, the password, email-code and sign-up forms of the Login plugin return to the page of the plugin, like its hosted-login and social buttons always did. Before, the forms posted that page under the plugin namespace, the value was ignored, and they went to `frontendSuccessRedirect`. `frontendSuccessRedirect` still applies to `/workos-auth/frontend/login` without `returnTo`.

### Tests

- Functional tests render the Login plugin through the TYPO3 frontend and follow its sign-in / sign-up links for eight rounds (the same links every time, `returnTo` a path), replay a nested 2.3.1 link, a requested target, foreign targets and a sign-up submission, and check what the frontend login endpoint stores. Backend tests cover the login provider's route target and the state the backend login endpoint stores. Unit tests cover the canonical form, the toggle loop and the length limit.

## 2.3.1 - 2026-09-23

### Fixed

- **User management said "log in again" to logged-in users:** the module looked for the backend user in a `backend.user` request attribute that TYPO3 does not set, so every administrator saw "No backend user session could be detected". It now reads the backend user from the session, as Core does, and keeps that message for requests without a backend user.
- **Password sessions without WorkOS link:** an administrator who signed in with the TYPO3 password and was never linked to a WorkOS user now reads that the User Management widget needs a WorkOS user, with a primary "Sign in with WorkOS" action. It starts the backend WorkOS login (validated `returnTo`, single-use state, PKCE, the backend user's email as login hint) and comes back to the module; the accounts are linked through the verified email address.
- **English buttons in a German backend:** the document header's Save and reload buttons of the three modules carry this extension's labels in English and German instead of Core labels, which stay English without a Core language pack.

### Tests

- Functional tests render the modules without the `backend.user` attribute, as a real backend request does, cover the missing-session and the WorkOS sign-in states, and check the German document header and sign-in labels in all three modules.

## 2.3.0 - 2026-09-23

A security review of the sign-in flows, and backend screens rebuilt from Core markup.

### Security

- **Account takeover through unverified email:** linking an existing TYPO3 account by email (on by default, backend administrators included) did not check that WorkOS had verified the address. Linking by email and creating backend users now require a verified email, and several accounts with one address are refused instead of letting the row order pick one.
- **Open redirect:** `returnTo` values with a tab or line break between the slashes (`/<TAB>/evil.example`) passed the protocol-relative check; control characters and backslashes are now refused.
- **Content injection on the login page:** the backend login printed any `workosAuthError` / `workosAuthNotice` text from its URL. Messages are now stored server-side, bound to the browser and shown once; the URL carries a token only.
- **Logout left WorkOS signed in:** logging out of TYPO3 now revokes the WorkOS session the sign-in opened, so a shared computer does not sign the previous person in again.
- **PKCE:** the hosted login sends a PKCE challenge on top of the client secret; the verifier stays in the server-side state of the login attempt.
- **Impersonation:** sessions a WorkOS administrator started through impersonation are refused for the backend and logged for the frontend.
- **Disabled accounts:** disabled accounts and accounts outside their start/end time no longer sign in through WorkOS or the MCP server, and a disabled linked account no longer falls through to another account.
- **API key exposure:** the setup module no longer prints the stored API key into the page; the field is write-only with a masked hint.
- **CSP:** the relaxation for the user management widget applied to the whole backend; it now applies to that module page only.
- Malformed `state` tokens no longer crash the backend login page.

### Changed

- The WorkOS login provider uses Core login markup: Core inputs, the orange login button, default buttons for the sign-in code and "Continue with Google/Microsoft/GitHub/Apple", Core alerts and infoboxes. The heading box, the relocated provider switch and their script and stylesheet are gone.
- Setup, MCP server and user management render inside the Core Module layout: document header with Save, reload and shortcut, h1, infoboxes, Core tables with copy-to-clipboard buttons, token-based badges, form labels and switches, Core flash messages. Shared field partials replace most of the hand-written form markup.
- MCP settings are edited in the MCP module only; saving the setup form no longer resets them to their defaults.
- Sign-in failures name the reason (not linked, email not verified, account disabled, ambiguous email, impersonation).
- Exactly one connection selector is sent to WorkOS: a chosen social provider, else the configured connection, else AuthKit (optionally pinned to an organization).
- Social buttons read "Continue with …"; the extension icon uses the v14 line-art colours.
- Typed class constants throughout.

### Removed

- Infection (never wired into CI, and its configuration could not run), the custom copy-URLs script, the dashboard screenshot shown in the setup module, and eleven unused labels.

### Dependencies

- PHPUnit 12.5 → 13.3, typo3/coding-standards 0.8 → 0.9, phpat 0.11 → 0.12; `workos/workos-php` stays on 9.4.0, the newest release.
- Widget build: React 19.3, TanStack Query 5.103.2, SWR 2.5.1, Radix Themes 3.3.0, `@workos-inc/widgets` 1.18.0 (newest); Playwright 1.63 for the E2E suite.
- CI: PHP 8.5 is a required leg, MariaDB 11.4, Node 24, `actions/checkout` v7, `actions/cache` v6, `actions/setup-node` v7.

### Upgrade notes

- Backend users whose WorkOS account has **no verified email** can no longer be linked by email; verify the email in WorkOS or link the account explicitly.
- If several `be_users` / `fe_users` share one email, link the right one to the WorkOS account (sign in once with linking disabled, or fix the duplicate emails).
- Links that put `workosAuthError=<text>` on the backend login URL no longer show that text.

## 2.2.0 - 2026-09-18

### Changed

- Require `workos/workos-php` `^9.4`; refreshed the `@workos-inc/widgets` bundle build (`npm update`, lockfile committed, CI `assets` job rebuilds and diffs it).
- Typed domain model: `LoginContext`, `SocialProvider` and `McpAuthenticationMode` enums replace the frontend/backend + `fe_users`/`be_users` string pairs, the social provider constant list and the MCP mode constants. `IdentityService` and `UserProvisioningService::resolve()` take a `LoginContext`; the redundant `userTable` parameters are gone.
- `WorkosClientFactory::client()` is the only SDK entry point and enforces configured credentials (three `assertConfigured()` copies removed). `UserProvisioningService` resolves link → email → create in one flow instead of three duplicated branches.
- `WorkosConfiguration::save()` persists and flushes for both backend modules; `validate()` works on normalized settings; MCP "requires WorkOS" is decided in one place.
- Backend login endpoints and multi-step state contexts are constants of `BackendWorkosAuthMiddleware`; the provider identifier is `WorkosBackendLoginProvider::IDENTIFIER`. Backend login CSS moved from PHP/Fluid into `Resources/Public/Css/Backend/`.
- `WorkosErrorMessageResolver` now also maps password-change and invitation errors (was duplicated in the controllers).
- Tooling: `.Build/` Composer layout, PHPUnit 12, PHPStan level 8 (portfolio policy), testing-framework bootstraps, `.gitignore`, single `ci.yml` with an `assets` job.
- Documentation restructured into Introduction, Installation, Configuration, Usage, Developer; README is a short quick start.

### Fixed

- Team plugin: organization domains were never shown (the SDK returns `OrganizationDomain` objects, not arrays).
- Backend login: "Back to sign in" now returns to the login form instead of starting the hosted AuthKit flow.

### Removed

- The unused `cookiePassword` setting (validated but never used); stale values in `settings.php` are ignored.
- Raw (non-JSON) OAuth `state` fallback, `WorkosTeamService::describePortalIntents()`, `*DefaultGroupCsv()` getters, the `architecture` CI job (phpat rules already run inside PHPStan), `docs/audits/` snapshots, duplicate PNGs under `Resources/Public/Images/`.

### Added

- Unit tests for the domain enums, `MixedCaster::stringKeyedArray()`, `RequestBody::group()`, `PathUtility::originFromRequest()/normalizeOrigin()/siteBaseUrl()`, `WorkosConfiguration::save()`, `WorkosClientFactory`, the MCP JSON-RPC dispatcher and `McpServerMiddleware` (batches, notifications, discovery documents, 401 hint); functional tests for backend provisioning and the `ext_localconf.php` plugin / login provider registration.

## 2.1.0 - 2026-09-12

### Changed

- Require `workos/workos-php` `^9.3` (was `^5.0.3`) and adapt the wrapper services to the regenerated SDK: organization memberships via `OrganizationMembershipService`, AuthKit screen hint via `RadarStandaloneAssessRequestAction`, `PaginationOrder` instead of `EventsOrder`, `createUser()` returns `UserCreateResponse`, email-verification handshake data read from `ApiException::$rawBody`. The hand-written PHPStan SDK stubs are gone.
- Modernized the code for PHP 8.4 / TYPO3 14: shared `LabelTranslator`, `AbstractFrontendController` and `ResponseUtility`, readonly services with typed constants, `#[Autoconfigure(public: true)]` instead of `Services.yaml` entries, request attributes instead of `$GLOBALS['BE_USER']` / `$_SERVER` in the backend User Management module.
- Bundled WorkOS User Management widget updated to `@workos-inc/widgets` 1.18.0.
- Single `ci.yml` workflow: lint, coding standards, PHPStan (level max, policy minimum level 8), unit (PHP 8.4, PHP 8.5 allowed to fail), functional (MariaDB 10.11), architecture.
- README slimmed to a quick start; details moved into `Documentation/`.

### Removed

- Unreferenced `WorkosBackendUserAuthentication` / `WorkosFrontendUserAuthentication` classes and five unused `backend.login.*` labels.

### Security

- Require WorkOS MCP bearer tokens to target the exact TYPO3 MCP resource audience and carry a future expiration time.
- Require TYPO3 14.3.7 or newer (TYPO3-CORE-SA-2026-022) and `paragonie/sodium_compat` patched releases; update the widget build dependency.

The full, versioned release history is [Documentation/Changelog.rst](Documentation/Changelog.rst).

## 2.0.0 - 2026-08-06

### Changed

- Require PHP 8.4 for the TYPO3 14 runtime.
- Normalize the public PHP namespace to `Webconsulting\\WorkosAuth` across runtime code, configuration, tests and documentation.

## 1.0.0 - 2026-05-24

### Changed

- Prepared the first official release as `1.0.0` in TYPO3's Composer
  metadata.
- Removed legacy classic-mode metadata; TYPO3 14.3+ release metadata now
  lives in `composer.json` via `extra.typo3/cms.version` and
  `Package.providesPackages`.
- Dropped broad TYPO3 14 minor compatibility and now require TYPO3 `^14.3`
  packages only.
- Updated the lock-file install to TYPO3 14.3.1 and current PHPStan 2.x
  tooling while keeping PHP 8.2 as the minimum runtime.
- PHPStan now runs at `level: max` with `saschaegerer/phpstan-typo3` 3.0.1.
- CI covers PHP 8.2, 8.3, 8.4, and 8.5 for static analysis, unit tests,
  and functional tests.
- Converted extension labels to XLIFF 2.0 and named ICU placeholders.

### Fixed

- Replaced removed TYPO3 14 TCA `ctrl.searchFields` usage with field-level
  searchable configuration.
- Added PHP 8.3 `#[Override]` attributes required by max-level analysis.
- Normalized external API arrays before returning them from MCP/schema
  services.
- Added German database labels for `tx_workosauth_identity` so all shipped
  label files have English/German coverage.

### Added

- TYPO3 MCP server endpoint with anonymous development mode, WorkOS-protected production mode, WorkOS-authorized Connect application discovery, and TYPO3 FE/BE group introspection.
- Dedicated WorkOS → MCP Server backend module for endpoint URLs, auth mode, AuthKit domain, WorkOS discovery, server limit, and verbose logging.

## 0.26.0 - 2026-04-22

### Changed

- The extension remains TYPO3 14 only. Previous-major compatibility paths are no longer part of the active codebase.
- PHPStan now runs at level 9 with `saschaegerer/phpstan-typo3`.
- Official TYPO3 coding standards are enforced via `typo3/coding-standards` and a generated `.php-cs-fixer.dist.php`.
- `composer ci` now runs coding standards, PHPStan, unit tests, and functional tests together.

### Security

- Team admin actions now require an active WorkOS `admin` or `owner` role.
- Account-center factor deletion and session revocation now verify object ownership before using the WorkOS API key.
- Frontend auth POST flows now require request tokens.
- Frontend `returnTo` handling is sanitized consistently before TYPO3 session creation.

### Workspaces

- Identity records remain live-only (`versioningWS=false`) and backend modules remain live-only (`workspaces => 'live'`).
- Workspace-sensitive identity lookups continue to be covered by functional tests.
