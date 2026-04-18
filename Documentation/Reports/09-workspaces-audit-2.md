# Workspaces Audit Report — Second Pass

_Skill applied:_ `typo3-workspaces`
_Extension:_ `workos_auth`

## Findings

**All prior findings still hold.** No new concerns:

- `Configuration/TCA/tx_workosauth_identity.php` keeps
  `versioningWS=false`, `adminOnly=true`, `hideTable=true`.
- `IdentityService` writes via `Connection::insert/update` (bypasses
  DataHandler — intentional for an auth cache).
- Auth lookups against `fe_users` and `be_users` keep
  `removeAll()` restrictions — correct for authentication.
- The extension owns no FAL references / file collections — the
  workspace file limitation does not apply here.
- No inline child tables — the v14 deprecation for missing
  `versioningWS` on IRRE children does not apply.

## Planned change

Add a simple unit test that loads the TCA file and asserts
`versioningWS` stays `false`. That turns the current informal contract
into a regression guard (so a well-meaning future refactor can't
accidentally set `versioningWS=true` on an auth table).

## Deferred

- Functional test with an actual workspace (needs a full DB
  bootstrap — covered by the testing-skill backlog).
