// Handles WorkOS password login on the TYPO3 backend login page.
//
// Problem: The TYPO3 backend wraps every login provider's fields inside
// <form id="typo3-login-form">. Any field/button we inject there can
// inadvertently participate in TYPO3's own username/password login flow.
//
// Solution: On init we take our WorkOS region (div.workos-login-root)
// out of TYPO3's form and wrap it in a dedicated form that POSTs to our
// /workos-auth/backend/password-auth middleware. From then on the submit
// path is a native form POST, no JavaScript interference required.

function mountWorkosLogin() {
    const region = document.querySelector('.workos-login-root');
    if (!region) {
        return;
    }

    const submitButton = region.querySelector('#workos-password-submit');
    if (!submitButton) {
        return;
    }
    const action = submitButton.getAttribute('data-workos-password-url') || '';
    if (!action) {
        return;
    }

    const hostForm = region.closest('#typo3-login-form');

    const newForm = document.createElement('form');
    newForm.method = 'POST';
    newForm.action = action;
    newForm.setAttribute('novalidate', 'novalidate');
    newForm.className = 'workos-login-form';

    // Move region's content into the new form, then place the new form
    // outside (right after) TYPO3's form so it becomes a first-class
    // sibling in the DOM.
    newForm.appendChild(region);
    if (hostForm && hostForm.parentNode) {
        hostForm.parentNode.insertBefore(newForm, hostForm.nextSibling);
    } else {
        document.body.appendChild(newForm);
    }

    // Normalize the submit button so native form submission is used.
    submitButton.type = 'submit';

    // Make sure email/password inputs are part of the submission.
    const emailInput = region.querySelector('#workos-email');
    const passwordInput = region.querySelector('#workos-password');
    if (emailInput) {
        emailInput.name = 'email';
    }
    if (passwordInput) {
        passwordInput.name = 'password';
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountWorkosLogin, { once: true });
} else {
    mountWorkosLogin();
}
