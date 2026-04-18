# Documentation Audit Report

_Skill applied:_ `typo3-docs`
_TYPO3 target:_ v14.x
_Extension:_ `workos_auth`

## Scope of this pass

Docs stay in the existing Markdown layout (they are not RST; we are
not publishing to `docs.typo3.org` at this time). The goal is to make
README and `Documentation/*` reflect the work we did in the workspaces,
upgrade, conformance, security, and testing passes.

## Changes

1. **README.md**
   - Bump the stated version reference where it talks about
     `EXTENSIONS.workos_auth` / composer install to mention 0.23.0.
   - Add a _Quality_ section that calls out PHPStan level 9,
     43 unit tests, the `composer phpstan|test:unit|test:functional`
     scripts.
   - Add a _Security_ section linking to the audit reports under
     `Documentation/Reports/` and summarising: CSRF tokens on Account
     & Team plugins, open-redirect hardening in `sanitizeReturnTo`,
     secret redaction in logs.
   - Note explicitly: workspace-safe (identity table is admin-only,
     `versioningWS=false`).
   - Add a link to `Documentation/Changelog.md`.

2. **Documentation/Changelog.md** (new file)
   - v0.23.0 entry summarising this cleanup pass (no user-facing
     behaviour change; lots of hardening).

3. **Documentation/Index.md**
   - Add a _Reports_ pointer to the skill-pass reports committed
     under `Documentation/Reports/` so reviewers can replay the
     history.

4. **Documentation/Configuration.md** already has the Workspaces
   section (added in the workspaces pass). No more changes here.

5. **Documentation/Troubleshooting.md** — add a short entry for
   _Security check failed_, the new CSRF-rejection flash.

## Out of scope

- RST migration and docs.typo3.org rendering (a proper `guides.xml`,
  `Index.rst`, etc. — handled by a dedicated pass).
- Marketing-style screenshots / GIFs for the frontend plugins.
