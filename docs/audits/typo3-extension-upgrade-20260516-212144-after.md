# TYPO3 extension upgrade report - after - 2026-05-16 21:21:44

Changes applied:

- `composer.json` now declares TYPO3 Composer metadata version `14.0.0`
  and TYPO3 `^14.3` requirements.
- `ext_emconf.php` now declares version `14.0.0` and TYPO3
  `14.3.0-14.3.99`.
- Dependency install was refreshed to TYPO3 14.3.1 packages.
- `phpstan.neon` now runs at `level: max`.
- Removed `ctrl.searchFields` from the identity TCA and enabled search
  directly on searchable fields.

Verification:

- `Build/Scripts/runTests.sh -s phpstan` passes with no errors.
- `Build/Scripts/runTests.sh -s cs` passes.
