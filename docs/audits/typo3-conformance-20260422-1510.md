# TYPO3 Conformance Audit

- Date: 2026-04-22 15:10 Europe/Vienna
- Requested rule: `.cursor/rules/typo3-conformance.mdc` (not present in this workspace)
- Method: repository inspection plus TYPO3/package primary sources

## Result

Clean after fixes.

## Findings addressed

1. The repository did not use TYPO3's official coding-standards package.
2. `composer ci` did not exist.
3. The README quality section still described the previous PHPStan/tooling state.

## Actions taken

- Added `typo3/coding-standards ^0.8`.
- Generated `.php-cs-fixer.dist.php` for an extension repository.
- Added `composer cs:check`, `composer cs:fix`, and `composer ci`.
- Updated the GitHub Actions static-analysis job to run TYPO3 coding standards.
- Refreshed README and changelog quality notes.
