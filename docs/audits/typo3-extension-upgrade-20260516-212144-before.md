# TYPO3 extension upgrade report - before - 2026-05-16 21:21:44

Scope: upgrade `workos_auth` to TYPO3 14.3-only and release version 14.0.0.

Findings:

- `composer.json` still allowed the full TYPO3 `^14.0` range.
- `ext_emconf.php` still declared extension version `0.26.0` and TYPO3
  `14.0.0-14.99.99`.
- The local TYPO3 API reported TYPO3 `14.3.0` as the active runtime target.
- `phpstan.neon` was still configured at `level: 9`.
- TCA still used removed TYPO3 14 `ctrl.searchFields`.

Planned changes:

- Require TYPO3 `^14.3` in Composer and `14.3.0-14.3.99` in
  `ext_emconf.php`.
- Set extension version to `14.0.0` in both Composer metadata and
  classic extension metadata.
- Update PHPStan to `level: max`.
- Replace removed v14 TCA options with TYPO3 14 APIs.
