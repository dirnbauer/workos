# Testing Audit — Third Pass

_Skill applied:_ `typo3-testing`
_Extension:_ `workos_auth`

## Snapshot

- Unit: 11 test classes, 77 methods, 139 assertions.
- Functional: 3 test classes (IdentityService, IdentityServiceWorkspace,
  UserProvisioningService).
- Architecture: 1 phpat rule (`LayeringTest`).
- Mutation: `infection.json5` scaffolded, not wired into CI.
- Coverage: not measured in CI; PHPStan level max.

## What changed since pass 2 needs coverage

Two new security-critical code paths landed in the security sweeps
and have no direct regression test:

1. `Classes/Service/WorkosTeamService::assertMemberOfOrganization()`
   — the authorization gate for the four Team* actions.
2. `Classes/Controller/Backend/UserManagementController::isCurrentBackendUserAdmin()`
   — defence-in-depth admin guard on the three POST routes.

Both sit behind `final` classes that call the WorkOS SDK
(`WorkOS\UserManagement`, `WorkOS\Widgets`). PHPUnit 11 can't mock a
final class without refactoring, which makes a unit test require an
interface seam we don't have yet.

## Tractable additions this pass

### T1 — XLIFF invariants as unit tests

The prior passes added `error.generic` and `team.flash.forbidden`
(EN + DE parity). Add a tiny unit test that asserts the key set in
`de.locallang.xlf` covers everything in `locallang.xlf`. Catches the
exact kind of drift we fixed manually this sweep and costs nothing
to run.

### T2 — Regression guard for the sanitizeErrorMessage fallback

`BackendWorkosAuthMiddleware::sanitizeErrorMessage()` now returns
`error.generic` instead of the raw WorkOS message on a no-match.
Cover it with reflection — the method is private, but the behaviour
is worth locking in so a future edit that restores `return $message`
fails a test.

### T3 — Admin guard behaviour is testable via the existing `WorkosConfiguration`-free path

`isCurrentBackendUserAdmin()` reads `$GLOBALS['BE_USER']`. A unit
test can set `$GLOBALS['BE_USER']` to a `BackendUserAuthentication`
stub, invoke the check, and verify admin vs non-admin. Covers the
defence-in-depth contract without booting TYPO3.

## Structural gaps acknowledged (deferred)

- **WorkosTeamService** / **WorkosAccountService** /
  **WorkosAuthenticationService** have no direct tests. Covering
  them properly requires either (a) introducing thin service
  interfaces and a test-only in-memory double, or (b) booting the
  functional test harness and using a WorkOS sandbox key. Both are
  larger than a single testing sweep.
- **Typo3SessionService** — cookie issuance is reviewed by eye; a
  functional test booting a request pipeline is the right place
  for this but demands the functional harness.
- **Mutation testing** — `infection.json5` exists; CI integration
  + ≥60 % MSI threshold still pending.

## Plan (this sweep)

1. Add `Tests/Unit/Configuration/XliffParityTest.php` (T1).
2. Add `Tests/Unit/Middleware/BackendWorkosAuthMiddlewareSanitizeErrorTest.php`
   using reflection to cover the private method (T2).
3. Add `Tests/Unit/Controller/Backend/UserManagementControllerAdminGuardTest.php`
   — asserts `isCurrentBackendUserAdmin()` for admin, non-admin
   and missing-global cases (T3).

## Commands to re-run after this sweep

```bash
vendor/bin/phpstan analyse --no-progress --memory-limit=2G
vendor/bin/phpunit -c Build/phpunit/UnitTests.xml --no-progress
```
