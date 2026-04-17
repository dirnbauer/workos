/**
 * Backend login: heading + relocated provider-switcher buttons.
 *
 * On the classic TYPO3 login page the heading text is supplied via a
 * <template data-workos-login-heading data-text="…"> element injected by
 * InjectLoginHeadingsListener (the WorkOS provider renders its own <h2>
 * server-side, so no template is needed there).
 *
 * On every login page the original "switch login provider" links rendered
 * by TYPO3 (`.typo3-login-links`) and the WorkOS "More sign-in options"
 * link (`.workos-authkit-link[data-workos-relocate]`) are moved into a
 * unified button row right under the heading. The originals are hidden via
 * CSS to avoid a layout flash.
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
    heading.textContent = text;
    form.insertBefore(heading, form.firstChild);
}

function relocateProviderButtons() {
    const heading = document.querySelector('.workos-login-heading');
    if (!heading) {
        return;
    }
    if (heading.nextElementSibling && heading.nextElementSibling.classList.contains('workos-login-buttons')) {
        return;
    }

    const items = [];

    // 1) TYPO3 default provider switcher (e.g. "Login with username and password"
    //    when the WorkOS provider is active, or "Continue with WorkOS" when the
    //    classic provider is active).
    document.querySelectorAll('.typo3-login-links a[href]').forEach((anchor) => {
        const href = anchor.getAttribute('href');
        const span = anchor.querySelector('span');
        const label = (span ? span.textContent : anchor.textContent || '').trim();
        if (href && label) {
            items.push({ href, label, kind: 'switch' });
        }
    });

    // 2) WorkOS "More sign-in options (SSO, passkey)" link — only present on
    //    the default WorkOS subview (not on the email-verify / magic-auth
    //    subviews, which carry their own "back to sign in" link instead).
    document.querySelectorAll('.workos-authkit-link[data-workos-relocate] a[href]').forEach((anchor) => {
        const href = anchor.getAttribute('href');
        const label = (anchor.textContent || '').trim();
        if (href && label) {
            items.push({ href, label, kind: 'authkit' });
        }
    });

    if (items.length === 0) {
        return;
    }

    const row = document.createElement('div');
    row.className = 'workos-login-buttons';
    items.forEach(({ href, label, kind }) => {
        const btn = document.createElement('a');
        btn.className = 'workos-login-buttons__btn workos-login-buttons__btn--' + kind;
        btn.href = href;
        btn.textContent = label;
        row.appendChild(btn);
    });

    heading.insertAdjacentElement('afterend', row);
}

function init() {
    injectClassicHeading();
    relocateProviderButtons();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
