# Security Audit Report (Broader OWASP/CWE Pass)

_Skill applied:_ `security-audit`
_TYPO3 target:_ v14.x
_Extension:_ `workos_auth`

## Clean checks

- `composer audit` — no advisories on any direct or transitive
  dependency (typo3/*, workos/workos-php 4.32.0, phpstan 2.1.x,
  phpunit 11.5.x).
- No `unserialize()` anywhere in `Classes/`.
- No XML parsing (no `DOMDocument`, `simplexml_load_*`, `libxml_*`).
- No hard-coded secrets: grep for `api[_-]?key|secret|password` with
  ≥16-char string literal returns nothing.
- No raw `$password` logging.
- No `mt_rand` / `rand` usage for security-sensitive values; tokens go
  through `HashService::hmac()` or `random_bytes()`.
- WorkOS SDK base URL is pinned to `https://api.workos.com`; no
  host-injection or SSRF surface.
- CSP scope correctly limited to backend.
- Extbase forms use `<f:form>` (protects against parameter-mapping
  abuse); CSRF tokens now verify state-changing requests.

## New findings

### SA-01 (Medium) — Raw `$exception->getMessage()` rendered to callers

Three call sites return the raw exception message in a user-facing
response:

- `BackendWorkosAuthMiddleware::handleLogin()` line 121:
  `return $this->errorResponse($exception->getMessage(), 500);`
- `BackendWorkosAuthMiddleware::handleCallback()` line 152:
  `$response->withAddedHeader('X-WorkOS-Auth-Error', $exception->getMessage())`
- `FrontendWorkosAuthMiddleware::handleLogin()` / `handleCallback()`
  lines 97 and 113: same pattern for the frontend flow.

Exception messages from the WorkOS SDK can contain internal
identifiers and error bodies that are useful to an attacker (e.g. a
WorkOS `client_id`, organization id, internal URL). Also, our own
`\RuntimeException` messages include file-path-like hints (see
`UserProvisioningService.php:88`: _"Automatic frontend provisioning
requires a storage PID."_ — fine; but other messages leak
implementation details).

**Fix:** route all three through `sanitizeErrorMessage()` or a generic
translated string, and drop the `X-WorkOS-Auth-Error` header (it was
a debug aid).

### SA-02 (Low) — WorkOS error text logged at `error` level

Multiple `logger?->error('... ' . $e->getMessage())` calls in
controllers and middlewares. Log messages currently go through the
TYPO3 Log API, which is fine for severity, but if the WorkOS SDK ever
echoes the API key back in an error body (it shouldn't, but _in
theory_) the secret would land in `var/log/`.

**Fix:** add a single `redactSecrets()` helper that strips any
substring matching `/sk_live_[A-Za-z0-9]+/` before logging. Ship it
even if the concrete leak path is unlikely — defensive, cheap.

### SA-03 (Info) — Rate limiting

Flagged already by the earlier typo3-security audit. No change here;
decision remains "deploy-time concern, not extension code".

## Planned changes (this pass)

1. Middleware `errorResponse()` and the callback header no longer
   include raw exception messages. The response body uses a
   translated generic message; the original message keeps flowing
   into the log with any `sk_*` tokens redacted.
2. New `SecretRedactor` helper used by every controller / middleware
   that calls `$this->logger?->{warning|error}()` with an exception.

## Deferred

- CI secret scanning (gitleaks) — belongs in the enterprise-readiness
  pass.
- WAF / rate limiting — deployment concern.
