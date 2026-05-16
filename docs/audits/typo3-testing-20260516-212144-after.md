# TYPO3 testing report - after - 2026-05-16 21:21:44

Changes applied:

- PHPStan now runs at `level: max`.
- CI static-analysis wording now says `level max + phpat`.
- Max-level findings were fixed with real type normalization and
  `#[Override]` attributes.

Verification:

- `Build/Scripts/runTests.sh -s phpstan` passes.
- `Build/Scripts/runTests.sh -s cs` passes.
- Unit and functional suites are run as final verification before push.
