# Testing Audit Report

_Skill applied:_ `typo3-testing`
_TYPO3 target:_ v14.x
_Extension:_ `workos_auth`

## State before this pass

- 5 unit tests (StateService only), PHPUnit 11.5, wired via
  `Build/phpunit/UnitTests.xml`.
- No functional tests, no Playwright, no runTests.sh, no CI matrix.
- `typo3/testing-framework ^9.2` already in `require-dev`.

## Coverage targets for this pass

Priority A — **regression tests for security fixes** (must not silently
break again):

1. `PathUtility::sanitizeReturnTo()` — open-redirect regression:
   - Relative paths (`/dashboard`) pass through.
   - `//evil.example/path` (protocol-relative) returns the fallback.
   - `/\evil.example`, `\\evil.example`, `\/evil.example` likewise.
   - Same-host absolute URL is accepted.
   - Different host is rejected.
   - Empty and whitespace-only input → fallback.
2. `FrontendCsrfService` — CSRF token regression:
   - Token issued for the same user+scope verifies.
   - Same user, different scope → fails.
   - Different session id → fails (simulates two users).
   - Empty token → fails.
3. `SecretRedactor::redact()`:
   - `sk_live_...`, `sk_test_...`, `client_...`, `Bearer ...`, and a
     JWT shape are each replaced with `[REDACTED]`.
   - Messages without secrets pass through unchanged.
4. `RequestBody`:
   - `fromRequest()` with array body, object body, null body.
   - `string()` returns defaults for non-scalars.
   - `trimmedString()` trims whitespace.

Priority B — **domain logic** that isn't covered yet:

- `WorkosConfiguration::normalizeInput()` + `validate()` (paths,
  default group CSV, allowed-domain parsing).
- `PathUtility::appendQueryParameters` / `joinBaseAndPath`.

Priority C — **functional** (deferred to a follow-up branch):

- Would need a running DB and HTTP stub for the WorkOS SDK. The
  scaffolding is cheap (Build/phpunit/FunctionalTests.xml + a
  `setUpBackendUser` smoke test) and sensible to add now even without
  populated test bodies, so future work can drop tests in without
  repeating setup.

## Planned deliverables

1. `Tests/Unit/Service/PathUtilityTest.php` — covers Priority A.1 +
   B's utility helpers.
2. `Tests/Unit/Service/RequestBodyTest.php` — covers A.4.
3. `Tests/Unit/Security/FrontendCsrfServiceTest.php` — covers A.2.
4. `Tests/Unit/Security/SecretRedactorTest.php` — covers A.3.
5. `Tests/Unit/Configuration/WorkosConfigurationTest.php` — covers B.
6. `Build/phpunit/FunctionalTests.xml` with the testing-framework
   bootstrap and a `Tests/Functional/` directory (empty for now so
   `composer test:functional` is valid).

## Out of scope (explicitly)

- runTests.sh + DDEV matrix and CI workflow (`enterprise-readiness`
  pass).
- Playwright E2E for the login flow (too heavy for a cleanup pass).
- Mutation testing (premature while unit coverage is still growing).
