# Conformance Audit Report — Second Pass

_Skill applied:_ `typo3-conformance`
_Extension:_ `workos_auth`

## Score update

First pass: **~89 / 100.** After the level-max + test + doc uplift the
fair estimate is **~93 / 100** (+4 from Testing and Practices).

## Changes worth doing in this pass

### 1. `.editorconfig` at project root

Standard TYPO3 contributor convention — enforces 4-space indent,
LF line endings, trim-whitespace, 80-col for Markdown, 120 for
PHP. A one-file addition with no runtime impact.

### 2. `Build/Scripts/runTests.sh` scaffold

Even without a DDEV matrix, a thin script that wraps `composer
phpstan`, `composer test:unit`, and `composer test:functional`
behind a `-s` flag makes local and CI invocations uniform. This is
a TER-readiness signal called out in the scoring rubric.

### 3. Inline `<style>` extraction

`Resources/Private/Templates/Frontend/Login/Show.html` has an
inline `<style>` block. Move to
`Resources/Public/Css/plugin-login.css` and register via Page
Renderer or TypoScript (not inside the template).

## Out of scope (this pass)

- CI workflow, GitHub Actions, DDEV — dedicated
  `enterprise-readiness` pass.
- Functional tests — `typo3-testing` pass already scaffolded
  `Build/phpunit/FunctionalTests.xml`; tests themselves stay
  deferred.
