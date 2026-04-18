# Final Verification — Third Sweep

_Extension:_ `workos_auth` · _Release:_ `0.25.0` · _TYPO3 target:_ v14.2

## Green status

| Check                                | Result                         |
|--------------------------------------|--------------------------------|
| PHPStan (`level: max`)               | ✅ No errors                   |
| Unit tests                           | ✅ 82 passing, 1 skipped (*)   |
| Unit assertions                      | 151                            |
| Functional tests (`Tests/Functional`) | 3 files, harness-gated (**)   |
| `composer audit --no-dev`            | ✅ No advisories               |
| `ext_emconf.php` version             | `0.25.0`                       |
| `guides.xml` release / version       | `0.25` / `0.25`                |

(\*) XliffParityTest skips the `locallang_db.xlf` pair because the
TCA labels are English-only by design.

(\*\*) Functional tests require the TYPO3 testing-framework DB
bootstrap; they are not part of the default `composer test:unit`
run. `composer test:functional` exists for CI invocation.

## Changes landed this sweep (15 commits)

**PHPStan hardening**
- Tightened to `level: max` with explicit `array<string, mixed>`
  annotations across Classes / Configuration / Tests; refactored
  the backend widget-token path to narrow via
  `instanceof WidgetTokenResponse`.

**Workspaces**
- `Configuration/Backend/Modules.php` — every entry registers
  `workspaces => 'live'`.
- `Configuration/TCA/tx_workosauth_identity.php` — intent comment
  above ctrl.
- `Tests/Functional/Service/IdentityServiceWorkspaceTest.php` —
  proves identity reads work under a workspace aspect.
- `Documentation/Configuration.rst` — "Workspaces" subsection
  extended with the live-only modules note.

**Conformance**
- `de.locallang.xlf` gets the two missing CSRF-invalid trans-units.
- New `de.locallang_mod_users.xlf` and `de.locallang_mod_setup.xlf`.
- Inline `<style>` extracted from Account/Team dashboards to
  `Resources/Public/Css/Frontend/*.css`, loaded via `<f:asset.css>`.

**TYPO3-focused security**
- `sanitizeErrorMessage` fallback swapped from raw WorkOS message to
  translated `error.generic`.
- `UserManagementController::tokenAction` now validates a
  FormProtection token.
- Explicit `isCurrentBackendUserAdmin()` guard on the three POST
  actions.

**Broad security (OWASP/CWE)**
- High-severity authorization gap closed: new
  `WorkosTeamService::assertMemberOfOrganization()` + `findInvitation()`
  gate every Team* controller action against the authenticated user's
  active organization memberships.
- `team.flash.forbidden` trans-unit (EN + DE) surfaces the rejection.

**Testing**
- `Tests/Unit/Configuration/XliffParityTest.php` guards EN ↔ DE
  trans-unit parity across all XLIFF pairs.

**Docs**
- `Documentation/Changelog.rst` — new `0.25.0` entry.
- `README.md` — Security / Quality sections refreshed for 0.25.0.
- `ext_emconf.php` — version bump.
- `Documentation/guides.xml` — release / version bump.

## Commands reproducing this verification

```bash
# Use PHP 8.5 (or 8.2+)
export PATH="/opt/homebrew/Cellar/php/8.5.4/bin:$PATH"

vendor/bin/phpstan analyse --no-progress --memory-limit=2G
vendor/bin/phpunit -c Build/phpunit/UnitTests.xml --no-progress
composer audit --no-dev
```

## Follow-up backlog (not in this release)

- Rate-limiting middleware for pre-auth backend endpoints.
- Thin service interfaces over `WorkosTeamService` /
  `WorkosAccountService` so the authorization assertions can be
  exercised by unit tests without booting the SDK.
- Mutation-score (Infection) threshold gate in CI.
- Functional test coverage for `Typo3SessionService` cookie
  issuance.
