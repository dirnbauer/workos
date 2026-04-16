// Thin loader that mounts the WorkOS User Management widget inside the
// TYPO3 backend module (workos_users). The actual React/Radix/widget
// code lives in user-management-widget.bundle.js which is built locally
// by the Build/user-management-widget/ esbuild pipeline; that avoids
// the esm.sh code-splitting that breaks the Radix ThemeContext.

import { mount as mountWorkosWidget } from '@webconsulting/workos-auth/user-management-widget.bundle.js';

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

function resolveAsset(relativePath) {
    const base = new URL('.', import.meta.url);
    return new URL(relativePath, base).toString();
}

async function bootstrap() {
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
        ensureStylesheet(resolveAsset('radix-themes.css'), 'workos-radix-theme-css');
        ensureStylesheet(resolveAsset('user-management-widget.bundle.css'), 'workos-widgets-css');

        const token = await fetchWidgetToken(tokenUri);

        const container = document.createElement('div');
        container.style.display = 'block';
        container.style.width = '100%';
        container.style.minHeight = '600px';
        mountEl.innerHTML = '';
        mountEl.appendChild(container);

        mountWorkosWidget({ container, authToken: token, appearance: 'inherit' });
    } catch (error) {
        const message = error && error.message ? error.message : 'Unable to load the WorkOS widget.';
        renderError(mountEl, message);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
    bootstrap();
}
