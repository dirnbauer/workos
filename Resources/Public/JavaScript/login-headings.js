/**
 * Backend login: heading + relocated provider-switcher links/buttons.
 *
 * On the classic TYPO3 login page the heading text is supplied via a
 * <template data-workos-login-heading data-text="…"> element injected by
 * InjectLoginHeadingsListener (the WorkOS provider renders its own <h2>
 * server-side, so no template is needed there).
 *
 * On every login page the original "switch login provider" link rendered
 * by TYPO3 (`.typo3-login-links`, e.g. "Login with username and password"
 * or "Continue with WorkOS") is moved INTO the grey heading box as a
 * small inline text link directly under the heading title.
 *
 * The WorkOS "More sign-in options (SSO, passkey)" link
 * (`.workos-authkit-link[data-workos-relocate]`) is moved into a button
 * row right below the heading box (more visually prominent, since it is
 * the secondary CTA on the WorkOS provider screen).
 *
 * The originals are hidden via CSS to avoid a layout flash.
 */

function injectClassicHeading() {
    const tpl = document.querySelector('template[data-workos-login-heading]');
    if (!tpl) {
        return;
    }
    const text = tpl.getAttribute('data-text') || '';
    if (text === '') {
        return;
    }

    const form = document.getElementById('typo3-login-form');
    if (!form) {
        return;
    }
    if (form.querySelector('.workos-login-heading')) {
        return;
    }

    const heading = document.createElement('h2');
    heading.className = 'workos-login-heading';
    const title = document.createElement('span');
    title.className = 'workos-login-heading__title';
    title.textContent = text;
    heading.appendChild(title);
    form.insertBefore(heading, form.firstChild);
}

function ensureHeadingTitleWrapper(heading) {
    // The WorkOS provider template renders the heading text directly inside
    // <h2 class="workos-login-heading">. Wrap the existing children in a
    // dedicated title span so we can append the small switcher link below
    // it without touching the original markup.
    if (heading.querySelector(':scope > .workos-login-heading__title')) {
        return;
    }
    const title = document.createElement('span');
    title.className = 'workos-login-heading__title';
    while (heading.firstChild) {
        title.appendChild(heading.firstChild);
    }
    heading.appendChild(title);
}

function relocateProviderSwitcherIntoHeading(heading) {
    if (heading.querySelector('.workos-login-heading__link')) {
        return;
    }

    // TYPO3 core renders the switch-provider links inside
    // `.typo3-login-links`. We relocate them into our heading box, but
    // replace the core-supplied label with our own localised text
    // (delivered via <template data-switch-text="…">) so we can keep the
    // wording short and formal ("Sie"-Form) regardless of which provider
    // TYPO3 hands back.
    const tpl = document.querySelector('template[data-workos-login-heading]');
    const switchText = (tpl && tpl.getAttribute('data-switch-text')) || '';

    const anchors = document.querySelectorAll('.typo3-login-links a[href]');
    anchors.forEach((anchor) => {
        const href = anchor.getAttribute('href');
        if (!href) {
            return;
        }
        const labelSpan = anchor.querySelector('span:not(.t3js-icon):not(.icon-markup)');
        const coreLabel = ((labelSpan ? labelSpan.textContent : anchor.textContent) || '').trim();
        const label = switchText !== '' ? switchText : coreLabel;
        if (!label) {
            return;
        }
        const link = document.createElement('a');
        link.className = 'workos-login-heading__link';
        link.href = href;
        link.textContent = label;
        heading.appendChild(link);
    });
}

function relocateAuthkitButton(heading) {
    if (heading.nextElementSibling && heading.nextElementSibling.classList.contains('workos-login-buttons')) {
        return;
    }

    const items = [];
    document.querySelectorAll('.workos-authkit-link[data-workos-relocate] a[href]').forEach((anchor) => {
        const href = anchor.getAttribute('href');
        const label = (anchor.textContent || '').trim();
        if (href && label) {
            items.push({ href, label });
        }
    });

    if (items.length === 0) {
        return;
    }

    const row = document.createElement('div');
    row.className = 'workos-login-buttons';
    items.forEach(({ href, label }) => {
        const btn = document.createElement('a');
        btn.className = 'workos-login-buttons__btn workos-login-buttons__btn--authkit';
        btn.href = href;
        btn.textContent = label;
        row.appendChild(btn);
    });

    heading.insertAdjacentElement('afterend', row);
}

function init() {
    injectClassicHeading();
    const heading = document.querySelector('.workos-login-heading');
    if (!heading) {
        return;
    }
    ensureHeadingTitleWrapper(heading);
    relocateProviderSwitcherIntoHeading(heading);
    relocateAuthkitButton(heading);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
