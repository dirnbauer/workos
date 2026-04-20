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

async function fetchWidgetToken(tokenUri, requestToken) {
    const body = new URLSearchParams();
    if (requestToken) {
        body.append('__RequestToken', requestToken);
    }
    const response = await fetch(tokenUri, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'fetch',
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body.toString(),
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

function normalizeAppearance(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const normalized = value.trim().toLowerCase();
    const hasDark = /\bdark\b/.test(normalized);
    const hasLight = /\blight\b/.test(normalized);

    if (hasDark === hasLight) {
        return null;
    }

    return hasDark ? 'dark' : 'light';
}

function detectAppearanceFromAttributes(element) {
    if (!element) {
        return null;
    }

    for (const attributeName of ['data-color-scheme', 'data-bs-theme', 'data-theme']) {
        const appearance = normalizeAppearance(element.getAttribute(attributeName));
        if (appearance) {
            return appearance;
        }
    }

    for (const className of element.classList) {
        if (/(^|[-_])dark($|[-_])/.test(className)) {
            return 'dark';
        }
        if (/(^|[-_])light($|[-_])/.test(className)) {
            return 'light';
        }
    }

    return null;
}

function parseColor(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const channels = value.match(/[\d.]+/g);
    if (!channels || channels.length < 3) {
        return null;
    }

    const [r, g, b, alpha] = channels.map(Number);
    if (typeof alpha === 'number' && alpha === 0) {
        return null;
    }

    return { r, g, b };
}

function relativeLuminance({ r, g, b }) {
    const transform = (channel) => {
        const srgb = channel / 255;
        return srgb <= 0.03928 ? srgb / 12.92 : ((srgb + 0.055) / 1.055) ** 2.4;
    };

    return (0.2126 * transform(r)) + (0.7152 * transform(g)) + (0.0722 * transform(b));
}

function detectAppearanceFromComputedStyles(elements) {
    for (const element of elements) {
        if (!element) {
            continue;
        }

        const styles = window.getComputedStyle(element);
        const colorScheme = normalizeAppearance(styles.colorScheme);
        if (colorScheme) {
            return colorScheme;
        }

        const backgroundColor = parseColor(styles.backgroundColor);
        if (!backgroundColor) {
            continue;
        }

        return relativeLuminance(backgroundColor) < 0.45 ? 'dark' : 'light';
    }

    return null;
}

function resolveWidgetAppearance(mountEl) {
    return (
        detectAppearanceFromAttributes(document.documentElement) ||
        detectAppearanceFromAttributes(document.body) ||
        detectAppearanceFromAttributes(mountEl.closest('.module-body')) ||
        detectAppearanceFromComputedStyles([
            mountEl.closest('.card'),
            mountEl.closest('.module-body'),
            document.body,
            document.documentElement,
        ]) ||
        (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
    );
}

function applyAppearanceAttributes(element, appearance) {
    if (!element) {
        return;
    }

    element.style.colorScheme = appearance;
    element.setAttribute('data-color-scheme', appearance);
    element.setAttribute('data-theme', appearance);
    element.classList.toggle('dark', appearance === 'dark');
    element.classList.toggle('light', appearance === 'light');
    element.classList.toggle('dark-theme', appearance === 'dark');
    element.classList.toggle('light-theme', appearance === 'light');
}

function observeAppearanceChanges(mountEl, onChange) {
    let currentAppearance = resolveWidgetAppearance(mountEl);
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    let frameId = null;

    const handleChange = () => {
        frameId = null;
        const nextAppearance = resolveWidgetAppearance(mountEl);
        if (nextAppearance === currentAppearance) {
            return;
        }

        currentAppearance = nextAppearance;
        onChange(nextAppearance);
    };

    const scheduleHandleChange = () => {
        if (frameId !== null) {
            return;
        }

        frameId = window.requestAnimationFrame(handleChange);
    };

    const observer = new MutationObserver(scheduleHandleChange);
    for (const element of [
        document.documentElement,
        document.body,
        mountEl.closest('.module-body'),
        mountEl.closest('.card'),
    ]) {
        if (!element) {
            continue;
        }

        observer.observe(element, {
            attributes: true,
            attributeFilter: ['class', 'data-color-scheme', 'data-bs-theme', 'data-theme', 'style'],
        });
    }

    if (document.head) {
        observer.observe(document.head, {
            attributes: true,
            childList: true,
            subtree: true,
            attributeFilter: ['class', 'disabled', 'href', 'media', 'rel', 'style'],
        });
    }

    if (typeof mediaQuery.addEventListener === 'function') {
        mediaQuery.addEventListener('change', scheduleHandleChange);
    } else if (typeof mediaQuery.addListener === 'function') {
        mediaQuery.addListener(scheduleHandleChange);
    }

    return () => {
        observer.disconnect();
        if (frameId !== null) {
            window.cancelAnimationFrame(frameId);
            frameId = null;
        }
        if (typeof mediaQuery.removeEventListener === 'function') {
            mediaQuery.removeEventListener('change', scheduleHandleChange);
        } else if (typeof mediaQuery.removeListener === 'function') {
            mediaQuery.removeListener(scheduleHandleChange);
        }
    };
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
    const requestToken = mountEl.getAttribute('data-request-token') || '';

    try {
        ensureStylesheet(resolveAsset('radix-themes.css'), 'workos-radix-theme-css');
        ensureStylesheet(resolveAsset('user-management-widget.bundle.css'), 'workos-widgets-css');

        const token = await fetchWidgetToken(tokenUri, requestToken);

        const container = document.createElement('div');
        container.style.display = 'block';
        container.style.width = '100%';
        container.style.minHeight = '600px';
        mountEl.innerHTML = '';
        mountEl.appendChild(container);

        let widgetHandle = null;
        const renderWidget = (appearance = resolveWidgetAppearance(mountEl)) => {
            widgetHandle?.unmount?.();
            applyAppearanceAttributes(container, appearance);
            widgetHandle = mountWorkosWidget({ container, authToken: token, appearance });
        };

        renderWidget();
        const stopObservingAppearance = observeAppearanceChanges(mountEl, renderWidget);
        window.addEventListener('beforeunload', stopObservingAppearance, { once: true });
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
