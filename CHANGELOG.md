# Changelog

The full, versioned release history is [Documentation/Changelog.rst](Documentation/Changelog.rst).

## Unreleased

## 14.0.0 - 2026-05-16

### Changed

- Raised the extension release to `14.0.0` in TYPO3's Composer metadata
  and `ext_emconf.php`.
- Added TYPO3 14.3 classic-mode Composer metadata
  (`extra.typo3/cms.version` and `Package.providesPackages`) while
  keeping `ext_emconf.php` for TER/tooling compatibility.
- Dropped broad TYPO3 14 minor compatibility and now require TYPO3 `^14.3`
  / `14.3.0-14.3.99` only.
- Updated the lock-file install to TYPO3 14.3.1 and current PHPStan 2.x
  tooling while keeping PHP 8.2 as the minimum runtime.
- PHPStan now runs at `level: max` with `saschaegerer/phpstan-typo3` 3.0.1.

### Fixed

- Replaced removed TYPO3 14 TCA `ctrl.searchFields` usage with field-level
  searchable configuration.
- Added PHP 8.3 `#[Override]` attributes required by max-level analysis.
- Normalized external API arrays before returning them from MCP/schema
  services.

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
