# Frontend Login Handoff Security Analysis

- Date: 2026-04-24 Europe/Vienna
- Scope: frontend email-code final step and WorkOS/TYPO3 login-token handoff
- Method: targeted auth-flow review, TYPO3 core request-token comparison,
  WorkOS pending-token state review, dependency audit, focused regression tests

## Result

No new unauthenticated session-bypass or CSRF bypass path was found after
the current fixes.

Both problems were availability/auth-completion defects in the final
handoff, not evidence that an attacker could create a TYPO3 session without
first completing WorkOS authentication and local user resolution.

## Problem 1: TYPO3 rejected the final frontend login

### Failure mode

The frontend plugin POST used the extension-scoped request token
`workos/frontend/login`. That token is correct for the public plugin form and
is validated before calling WorkOS.

After WorkOS accepted the password or magic-auth code, the extension created
an internal pending-login request for TYPO3's own auth service. TYPO3 v14
then checked for its core active-login token scope:

- `core/user-auth/fe` for frontend sessions
- `core/user-auth/be` for backend sessions

When the validated plugin token was still present, TYPO3 did not treat it as
the core login token and disabled active login processing.

### Security properties after the fix

- The token swap only runs when the request has the server-created
  `workos_auth.pending_login` attribute.
- The pending-login context must match the TYPO3 auth object (`frontend`
  for `FE`, `backend` for `BE`).
- A request-token state already marked invalid by TYPO3 remains invalid.
- The listener does not trust user-submitted parameters for the local user
  row. The local user row is attached by `Typo3SessionService` only after
  WorkOS authentication and `UserProvisioningService` mapping succeeded.

### Threats considered

| Threat | Outcome |
| --- | --- |
| Login CSRF via public frontend form | Still requires a valid plugin token before WorkOS is called. |
| Forged request attribute | Not attacker-controlled through HTTP; attributes are server-side PSR-7 state. |
| Mismatched FE/BE context | Rejected by the listener before issuing a core token. |
| Invalid signed token upgraded to valid core token | Rejected; `false` token state is preserved. |
| Session fixation | TYPO3 still creates the FE/BE session through its native authentication lifecycle. |

## Problem 2: magic-auth code could require a second email-verification step

### Failure mode

WorkOS can return `email_verification_required` after a valid magic-auth
code. The frontend password flow already handled that typed exception by
storing the pending email-verification token in the TYPO3 frontend session
and redirecting to the verification screen.

The frontend magic-auth verify flow handled the exception as a generic
failure, so the user returned to the login form.

### Security properties after the fix

- The magic-auth session is cleared before the email-verification session is
  started, so the user cannot accidentally reuse stale magic-auth state.
- The WorkOS `pending_authentication_token` stays in
  `workos_email_verification` frontend session data.
- The token is not placed in a query string, redirect URL, or log message.
- The email-verification submit and resend forms keep the existing
  `workos/frontend/login` request-token requirement.
- `returnTo` remains the value sanitized at the first step; it is not
  re-read from the final verification POST.

### Threats considered

| Threat | Outcome |
| --- | --- |
| Pending token leakage through URL/history/access logs | Not exposed; stored in the FE session. |
| Reusing old magic-auth state after email verification starts | Cleared before the new pending verification state is saved. |
| CSRF on final verification submit | Existing request-token validation still runs. |
| Open redirect after verification | `returnTo` was sanitized before being stored in session state. |
| Raw WorkOS error leakage | Errors continue through `SecretRedactor` and translated generic fallbacks. |

## Residual risk

- Frontend pending email-verification data is protected by the TYPO3 frontend
  session. A compromised browser session can complete any pending auth flow
  for that browser, which is expected for session-bound authentication.
- If another extension deliberately adds the internal pending-login request
  attribute, it can participate in the trusted server-side auth handoff.
  That is equivalent to trusted TYPO3 extension code and not exposed to
  remote users through HTTP parameters.
- `gitleaks`, `semgrep`, and `trivy` were not installed in this workspace,
  so this pass did not include those optional scanners.

## Verification

- `composer audit --locked`: no security vulnerability advisories found.
- `composer test:unit`: 97 tests, 199 assertions, 1 skipped.
- `composer cs:check`: clean.
- `composer phpstan`: clean.
- `composer ci`: clean.
- `composer test:functional`: command exited successfully in this local
  setup, but PHPUnit printed no functional-test detail.
