# Security audit report - before - 2026-05-16 21:21:44

Scope: broader security review for the TYPO3 14.3-only cleanup.

Findings:

- No `eval`, shell execution, deserialization, direct `$_GET`/`$_POST`, or
  raw SQL string concatenation findings were found in active PHP sources.
- Generated WorkOS widget bundles were excluded from source-code security
  grep conclusions.
- `f:format.raw` remains limited to a translated backend login heading.
- External JWT/JSON/API boundaries needed stricter array normalization for
  max-level analysis and clearer trust boundaries.

Planned changes:

- Normalize external API arrays.
- Re-run dependency audit through Composer/GitHub Actions after push.
