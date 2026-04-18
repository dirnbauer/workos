# Broader Security Audit — Second Pass

_Skill applied:_ `security-audit`
_Extension:_ `workos_auth`

## Results

- `composer audit`: no advisories.
- **MixedCaster**: uses `is_*()` type checks before casting. No type
  juggling via `==` or loose comparison. `is_numeric($value)` + cast
  to `int` is tight. No user input flows through it into SQL or
  shell — it only narrows values that are then passed to further
  typed code paths. Safe.
- **runTests.sh**: no user input interpolated into shell; `-s`
  value is matched against a hard-coded `case` whitelist; unknown
  values bail out. `set -euo pipefail` catches any missed errors.
  No quoting risk.
- **CSRF TOCTOU**: `FrontendCsrfService::issue()` and `validate()`
  both derive the secret from the same `$user->getSession()
  ->getIdentifier()` call, which is a read of in-memory session
  state. No file-system or external lookup that could race. The
  session identifier does not rotate mid-request, so a token issued
  during the dashboard render always matches on the follow-up POST.
  No TOCTOU window.

No changes required.
