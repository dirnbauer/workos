# Security Audit Report

_Skill applied:_ `typo3-security`
_TYPO3 target:_ v14.x
_Extension:_ `workos_auth`

## Summary

Auth-critical extension. PHPStan level 9 clean. Most cryptographic
primitives are used correctly (HMAC with `hash_equals()`, Argon2-
backed TYPO3 password hashing, HTTPS redirect URIs). Two meaningful
findings and a handful of low-severity suggestions.

## Findings

### S-01 (High) — Open-redirect in `PathUtility::sanitizeReturnTo()`

`sanitizeReturnTo()` accepts any candidate that "starts with `/`" as a
safe path:

```php
if (str_starts_with($candidate, '/')) {
    return $candidate;
}
```

But protocol-relative URLs like `//evil.example/path` also start with
`/`. Browsers follow `Location: //evil.example/path` as
`https://evil.example/path`. Any caller that uses `returnTo` as a
`Location` header (the frontend and backend middlewares both do) leaks
users to attacker-controlled hosts after a successful WorkOS login.

`\\evil.example` is the same bug via backslash (some browsers / proxies
normalise it).

**Fix:** reject any candidate whose first two characters are `//`,
`/\`, `\/` or `\\` before the "starts with `/`" branch. A path must
start with a single `/` followed by a non-slash, non-backslash
character.

### S-02 (Medium) — Frontend plugin actions lack CSRF tokens

`AccountController` (`updateProfile`, `changePassword`,
`startMfaEnrollment`, `verifyMfaEnrollment`, `cancelMfaEnrollment`,
`deleteFactor`, `revokeSession`) and `TeamController` (`invite`,
`resendInvitation`, `revokeInvitation`, `launchPortal`) accept POST
via `$this->request->getParsedBody()`. The Fluid `<f:form>` only adds
Extbase's property-mapping hash, which is bypassed because we don't
use property mapping. An attacker-controlled page can POST to these
endpoints using the victim's `fe_typo_user` cookie and e.g. revoke
their MFA or change their WorkOS password (WorkOS API re-prompts for
password so the attack vector is narrower, but MFA revocation and
session revocation land without re-auth).

`BackendWorkosAuthMiddleware` POST endpoints (`password-auth`,
`magic-auth-send`, ...) are pre-login so CSRF impact is low (at most
account-enumeration / email spam).

**Fix:** stamp an HMAC-signed CSRF token into a session value when the
dashboard is rendered and verify it in every state-changing action.
Reuse the existing `StateService` pattern.

### S-03 (Low) — Provider allowlist duplicated in two places

`['GoogleOAuth','MicrosoftOAuth','GitHubOAuth','AppleOAuth']` is
hard-coded in `FrontendWorkosAuthMiddleware`, `BackendWorkosAuthMiddleware`
and `WorkosBackendLoginProvider`. Not a vulnerability — but a
regression here (e.g. someone adds `evil-provider`) would bypass the
expectation in the other copies.

**Fix:** move the allowlist to a single constant, e.g.
`WorkosConfiguration::SUPPORTED_SOCIAL_PROVIDERS`.

### S-04 (Info) — `cookiePassword` handling

`cookiePassword` is loaded from `config/system/settings.php` via
`ExtensionConfiguration`. That is the correct place. The validation
rule that it must be ≥ 32 characters is enforced by
`validate()`. No change needed, but README should note: never commit
this value, and the setup assistant should show it
obscured (it already does, per `SetupAssistantController::generateCookiePassword()`).

### S-05 (Info) — CSP correct

`Configuration/ContentSecurityPolicies.php` correctly scopes itself to
`Scope::backend()`, extends (not replaces) the Core policy, only
relaxes `StyleSrc` `'unsafe-inline'` plus WorkOS CDN / API for
`connect-src` / `img-src` / `font-src`. No `'unsafe-eval'` or wildcard
hosts. No frontend CSP mutation because the frontend plugins do not
need extra origins.

### S-06 (Info) — State token is sound

`StateService::issue()` uses HMAC-SHA256 via TYPO3's `HashService`,
TTL of 600 seconds, `hash_equals()` for constant-time comparison.
`base64_decode(..., true)` is now strict. JSON is decoded with
`JSON_THROW_ON_ERROR`. No change needed.

### S-07 (Info) — Email-verification flow cannot be bypassed

`WorkosAuthenticationService::rethrowEmailVerificationException()`
only converts the exception when WorkOS's error body contains
`pending_authentication_token`. `authenticateWithEmailVerification()`
takes the pending token from session data and submits it to WorkOS;
WorkOS enforces the one-time code. We never mark a user verified
client-side. No change needed.

## Planned code changes (this pass)

1. `PathUtility::sanitizeReturnTo()` rejects protocol-relative /
   backslash-variant URLs.
2. New `StateService::issueCsrfToken()` / `verifyCsrfToken()` pair
   (or reuse `issue()`/`consume()`) and plumbed into the Account and
   Team controllers.
3. Shared `WorkosConfiguration::SUPPORTED_SOCIAL_PROVIDERS` constant
   replacing the three duplicates.
4. Regression tests for each of the three.

## Deferred

- Rate limiting on pre-auth endpoints (Core has no built-in; would
  need e.g. in-memory fail2ban-style counters — out of scope here).
- Origin/Referer header checks (belt-and-braces; CSRF tokens are the
  canonical fix).
