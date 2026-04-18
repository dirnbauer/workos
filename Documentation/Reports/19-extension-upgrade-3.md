# Extension Upgrade Report — Third Pass

_Skill applied:_ `typo3-extension-upgrade`
_Extension:_ `workos_auth`
_TYPO3 target:_ v14.2 (v14-only, no dual-version)

## Verdict

Still upgrade-clean. Passes 1 and 2 already cleared the v13→v14 work;
there is nothing for Rector, Fractor, or `php-cs-fixer` to rewrite. This
pass re-validates the extension against the v14-specific changes the
skill asks about.

## Re-audit results

### PHP & composer

- `composer.json` pins all `typo3/cms-*` requires to `^14.0`, PHP to
  `^8.2`. `ext_emconf.php` mirrors the constraint (`14.0.0-14.99.99`
  and `8.2.0-8.5.99`).
- Dev toolchain is on the latest majors compatible with v14:
  `phpstan/phpstan ^2.1`, `saschaegerer/phpstan-typo3 ^3.0`,
  `phpunit/phpunit ^11.5`, `typo3/testing-framework ^9.2`.
- No deprecated package dependencies left.

### TCA (`Configuration/TCA/`)

- `tx_workosauth_identity.php` declares the six data columns needed
  in the List module, keeps `versioningWS => false` (auth cache), and
  is `adminOnly` + `hideTable`. v14 auto-creates TCA for the
  enablecolumns/language fields — we correctly do not redefine them.
- `TCA/Overrides/tt_content.php` uses
  `ExtensionUtility::registerPlugin()` — canonical on v14 (no
  `#[AsPlugin]` attribute exists yet).

### Configuration classes

- `Backend/Modules.php` — v14 schema, now with `workspaces => 'live'`
  after the prior sweep.
- `RequestMiddlewares.php` — identifiers follow the
  `<vendor>/<package>/<name>` convention, `before`/`after` references
  use the Core middleware IDs shipped with v14 core-backend and
  core-frontend.
- `JavaScriptModules.php` — uses the `dependencies` + `imports` map
  that v13 introduced and v14 continues.
- `Icons.php` — `SvgIconProvider` + `source` entries, v14-native.
- `ContentSecurityPolicies.php` — v14 `Map::fromEntries()` + `Mutation`
  objects. No legacy array form.
- `Services.yaml` — `autowire` + `autoconfigure` defaults, plus
  targeted `public: true` for controllers / middlewares / login
  provider invoked via DI containers. Correct for v14 (the
  `#[AsController]` attribute is still only a proposal).

### PSR-14 event listener

- `EventListener/InjectLoginHeadingsListener` uses
  `#[AsEventListener('workos-auth/inject-classic-login-heading')]` —
  v14 attribute form. No listener array in `Services.yaml`.

### Login provider

- Registered through `$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['backend']['loginProviders']`
  in `ext_localconf.php`. This is still the canonical registration
  API on v14 (no YAML-based replacement shipped yet).
- `WorkosBackendLoginProvider::modifyView()` takes the v14
  `TYPO3\CMS\Core\View\ViewInterface` and only reaches into
  `FluidViewAdapter` for template-path injection — the documented v14
  upgrade pattern.

### ext_tables.sql / ext_localconf.php

- `ext_tables.sql` defines `uid`, `pid`, `tstamp`, `crdate`, and the
  six data fields explicitly. v14's SchemaMigrator can derive
  `tstamp`/`crdate` from ctrl, but keeping them explicit is safer for
  non-composer deployments and is not a v14 deprecation.
- `ext_localconf.php` has no hook registrations, no `SC_OPTIONS`
  entries, no `TYPO3_CONF_VARS['SYS']` touches beyond the login
  provider registration.

## No changes proposed

Rector / Fractor would produce empty diffs. The extension is already
shaped for v14 and structured to migrate cleanly to v15 when the time
comes (no inline child tables, no frozen workspaces, PSR-14 attributes
in use).

## Deferred / not applicable

- Rector rule set for TYPO3 v14 — run would be a no-op; skipped to
  avoid noise.
- Dual-version compatibility reference — explicitly out of scope
  (v14-only by user decree).
- Third-party dependency audit — `workos/workos-php` pinned at `^4.32`;
  no major bump pending.
