# Testing Audit — Second Pass

_Skill applied:_ `typo3-testing`
_Extension:_ `workos_auth`

## Priority additions (this pass)

1. **`PathUtility` coverage uplift.** Today we only test
   `normalizePath`, `joinBaseAndPath`, `appendQueryParameters`, and
   `sanitizeReturnTo`. `joinBaseUrlAndPath`, `getPathRelativeToSiteBase`,
   `guessBackendBasePath`, `guessBasePathFromMatchedPath`, and
   `buildAbsoluteUrlFromRequest` are all pure static helpers — cheap
   to cover, each used in the auth flow.
2. **`MixedCaster`.** The new helper underpins most of the
   level-max cleanup. Worth locking its behaviour in with tests:
   numeric-string → int, bool → int, non-scalars → default, float
   round-down.

## Deferred to a future functional pass

- **`WorkosAuthenticationService` state-token round-trip.** Requires
  mocking the WorkOS SDK; value-vs-effort is lower than bootstrapping
  `typo3/testing-framework` once for the real-DB flow. Defer to
  functional.
- **`Typo3SessionService` cookie logic.** Constructs `Cookie` objects
  with real signing — best exercised in a functional test that boots
  a request.
- **phpat architecture rules.** Worth adding, but deserves its own
  small pass (add the dependency, write ~4 rules).

## Out of scope

- Functional / E2E tests — still scaffolded, still deferred.
- Mutation testing.
