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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', injectClassicHeading);
} else {
    injectClassicHeading();
}
