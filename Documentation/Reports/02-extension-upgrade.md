# Extension Upgrade Report

_Skill applied:_ `typo3-extension-upgrade`
_TYPO3 target:_ v14.x only (no dual-version support)
_Extension:_ `workos_auth`

## Status

- `composer.json` and `ext_emconf.php` already constrain to TYPO3
  `^14.0` / `14.0.0-14.99.99` and PHP `^8.2`. **No change required.**
- No v13/v12 back-compat code, no deprecated `$GLOBALS['TYPO3_DB']`,
  no `\TYPO3\CMS\Core\Utility\GeneralUtility::_GP`, no removed Extbase
  `ActionController` APIs. **Codebase is already v14-clean.**
- Rector/Fractor passes would be no-ops, so we focus on PHPStan level 9
  findings.

## PHPStan level 9 findings (214 errors)

Error identifier distribution:

| Identifier                      | Count | Root cause                                         |
|---------------------------------|------:|----------------------------------------------------|
| `property.notFound`             |    62 | WorkOS SDK uses magic `__get` — no declared props  |
| `offsetAccess.nonOffsetAccessible` | 48 | Array access on `mixed`, SDK arrays, `$GLOBALS`    |
| `cast.string`                   |    28 | `(string)$workosUser->something` — source is mixed |
| `ternary.shortNotAllowed`       |    15 | Strict rule: `$x ?: $y` — replace with `??`        |
| `property.nonObject`            |    14 | Chained access on `mixed` SDK response fields      |
| `argument.type`                 |    12 | SDK-returned values passed into typed parameters   |
| `empty.notAllowed`              |     9 | Strict rule: `empty()` — replace with explicit `===` |
| `nullCoalesce.property`         |     6 | `$res->data ?? []` — property is declared non-null |
| `cast.useless`                  |     4 | `(string)$alreadyString` — drop redundant cast     |
| `arguments.count`               |     3 | `Connection::lastInsertId()` API change (see below) |
| `offsetAccess.notFound`         |     2 | Optional request body array keys                   |
| `function.alreadyNarrowedType`  |     2 | `method_exists($request, 'getUri')` on v14 always true |
| `cast.int`                      |     2 | `(int)$mixed` — narrow type first                  |
| `variable.implicitArray`        |     1 | `ext_emconf.php` classic `$EM_CONF[...]` pattern    |
| `variable.undefined`            |     1 | Same — `$_EXTKEY` no longer set by Core            |
| `nullsafe.neverNull`            |     1 | `?->` on non-null                                  |
| `method.nonObject`              |     1 | Method call on mixed (same SDK pattern)            |
| `function.strict`               |     1 | `base64_decode($x)` without strict flag            |
| `booleanNot.exprNotBoolean`     |     1 | `!$stringOrFalse`                                  |
| `arrayFilter.strict`            |     1 | `array_filter(...)` without callback               |

## Planned fixes

### 1. WorkOS SDK magic-property access — the big one (~130 errors)

The WorkOS PHP SDK uses `BaseWorkOSResource::__get()` to expose fields
dynamically from an internal values array. Classes like `User`,
`Organization`, `Invitation` declare only
`RESOURCE_ATTRIBUTES = [...]` constants; they do not declare PHP
properties, so PHPStan cannot type-check access like `$user->id`.

**Fix:** ship a PHPStan stub file
(`Build/phpstan/stubs/WorkOsResources.stub`) that declares the public
`@property` list for each SDK class we touch:

- `BaseWorkOSResource` → `public array $raw;`
- `User` → id, email, firstName, lastName, emailVerified, ...
- `Organization` → id, name
- `OrganizationMembership` → id, userId, organizationId, role, status
- `AuthenticationFactorTotp` → id, totp (array)
- `Session` → id, createdAt, expiresAt, impersonator
- `Invitation` → id, email, state, expiresAt, organizationId, token
- `MagicAuth` → id, userId
- `AuthenticationResponse` → user, organizationId, accessToken, refreshToken, impersonator, authenticationMethod
- `PaginatedResource` → `public array $data;`, listMetadata

This is the idiomatic PHPStan-on-weakly-typed-SDKs fix: zero runtime
cost, no `@var` inline tags, no casts to silence. Wire the stub via
`phpstan.neon`'s `stubFiles:` parameter.

### 2. TYPO3 v14 `Connection::lastInsertId()` signature change

In `UserProvisioningService.php` lines 106 and 136 we call:

```php
$connection->lastInsertId('fe_users')
```

TYPO3 v14's `Connection::lastInsertId()` takes **no arguments**
(Doctrine DBAL 4 compliance). Fix: drop the table argument.

### 3. Strict rules (ternary / empty / cast cleanup)

- Replace `$a ?: $b` with `$a ?? $b` in 15 places where the fallback
  is for `null`, or with an explicit `$a !== '' ? $a : $b` when the
  empty-string fallback is intentional.
- Replace `empty($x)` in 9 places with explicit `$x === null`,
  `$x === ''`, or `$x === []` as appropriate.
- Drop 4 redundant `(string)` casts and 2 redundant `(int)` casts
  where the value is already the target type.
- Drop 2 `method_exists($request, 'getUri')` checks — on v14 the
  Extbase `RequestInterface` always has `getUri()`.
- Drop 1 unnecessary nullsafe `?->`.

### 4. Explicit narrowing before cast

Replace patterns like
`(int)$queryBuilder->...->fetchOne()` with
`is_scalar($value) ? (int)$value : 0` where the return type is `mixed`.

### 5. Request body array type narrowing

Controllers take Extbase request body arguments as `array` but use keys
that PHPStan cannot verify. Fix: declare `@var array<string, mixed>`
on the local var **only at the system boundary** (request entry
point), then access with explicit isset/fallback.

### 6. `$GLOBALS['TYPO3_CONF_VARS'][...]` access

`ext_localconf.php` and `UserManagementController::renderWidget` index
into `$GLOBALS` which is `mixed`. Fix: narrow with a local `array`
variable first.

### 7. `ext_emconf.php` variable superglobal pattern

The classic `$EM_CONF[$_EXTKEY] = [...];` pattern is officially
deprecated documentation — TYPO3 Core still reads `$EM_CONF` but v14
no longer sets `$_EXTKEY` globally. Use an explicit literal key.

### 8. `array_filter` / `base64_decode` strict flags

- `array_filter($names)` → `array_filter($names, static fn ($v) => $v !== '' && $v !== null)`.
- `base64_decode($value)` → `base64_decode($value, true)`.

### 9. `Tests/Unit/Security/StateServiceTest.php` — missing constructor arg

`StateService` now requires a secret. Update the fixture to pass one
(will be properly expanded in the testing-skill pass).

## Out of scope for this pass

- Writing new tests (handled by `typo3-testing` skill).
- Hardening CSP beyond what exists (handled by `typo3-security` skill).
- Documentation updates (handled by `typo3-docs` skill).
