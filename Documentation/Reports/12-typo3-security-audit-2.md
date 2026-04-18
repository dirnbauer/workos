# TYPO3 Security Audit — Second Pass

_Skill applied:_ `typo3-security`
_Extension:_ `workos_auth`

## Spot checks

All four targeted checks passed.

- **CSRF on every state-changing Action.** Every `updateProfile`,
  `changePassword`, `startMfaEnrollment`, `verifyMfaEnrollment`,
  `cancelMfaEnrollment`, `deleteFactor`, `revokeSession`, `invite`,
  `resendInvitation`, `revokeInvitation`, `launchPortal` verifies
  `csrfToken` via `FrontendCsrfService` before calling WorkOS. Backend
  `SetupAssistantController::save`, `UserManagementController::join`,
  and `createOrganization` all still go through
  `FormProtectionFactory` + `isValidToken()`.
- **All exception logging goes through `SecretRedactor`.** Grep for
  `logger?->*($e->getMessage())` with no `SecretRedactor` wrapping
  returns zero hits.
- **HMAC comparisons use `hash_equals`.** Two sites:
  `StateService::consume()` (state token) and
  `FrontendCsrfService::validate()` (CSRF token). Both call
  `hash_equals()`, giving constant-time comparison.
- **`SetupAssistantController` CSRF flow intact.** `save()` now
  narrows `$payload['csrfToken']` via `is_string()` before passing to
  `isValidToken()`, so a crafted non-string payload can no longer
  reach the form-protection validator.

## No changes required

The extension did not lose any prior hardening during the level-max
uplift.
