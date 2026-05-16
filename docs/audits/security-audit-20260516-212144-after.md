# Security audit report - after - 2026-05-16 21:21:44

Changes applied:

- External JWT claims, JSON-RPC params, and schema migrator responses now
  pass through string-key/list normalization before use.
- Version constraints now pin the extension to TYPO3 14.3 LTS.

Verification:

- PHPStan level max passes.
- Coding standards pass.
- `composer audit` reports no advisories.
- `security-audit.sh` exits successfully with 0 errors. It reports 2
  warnings: false-positive matches for method names containing `assert`, and
  no project-level security-header configuration, which is expected for this
  extension package.
