// Mounts the WorkOS User Management Widget inside the TYPO3 backend
// module (workos_users). The widget bundle is loaded from WorkOS's CDN
// and exposes the <wos-widget> web component. We fetch a short-lived
// widget token from our own TYPO3 route and hand it to the component.
//
// Docs: https://workos.com/docs/widgets/user-management

const WIDGET_SRC = 'https://unpkg.com/@workos-inc/widgets@latest/dist/index.js';

function ensureWidgetBundle() {
    if (window.__workosWidgetsLoader) {
        return window.__workosWidgetsLoader;
    }
    window.__workosWidgetsLoader = new Promise((resolve, reject) => {
        if (customElements.get('wos-widget')) {
            resolve();
            return;
        }
        const script = document.createElement('script');
        script.type = 'module';
        script.src = WIDGET_SRC;
        script.addEventListener('load', () => resolve());
        script.addEventListener('error', () => reject(new Error('Failed to load WorkOS widgets bundle')));
        document.head.appendChild(script);
    });
    return window.__workosWidgetsLoader;
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
        const [token] = await Promise.all([
            fetchWidgetToken(tokenUri),
            ensureWidgetBundle(),
        ]);

        const widget = document.createElement('wos-widget');
        widget.setAttribute('authToken', token);
        widget.setAttribute('widget', 'users-table-manage');
        widget.style.display = 'block';
        widget.style.width = '100%';
        widget.style.minHeight = '600px';

        mountEl.innerHTML = '';
        mountEl.appendChild(widget);
    } catch (error) {
        renderError(mountEl, error && error.message ? error.message : 'Unable to load the WorkOS widget.');
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount, { once: true });
} else {
    mount();
}
