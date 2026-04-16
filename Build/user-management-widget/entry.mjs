// Entry point for the WorkOS User Management widget bundle.
//
// Everything needed to render the widget is imported and bundled here:
// React, ReactDOM, @radix-ui/themes and @workos-inc/widgets. A single
// module graph guarantees that all consumers see the *same*
// Radix ThemeContext instance, fixing the
// "`useThemeContext` must be used within a `Theme`" error we hit when
// loading these packages from esm.sh (which code-splits Radix into
// submodules, each with its own React context).

import { createElement, StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Theme } from '@radix-ui/themes';
import { WorkOsWidgets, UsersManagement } from '@workos-inc/widgets';

function mount({ container, authToken, appearance }) {
    if (!container) {
        throw new Error('WorkOS widget mount: missing container element.');
    }
    if (!authToken) {
        throw new Error('WorkOS widget mount: missing authToken.');
    }

    const root = createRoot(container);
    root.render(
        createElement(
            Theme,
            { appearance: appearance || 'inherit', hasBackground: false },
            createElement(
                WorkOsWidgets,
                null,
                createElement(UsersManagement, { authToken })
            )
        )
    );
    return {
        unmount() {
            root.unmount();
        },
    };
}

export default { mount };
export { mount };
