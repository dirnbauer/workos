# WorkOS User Management widget bundle

Builds a self-contained ES module of the
[WorkOS User Management widget](https://workos.com/docs/widgets/user-management)
(React 19, `@radix-ui/themes`, `@workos-inc/widgets`) plus the CSS it needs.

Loading the widget from esm.sh at runtime code-splits `@radix-ui/themes`
into per-component modules with separate `ThemeContext` instances, which
makes the widget throw "`useThemeContext` must be used within a `Theme`".
Bundling everything once with esbuild yields a single context.

```bash
npm ci
npm run build
```

Outputs (committed, so the extension ships ready to use; the CI `assets`
job rebuilds them and fails on a diff):

- `Resources/Public/JavaScript/user-management-widget.bundle.js` -
  exports `mount({ container, authToken, appearance })`.
- `Resources/Public/JavaScript/user-management-widget.bundle.css`
- `Resources/Public/JavaScript/radix-themes.css`
