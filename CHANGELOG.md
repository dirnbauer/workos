# Changelog

The full, versioned release history is [Documentation/Changelog.rst](Documentation/Changelog.rst).

## Unreleased

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
