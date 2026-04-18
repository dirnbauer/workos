# Conformance Audit Report — Third Pass

_Skill applied:_ `typo3-conformance`
_Extension:_ `workos_auth`

## Score

Prior pass: **~93 / 100**. This sweep closes two long-standing
"nice-to-have" items that the earlier passes deferred, bringing the
estimate to **~95 / 100**.

## Hard-check delta

All hard checks from passes 1 and 2 still green:

- `declare(strict_types=1)` present on every `Classes/*.php` file.
- `ext_emconf.php` carries no `strict_types`.
- No `GeneralUtility::makeInstance` for services; constructor DI
  everywhere.
- No Bootstrap 4 data attributes (`data-toggle`, `data-dismiss`,
  `data-ride`) in any Fluid template.
- No cache `has()`/`get()` anti-pattern.
- No Extbase legacy annotations (no `@Extbase\Validate` docblocks).

## New findings this pass

### F1 — XLIFF parity gap in `de.locallang.xlf`

`locallang.xlf` ships 311 `trans-unit` entries, `de.locallang.xlf`
ships only 309. The two missing keys are
`account.flash.csrfInvalid` and `team.flash.csrfInvalid`. Both were
added in a prior CSRF-hardening pass but never mirrored into the
German file.

Fix: add both trans-units with German targets.

### F2 — `de.locallang_mod.xlf` out of sync with English source

- The `mlang_tabs_tab` entry uses `WorkOS Auth` in the German file
  and `WorkOS` in the English source — inconsistent wording.
- The `mlang_labels_tablabel` unit is missing entirely in the German
  file.
- The `mlang_labels_tabdescr` source differs from English
  (`Configure WorkOS-powered...` vs. `Manage WorkOS user management...`).

Fix: resync the German file to the English source and keep existing
German targets where they still map.

### F3 — No German translations for the two sub-module XLIFFs

`locallang_mod_users.xlf` and `locallang_mod_setup.xlf` have no
German counterparts. Both contain three entries (tab, tablabel,
tabdescr). Module picker in a German backend will therefore fall
back to English for these modules.

Fix: add `de.locallang_mod_users.xlf` and
`de.locallang_mod_setup.xlf` with translated `mlang_*` entries.

### F4 — Inline `<style>` blocks in two Fluid templates

`Frontend/Account/Dashboard.html` and `Frontend/Team/Dashboard.html`
each carry a ~100-line `<style>` block inside the template. This
trips CSP strict `style-src` in the future (when `'unsafe-inline'`
is removed) and makes the stylesheet unreusable across both
dashboards despite large overlap. `Frontend/Login/Show.html` has a
much smaller block that is acceptable for now.

Fix: extract both blocks to
`Resources/Public/Css/Frontend/dashboard.css` and load them via
`<f:asset.css>` inside the template. This keeps the CSS scoped to
the template (no global registration needed) and makes the CSP
contract clean.

## Plan

1. Patch `de.locallang.xlf` — insert the two missing trans-units
   next to their English neighbours.
2. Rewrite `de.locallang_mod.xlf` to match `locallang_mod.xlf`
   exactly (three units, German targets preserved).
3. Create `de.locallang_mod_users.xlf` and `de.locallang_mod_setup.xlf`.
4. Extract the two dashboard `<style>` blocks to
   `Resources/Public/Css/Frontend/dashboard.css`; reference via
   `<f:asset.css identifier="…" href="…" />` in each template.

## Deferred

- `Login/Show.html`'s small inline block — kept in place; it is
  scoped to a single view and removing it doesn't help the CSP story.
- Full Playwright E2E coverage for the dashboards (testing skill
  territory).
