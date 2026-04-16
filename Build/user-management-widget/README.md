# WorkOS User Management widget bundle

This directory builds a self-contained IIFE bundle of the
[WorkOS User Management widget](https://workos.com/docs/widgets/user-management)
and the CSS assets it needs.

## Why a local build?

The widget is a React 19 library that depends on
[`@radix-ui/themes`](https://www.radix-ui.com/). Loading it through
[esm.sh](https://esm.sh) at runtime code-splits `@radix-ui/themes` into
per-component submodules (for example `components/select.mjs`,
`components/dropdown-menu.mjs`), each of which re-declares its own
`ThemeContext` via `React.createContext(void 0)`. Those contexts are
therefore different instances and the widget immediately throws:

```
Error: `useThemeContext` must be used within a `Theme`
```

No combination of `?deps=…`, `?bundle-deps`, or an outer `<Theme>`
wrapper can make two different `ThemeContext` instances equal. We fix
the problem by pre-bundling everything with esbuild, which deduplicates
modules and produces a single `@radix-ui/themes` with a single
`ThemeContext`.

## Build

```bash
cd Build/user-management-widget
npm install
npm run build
```

Outputs:

- `Resources/Public/JavaScript/user-management-widget.bundle.js` &mdash;
  IIFE that sets `window.WorkosUserManagementWidget.mount(...)`.
- `Resources/Public/JavaScript/user-management-widget.bundle.css` &mdash;
  WorkOS widget styles.
- `Resources/Public/JavaScript/radix-themes.css` &mdash;
  Radix Themes styles.

The generated files **are committed** to the repository so the TYPO3
extension works out of the box without a JavaScript build step on the
target server.
