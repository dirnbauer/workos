# Broader Security Audit — Third Pass

_Skill applied:_ `security-audit`
_Extension:_ `workos_auth`
_Perspective:_ OWASP Top 10 (2021), CWE Top 25 (2025)

## Baseline still green

- `composer audit`: no advisories on the declared graph.
- Crypto: `hash_equals` on every token comparison, Argon2id for
  throwaway password hashes, `random_bytes` for randomness, no
  `mt_rand`/`rand`/`uniqid`/`md5()`/`sha1()` used as a security
  primitive. `sha1($workosUserId)` in `generateUniqueUsername()` is
  non-security username derivation (collisions fall back to
  `_1`, `_2`, ... — safe).
- No `unserialize`, no `eval`, no shell execution, no
  `file_get_contents` / `curl` on user-controlled URLs. All outbound
  calls go through the WorkOS SDK to the fixed API base URL.
- SecretRedactor patterns cover WorkOS live/test keys, client IDs,
  Bearer tokens and JWT-shaped strings; `redact()` is called on
  every exception message before `$this->logger`.
- Fluid auto-escapes; the single `<f:format.raw>` wraps a
  `<f:translate>` of a vendor-controlled string. Safe.
- CSP scoped to backend, extends (not replaces) core policy,
  `'unsafe-inline'` only for style-src (unavoidable with core).

## New finding (High)

### F1 — Authorization gap in Team* actions (CWE-285, OWASP A01)

`Classes/Controller/Frontend/TeamController.php` accepts an
`organizationId` (or `invitationId` that resolves to one) from the
POST body on:

- `inviteAction`
- `resendInvitationAction`
- `revokeInvitationAction`
- `launchPortalAction`

All four delegate to `WorkosTeamService`, which calls the WorkOS
SDK **with an app-scoped API key**. The SDK has no notion of the
current frontend user — it will happily send invitations, revoke
them, or mint an Admin Portal link for any org the API key can
reach.

Proof:

```php
public function inviteAction(): ResponseInterface {
    $context = $this->resolveContext();        // only checks the user is logged in
    // …
    $organizationId = $body->trimmedString('organizationId');  // attacker-supplied
    // …
    $this->teamService->sendInvitation(
        email: $email,
        organizationId: $organizationId,      // any org in the WorkOS tenant
        inviterUserId: $context['workosUserId'],
        …
    );
}
```

`launchPortalAction` is the worst case: the returned WorkOS Admin
Portal link is a bearer-style URL that grants full admin UI access
to the target organization. A logged-in frontend user could mint
portal links for organizations they are not a member of.

**Severity:** High. Exploitation requires:
- a valid frontend WorkOS session (low bar — `fe_users` + AuthKit),
- knowledge or enumeration of an organization UID (structured
  `org_...`) — not hard in a multi-tenant deployment.

**CVSS v3.1 preliminary:**
`AV:N/AC:L/PR:L/UI:N/S:C/C:H/I:H/A:L` → 9.0 (Critical). The
`S:C` (scope change) reflects that the tenant boundary is crossed.

### Fix plan

1. Add `WorkosTeamService::assertMemberOfOrganization(
      string $workosUserId, string $organizationId): void` — uses
   `listOrganizationMemberships(userId, organizationId, ['active'])`
   and throws a typed `\RuntimeException('forbidden_organization')`
   when the result is empty.
2. For actions that only have an `invitationId`, first call
   `getInvitation($invitationId)` to resolve the owning org, then
   assert membership.
3. Wire the assertion into every Team* action before calling
   WorkOS. Keep the WorkOS-level error path for edge cases where
   the org was revoked between list and action.
4. New unit/functional test: craft a request that lies about
   `organizationId` and assert the flow short-circuits with the
   translated `team.flash.forbidden` message.

## Other observations (no action required)

- **Session fixation:** Core TYPO3 rotates the session ID on
  successful login via `UserSessionManager::createAnonymousSession`
  + `->setSession(…)`; we do not override that in
  `Typo3SessionService`. The WorkOS callback therefore does not
  create a fixation window.
- **Cookie flags:** `SetCookieService::create()` uses core defaults
  (Secure when `requestIsSecure()`, HttpOnly, SameSite=Lax).
  Nothing overridden.
- **TLS:** Enforced at the reverse proxy / HSTS — outside the
  extension. The backend login flow explicitly builds
  `absolute URL from request` and WorkOS rejects non-HTTPS redirect
  URIs in production clients, so the callback cannot be downgraded.
- **MFA:** TOTP factor enrolment and verification are delegated to
  WorkOS. We never store shared secrets; we store only the
  user-scoped factor id in a `fe_typo_user` session scope, which
  expires with the session.

## Plan (this sweep)

1. `WorkosTeamService::assertMemberOfOrganization()` helper.
2. `WorkosTeamService::getInvitation()` thin wrapper (returns the
   org id, so the controller can assert membership before
   resending/revoking).
3. Apply the assertion in the four Team* actions.
4. New trans-unit `team.flash.forbidden` (EN + DE) for the flash.
5. Unit test for `assertMemberOfOrganization()` using a mocked
   `WorkosTeamService`.
