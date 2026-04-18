# Workspaces Audit Report — Third Pass

_Skill applied:_ `typo3-workspaces`
_Extension:_ `workos_auth`
_TYPO3 target:_ v14.2 (v14-only)

## Scope of this pass

Passes 1 and 2 locked down the identity table and documented the
live-only contract. This pass revisits the extension against the
v14-specific items in the skill (sidebar selector, `workspaces` module
key, inline-child deprecation, auto-publish) and closes the two items
both prior passes deferred.

## Findings still standing

- `Configuration/TCA/tx_workosauth_identity.php` keeps
  `versioningWS => false`, `adminOnly => true`, `hideTable => true`,
  `rootLevel => -1`. The identity table is explicitly out of scope for
  versioning.
- `Classes/Service/IdentityService.php` writes via
  `Connection::insert` / `Connection::update` (bypasses DataHandler) and
  reads via `QueryBuilder` with `->getRestrictions()->removeAll()` — the
  correct pattern for an auth cache.
- `Classes/Service/UserProvisioningService.php` resolves fe_users /
  be_users with `->removeAll()` + explicit `disable=0` / `deleted=0`.
  Core does not ship `versioningWS = true` for those tables.
- No FAL references, no file collections, no inline child tables — the
  v14 deprecations around inline-workspace (#106821) and the folder-based
  collection gap in section 2a of the skill do not apply.

## New findings on this pass

### F1 — Backend modules do not declare `workspaces`

`Configuration/Backend/Modules.php` registers `workos`, `workos_users`
and `workos_setup` without the `workspaces` key. With the v14.2 sidebar
workspace selector, these modules will appear for admins inside custom
workspaces too — yet they operate on TCA-external state (extension
config, WorkOS API) and on the live-only identity table. A stray click
while previewing a workspace can still mint widget tokens / create
WorkOS orgs, and the UI implies the action is workspace-scoped.

Fix: set `'workspaces' => 'live'` on all three module entries so they
only appear in the LIVE workspace. This matches the `workspaces => '*'
| 'live' | 'offline'` contract in section 5 of the skill.

### F2 — TCA ctrl carries versioningWS=false but no intent comment

The flag is correct, but a future contributor reading the TCA has no
way to know the intent is "this is an auth mapping, do NOT version".
Add a short ctrl-level comment so the invariant is self-documenting.

### F3 — No regression guard for the versioningWS=false invariant

A third-party TCA override in a site package could flip
`versioningWS` on our table. We have no test that fails when that
happens. Pass 2 proposed this as a "planned change" but it was never
landed.

Fix: add a functional test that boots the TCA and asserts
`$GLOBALS['TCA']['tx_workosauth_identity']['ctrl']['versioningWS']`
is exactly `false`. Keep it in the existing
`Tests/Functional/Service/` directory to avoid a new suite.

### F4 — Documentation does not explain the workspace contract

`Documentation/Configuration.rst` does not mention workspaces at all.
An admin integrating WorkOS into a workspace-enabled TYPO3 install has
no way to know whether login works under preview, whether identity
records get versioned, or whether the modules are safe to use while a
workspace is active.

Fix: add a short "Workspaces" subsection under Configuration with the
three guarantees (identity is live-only, login runs live regardless of
workspace, admin modules appear in LIVE only).

## Planned changes (this sweep)

1. `Configuration/Backend/Modules.php` — add `'workspaces' => 'live'`
   to every top-level and sub-module entry.
2. `Configuration/TCA/tx_workosauth_identity.php` — add a short
   `@see` / contract comment above the `ctrl` array.
3. `Tests/Functional/Configuration/WorkspaceInvariantTest.php` — new
   functional test asserting `versioningWS` stays `false`.
4. `Documentation/Configuration.rst` — add a "Workspaces" subsection
   (picked up by the `typo3-docs` skill pass if RST tightening is
   needed).

## Out of scope / deferred

- No changes to `IdentityService` / `UserProvisioningService` queries —
  they are already workspace-safe for an auth extension.
- Auto-publish / Scheduler integration — this extension does not expose
  editorial content, so none of section 3's Scheduler setup applies.
