# TYPO3 security report - before - 2026-05-16 21:21:44

Scope: TYPO3 14 security hardening review.

Findings:

- No new unsafe raw request superglobal usage was found in extension code.
- Authentication/session flows already used request tokens and TYPO3
  authentication APIs.
- Removed TYPO3 14 TCA options were still present in the identity table,
  which blocks clean v14 conformance.
- MCP JWT claims were returned from decoded external data without
  normalizing array keys.

Planned changes:

- Keep using TYPO3 request/authentication APIs.
- Normalize external token and schema API data before exposing typed
  extension service responses.
- Remove deleted TCA options.
