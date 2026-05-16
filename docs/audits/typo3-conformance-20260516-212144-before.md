# TYPO3 conformance report - before - 2026-05-16 21:21:44

Scope: TYPO3 14.3 conformance cleanup.

Findings:

- Version metadata did not match the requested 14.0.0 extension release.
- Composer accepted all TYPO3 14 minors instead of the 14.3 LTS target.
- PHPStan level was not max.
- TYPO3 14 removed TCA `ctrl.searchFields`, but the identity table still
  used it.
- PHP 8.3 `#[Override]` checks were not enforced.

Planned changes:

- Align Composer and `ext_emconf.php` with TYPO3 14.3-only metadata.
- Enable PHPStan `level: max` and missing override checks.
- Replace removed TCA with field-level searchable config.
