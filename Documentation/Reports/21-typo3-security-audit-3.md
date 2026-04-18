# TYPO3 Security Audit — Third Pass

_Skill applied:_ `typo3-security`
_Extension:_ `workos_auth`

## Still green from prior passes

- `PathUtility::sanitizeReturnTo()` rejects protocol-relative and
  backslash-variant open-redirect inputs (`//evil`, `/\\evil`,
  `\\\\evil`, `\\/evil`).
- All state-changing Account, Team, Setup and User-management
  actions validate CSRF via `FrontendCsrfService` or
  `FormProtectionFactory` before calling WorkOS.
- `SecretRedactor::redact()` wraps every `getMessage()` that flows to
  `$this->logger` in `Classes/`. Quick grep:
  `grep 'getMessage' | grep -v SecretRedactor | grep logger` returns
  zero hits.
- State token + CSRF token comparisons use `hash_equals()`.
- `SetCookieService::create()` issues the FE / BE cookie with core
  defaults (Secure, HttpOnly, SameSite configured via TYPO3 core).
  No extra flags are overridden downwards.
- `Configuration/ContentSecurityPolicies.php` stays scoped to
  `Scope::backend()` and only extends Core with WorkOS CDN origins.
- TCA `tx_workosauth_identity` stays `adminOnly` + `hideTable` +
  `rootLevel=-1`; backend modules are `access: admin` +
  `workspaces: 'live'` after the workspaces pass.

## New findings

### F1 — `sanitizeErrorMessage` leaks raw WorkOS text to the URL

`BackendWorkosAuthMiddleware::sanitizeErrorMessage()` and
`LoginController::sanitizeErrorMessage()` both fall through to
`return $message` when no known phrase matches. The sanitised value
is then appended to the redirect as
`?workosAuthError=<message>`. Two concrete problems:

1. **Information disclosure.** WorkOS error bodies occasionally
   include internal identifiers, trace IDs, or transient diagnostic
   strings. These end up in browser history, reverse-proxy access
   logs, and SIEM pipelines for any team that forwards HTTP logs.
2. **User-facing polish.** Raw WorkOS error text in English is
   shown to end-users regardless of backend language. A malformed
   long message also enlarges the URL.

Fix: change the fallback to a stable, translated generic message
(`error.generic`), and keep the specific branches as today. The
detailed original is already logged (via `SecretRedactor::redact`)
so we don't lose debugging value.

### F2 — `UserManagementController::tokenAction` does not verify a form-protection token

`tokenAction` mints a short-lived WorkOS widget token. Today the
only cross-site protection is SameSite=Lax on the backend session
cookie (Core default) plus `security.backend.enforceReferrer`.
`joinAction` and `createOrganizationAction` already validate a
`FormProtectionFactory` token; `tokenAction` should do the same so
the hardening is uniform and survives a future SameSite relaxation
(or a browser that honours SameSite differently on programmatic
`fetch()` calls). The browser already has the token from the page
render — the fix is plumbing.

### F3 — Admin-only check on the widget token endpoint is implicit

`tokenAction`, `joinAction` and `createOrganizationAction` rely
entirely on the module's `'access' => 'admin'` gate in
`Configuration/Backend/Modules.php`. That is correct today, but a
belt-and-braces assertion inside the controller (a single
`BackendUserAuthentication::isAdmin()` check) would catch the case
where the module is ever registered non-admin by a derived site
package. Kept as an explicit assertion instead of depending on
routing-time enforcement.

## Plan

1. Swap the raw `return $message` fallbacks in both middlewares'
   `sanitizeErrorMessage` helpers for `$this->translate('error.generic')`.
   Add an `error.generic` trans-unit to `locallang.xlf` (+ German
   target) if one does not already exist.
2. Plumb the existing `csrfToken` hidden field into the
   `tokenAction` flow: JS module already has the token, server
   validates via `FormProtectionFactory`.
3. Add an explicit admin assertion at the top of all three POST
   actions (`tokenAction`, `joinAction`, `createOrganizationAction`).

## Deferred

- **Rate limiting** on the pre-auth backend endpoints
  (`password-auth`, `magic-auth-send`, `magic-auth-verify`,
  `email-verify`). Core does not ship a rate-limiter middleware
  composable for these routes; a dedicated pass using
  `Symfony\Component\RateLimiter` is the right place for this.
- Origin/Referer enforcement on the token endpoints — Core's
  `security.backend.enforceReferrer` covers it once enabled; no
  additional per-controller code needed.
