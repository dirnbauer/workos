# Workspaces Audit Report

_Skill applied:_ `typo3-workspaces`
_TYPO3 target:_ v14.x
_Extension:_ `workos_auth`

## Summary

The extension is **workspace-neutral** by design. It exposes no editable
content tables and its single infrastructure table
(`tx_workosauth_identity`) is an identity cache that must remain
live-only. The frontend middleware and Extbase plugins are safe under
workspace preview because they do not query workspace-versioned records
for authentication decisions.

## Findings

### F1 — Identity table must never be workspace-versioned (confirmed)

`tx_workosauth_identity` maps WorkOS user IDs to local `fe_users` /
`be_users`. Fields are transactional (no editor workflow, no staging).
It currently has **no TCA** — so workspace versioning is never
applied — but that is implicit. We will add a minimal TCA that is
`adminOnly`, `hideTable`, and explicitly sets
`versioningWS => false` as a defensive declaration.

- `Classes/Service/IdentityService.php` writes directly through
  `Connection::insert`/`Connection::update` (not DataHandler). Select
  queries already call `->getRestrictions()->removeAll()` so workspace
  overlays would not apply even if enabled.
- Storage `pid` is hard-coded to `0`.

### F2 — `fe_users` / `be_users` authentication lookups are intentionally live-only

`Classes/Service/UserProvisioningService.php` looks up users by uid,
email and username with `->getRestrictions()->removeAll()` and explicit
`disable = 0` / `deleted = 0` constraints. This is **correct** for an
auth extension: a backend user previewing in a workspace must still be
resolved against the live identity row; workspace-versioned user
records would break login. No change required.

### F3 — Frontend middleware is route-scoped and workspace-safe

`Classes/Middleware/FrontendWorkosAuthMiddleware.php` matches on the
configured login/callback/logout paths and returns the live handler
otherwise. Workspace preview never hits these routes. No change
required.

### F4 — Backend login provider runs outside workspace context

`Classes/LoginProvider/WorkosBackendLoginProvider.php` runs during the
backend login form render, which is workspace-independent (user not yet
authenticated, no workspace selected). No change required.

### F5 — Extbase plugins do not query arbitrary content tables

`LoginController`, `AccountController`, `TeamController` only talk to
the WorkOS SDK and the identity table. They do not iterate
`tt_content`, `pages`, or other workspace-versioned tables. No change
required.

### F6 — Missing TCA → future-proofing risk

Even though no TCA currently exists, a third-party extension could
inject a TCA override and accidentally enable `versioningWS` on the
identity table. We will lock this down with an explicit TCA that
marks the table as system-only.

## Planned changes

1. Add `Configuration/TCA/tx_workosauth_identity.php` with:
   - `adminOnly => true`
   - `hideTable => true`
   - `versioningWS => false`
   - `rootLevel => -1` (allowed anywhere, including pid 0)
   - minimal `columns` for the six real data fields so the record can
     be inspected in the List module by admins
2. Add a short "Workspaces" section to `Documentation/Configuration.md`
   documenting the live-only identity store.

## Deferred to later skills

- Functional test coverage for workspace-safe auth lookups
  (`typo3-testing` pass).
- Verifying no CSP / Content-Element regressions under workspace
  preview (`typo3-conformance` pass).
