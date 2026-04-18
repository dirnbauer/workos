# Docs Audit — Third Pass

_Skill applied:_ `typo3-docs`
_Extension:_ `workos_auth`

## State

- Documentation/ is fully RST, already migrated from Markdown in
  pass 2.
- `guides.xml` advertises release `0.24`.
- `ext_emconf.php` version is `0.24.0`.
- `composer.json` does not carry a version field (correct for
  Composer-installed extensions).
- Changelog tops out at `0.24.0`.

## Gaps after the third-sweep code changes

1. **Changelog is missing a `0.25.0` entry.** The third sweep added:
   - workspaces → `workspaces => 'live'` on the three backend
     modules + intent comment on the identity TCA + functional
     workspace test.
   - conformance → XLIFF parity (two missing keys in German, two
     new German sub-module files), dashboard inline-style
     extraction to dedicated CSS files.
   - TYPO3 security → `error.generic` fallback replaces raw
     WorkOS text in redirect URLs; CSRF + explicit admin guard on
     `UserManagementController::tokenAction` / `joinAction` /
     `createOrganizationAction`.
   - broad security → new `WorkosTeamService::assertMemberOfOrganization`
     + `findInvitation`; authorization gate wired into every Team*
     controller action (invite, resend, revoke, portal).
   - testing → new `XliffParityTest` (5 cases) + functional
     `IdentityServiceWorkspaceTest`.

2. **Version bump.** `ext_emconf.php` and `guides.xml` should move
   to `0.25.0` / `0.25` so the Changelog header matches the
   metadata shipped with the extension.

3. **Configuration.rst's Workspaces section** was updated in the
   workspaces sweep with the live-only modules note. No further
   change needed.

4. **docs.typo3.org readiness.** `guides.xml` already declares the
   `t3coreapi`, `t3tca`, `t3install` inventories, the TYPO3 docs
   theme extension, and the GitHub edit link. Nothing to add.

## Plan

1. Prepend a `0.25.0 — Authorization + Workspaces polish` entry to
   `Documentation/Changelog.rst` covering the five groups above.
2. Bump `ext_emconf.php` version to `0.25.0`.
3. Bump `guides.xml` `release` / `version` to `0.25`.

## Out of scope

- Net-new screenshots. The only UI change is invisible to editors
  (module now hidden in non-LIVE workspace). The existing
  screenshots still represent the live-workspace experience.
- Migration notes — this is a 0.24 → 0.25 patch within a beta
  series; no breaking changes for integrators who followed the
  documented Configuration flow.
