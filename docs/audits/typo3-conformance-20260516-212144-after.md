# TYPO3 conformance report - after - 2026-05-16 21:21:44

Changes applied:

- Extension metadata is now release `14.0.0`.
- TYPO3 constraints are now 14.3-only.
- PHPStan runs at `level: max` with missing override checks enabled.
- All max-level override findings were fixed with `#[Override]`.
- The identity table TCA no longer uses removed `ctrl.searchFields`.

Verification:

- TYPO3 API inspection confirmed a TYPO3 14.3 runtime.
- PHPStan level max passes.
- Coding standards check passes.
