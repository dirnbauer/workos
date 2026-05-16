# TYPO3 security report - after - 2026-05-16 21:21:44

Changes applied:

- MCP JWT claims are normalized to `array<string, mixed>` after decoding.
- MCP JSON-RPC tool parameters are normalized to string-keyed arrays before
  dispatch.
- TYPO3 schema migrator errors/statements are normalized before returning
  from `ExtensionSchemaService`.
- Removed TCA `ctrl.searchFields` was replaced with field-level search
  configuration.

Verification:

- PHPStan level max passes, including typed external data boundaries.
