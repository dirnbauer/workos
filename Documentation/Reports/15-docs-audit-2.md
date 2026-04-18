# Docs Audit — Second Pass

_Skill applied:_ `typo3-docs`
_Extension:_ `workos_auth`

## Changes to apply

1. **README.md**
   - Quality section now needs to mention PHPStan level **max**
     (not level 9) and **77 tests** (not 43).
   - Add the `Build/Scripts/runTests.sh -s ci` invocation as the
     canonical harness.

2. **Documentation/Changelog.md**
   - Insert a **0.24.0** entry covering the level-max uplift,
     MixedCaster, TCA regression test, conformance polish
     (.editorconfig, runTests.sh, CSS extraction).

3. **Documentation/Configuration.md** — no change (workspaces
   section already current).

4. **Documentation/Troubleshooting.md** — no change.

## Scope explicitly not covered

- RST migration.
- New screenshots.
