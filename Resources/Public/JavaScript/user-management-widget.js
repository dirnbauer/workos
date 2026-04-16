// Mounts the WorkOS User Management widget inside the TYPO3 backend
// module (workos_users). The upstream package @workos-inc/widgets is a
// React 19 library, so we load React, ReactDOM and the widgets bundle
// from esm.sh (pinned versions, with `deps` so every module shares the
// same React instance). Our PHP controller hands us a token endpoint
// from which we fetch a short-lived widget token.
//
// Docs: https://workos.com/docs/widgets/user-management

const WIDGETS_VERSION = '1.10.1';
const REACT_VERSION = '19';
const RADIX_THEMES_VERSION = '3';
const REACT_QUERY_VERSION = '5';

// `@workos-inc/widgets` declares these as peer dependencies. We must pin
// them in the `deps=` param of every esm.sh URL so the widget and all of
// its internal submodules share the *same* instance of React, Radix
// Themes and React Query. Otherwise Radix context providers from inside
// `<WorkOsWidgets>` cannot be seen by the inner components and the
// widget fails with "useThemeContext must be used within a Theme".
const SHARED_DEPS = [
    `react@${REACT_VERSION}`,
    `react-dom@${REACT_VERSION}`,
    `@radix-ui/themes@${RADIX_THEMES_VERSION}`,
    `@tanstack/react-query@${REACT_QUERY_VERSION}`,
].join(',');

const REACT_URL = `https://esm.sh/react@${REACT_VERSION}?deps=${SHARED_DEPS}`;
const REACT_DOM_CLIENT_URL = `https://esm.sh/react-dom@${REACT_VERSION}/client?deps=${SHARED_DEPS}`;
// `bundle-deps` inlines the widgets' own dependencies (Radix primitives,
// clsx, bowser, etc.) into one module and forces all internal submodules
// to share the Radix/React Query singletons declared as peer deps.
const WIDGETS_URL = `https://esm.sh/@workos-inc/widgets@${WIDGETS_VERSION}?bundle-deps&deps=${SHARED_DEPS}`;
// We explicitly load `@radix-ui/themes` ourselves and wrap the widget in
// our own <Theme>. Even though <WorkOsWidgets> already renders a Radix
// <Theme>, some widget components end up resolving `useThemeContext`
// against a subtly different Radix module instance loaded by esm.sh.
// Providing an outer Theme guarantees that any `useThemeContext` call
// finds a matching provider.
const RADIX_THEMES_URL = `https://esm.sh/@radix-ui/themes@${RADIX_THEMES_VERSION}?deps=${SHARED_DEPS}`;
const WIDGETS_CSS_URL = `https://esm.sh/@workos-inc/widgets@${WIDGETS_VERSION}/styles.css`;
const RADIX_THEME_CSS_URL = `https://esm.sh/@radix-ui/themes@${RADIX_THEMES_VERSION}/styles.css`;

function ensureStylesheet(href, id) {
    if (document.getElementById(id)) {
        return;
    }
    const link = document.createElement('link');
    link.id = id;
    link.rel = 'stylesheet';
    link.href = href;
    document.head.appendChild(link);
}

async function fetchWidgetToken(tokenUri) {
    const response = await fetch(tokenUri, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.token) {
        const message = payload && payload.error ? payload.error : `Token request failed (${response.status})`;
        throw new Error(message);
    }
    return payload.token;
}

function renderError(mountEl, message) {
    mountEl.innerHTML = '';
    const note = document.createElement('div');
    note.className = 'alert alert-warning';
    note.setAttribute('role', 'alert');
    note.textContent = message;
    mountEl.appendChild(note);
}

async function mount() {
    const mountEl = document.querySelector('[data-workos-user-management-mount]');
    if (!mountEl) {
        return;
    }
    const tokenUri = mountEl.getAttribute('data-token-uri') || '';
    if (!tokenUri) {
        renderError(mountEl, 'Missing token endpoint.');
        return;
    }

    try {
        ensureStylesheet(RADIX_THEME_CSS_URL, 'workos-radix-theme-css');
        ensureStylesheet(WIDGETS_CSS_URL, 'workos-widgets-css');

        const [token, React, ReactDOM, RadixModule, WidgetsModule] = await Promise.all([
            fetchWidgetToken(tokenUri),
            import(REACT_URL),
            import(REACT_DOM_CLIENT_URL),
            import(RADIX_THEMES_URL),
            import(WIDGETS_URL),
        ]);

        const { Theme } = RadixModule;
        const { WorkOsWidgets, UsersManagement } = WidgetsModule;
        if (!Theme) {
            throw new Error('Failed to load Radix Themes.');
        }
        if (!WorkOsWidgets || !UsersManagement) {
            throw new Error('Unexpected WorkOS widgets bundle.');
        }

        const container = document.createElement('div');
        container.style.display = 'block';
        container.style.width = '100%';
        container.style.minHeight = '600px';
        mountEl.innerHTML = '';
        mountEl.appendChild(container);

        const { createElement } = React;
        const root = ReactDOM.createRoot(container);
        root.render(
            createElement(
                Theme,
                { appearance: 'inherit' },
                createElement(
                    WorkOsWidgets,
                    null,
                    createElement(UsersManagement, { authToken: token })
                )
            )
        );
    } catch (error) {
        const message = error && error.message ? error.message : 'Unable to load the WorkOS widget.';
        renderError(mountEl, message);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount, { once: true });
} else {
    mount();
}
