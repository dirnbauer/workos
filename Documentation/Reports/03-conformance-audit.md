# Conformance Audit Report

_Skill applied:_ `typo3-conformance`
_TYPO3 target:_ v14.x
_Extension:_ `workos_auth`

## Scorecard (estimated)

| Dimension           | Points | Notes                                                                 |
|---------------------|-------:|-----------------------------------------------------------------------|
| Architecture        |  19/20 | Constructor DI everywhere; Services.yaml well-formed; PSR-14 attrs    |
| Coding guidelines   |  20/20 | `declare(strict_types=1)` on every `Classes/` file; PSR-12; no `@var` |
| PHP                 |  20/20 | No implicit nullable, no PHP 8.5 float-int casts; PHPStan level 9     |
| Testing             |  13/20 | Only 5 unit tests; functional coverage will land with testing skill   |
| Practices           |  17/20 | No CI yet, no ddev `runTests.sh`, but PHPStan scripted in composer    |

**Baseline: ~89 / 100.** Production-ready. Remaining points are handled
by later skills (testing, docs).

## Hard checks

| Check                                        | Result                             |
|----------------------------------------------|------------------------------------|
| Every `Classes/*.php` has `declare(strict_types=1)` | ✅ |
| `ext_emconf.php` has no `strict_types`       | ✅                                 |
| No `GeneralUtility::makeInstance` for services | ✅                               |
| No Bootstrap 4 `data-toggle` / `data-dismiss` attrs in Fluid | ✅       |
| No cache `has()`+`get()` anti-pattern        | ✅ (single `has()` is on the new `RequestBody` DTO) |
| All 7 XLIFF files are valid XML              | ✅                                 |
| No implicit-nullable method parameters        | ✅                                 |
| `GLOBALS` access narrowed via `instanceof` / `is_array()` | ✅ after upgrade pass |
| `final` on every class                        | ✅ 22/22 (one `readonly` + `final`) |
| Services.yaml sets `autowire: true` + `autoconfigure: true` defaults | ✅ |
| All public Symfony services (controllers, middleware, login provider) explicitly set `public: true` | ✅ |
| No `ViewHelpers/` directory → no custom VH to harden                         | ✅ |

## Findings

### Nice to have (not blockers)

1. **Inline `<style>` in Fluid templates.** `Frontend/Login/Show.html`
   inlines a stylesheet. Move to `Resources/Public/Css/` and register via
   the existing CSP and/or `assetCollector`. Low priority — the CSS is
   scoped and not leaking globals.
2. **Missing `.editorconfig` / `.php-cs-fixer.dist.php`.** Helpful for
   contributor hygiene but not a v14 requirement.
3. **No `Tests/Functional/`.** Handled by `typo3-testing` in the next
   pass.
4. **No CI config (`.github/workflows/tests.yml`, `.ddev/`).** Out of
   scope for this audit — would normally push score above 95.

### Applied immediately in this pass

- **Version bump in `ext_emconf.php` and a CHANGELOG note.** The
  upgrade pass already changed a DB API call (`lastInsertId()`) and the
  identity TCA — bumping to `0.23.0` reflects that; already committed
  in the previous step.
- **Composer metadata polish.** `composer.json` now declares
  `conflict` / `authors` / `keywords` and pins `allow-plugins` so
  TER uploads install cleanly.
- **Extension metadata polish.** `ext_emconf.php` now hard-codes the
  extension key instead of using the removed `$_EXTKEY` superglobal.

## Deferred

- Test coverage uplift (`typo3-testing`).
- README/docs modernization (`typo3-docs`).
- CI wiring (`enterprise-readiness`).
