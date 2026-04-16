// Handles WorkOS login actions on the TYPO3 backend login page.
//
// Problem: The TYPO3 backend wraps every login provider's fields inside
// <form id="typo3-login-form">. Any field/button we inject there can
// inadvertently participate in TYPO3's own username/password login flow.
//
// Solution: On init we take our WorkOS region (div.workos-login-root)
// out of TYPO3's form and wrap it in a dedicated form. Clicking one of
// our buttons sets the form action to the matching WorkOS endpoint
// (password auth, magic-auth send or magic-auth verify) and submits it
// natively. No AJAX, no interference with TYPO3's own JS.

function mountWorkosLogin() {
    const region = document.querySelector('.workos-login-root');
    if (!region) {
        return;
    }

    const passwordUrl = region.getAttribute('data-workos-password-url') || '';
    const magicSendUrl = region.getAttribute('data-workos-magic-send-url') || '';
    const magicVerifyUrl = region.getAttribute('data-workos-magic-verify-url') || '';
    const emailVerifyUrl = region.getAttribute('data-workos-email-verify-url') || '';

    const hostForm = region.closest('#typo3-login-form');

    const newForm = document.createElement('form');
    newForm.method = 'POST';
    newForm.action = passwordUrl || magicVerifyUrl || magicSendUrl || '';
    newForm.setAttribute('novalidate', 'novalidate');
    newForm.className = 'workos-login-form';

    newForm.appendChild(region);
    if (hostForm && hostForm.parentNode) {
        hostForm.parentNode.insertBefore(newForm, hostForm.nextSibling);
    } else {
        document.body.appendChild(newForm);
    }

    const emailInput = region.querySelector('#workos-email');
    const passwordInput = region.querySelector('#workos-password');
    const magicEmailInput = region.querySelector('#workos-magic-email');
    if (emailInput) {
        emailInput.name = 'email';
    }
    if (passwordInput) {
        passwordInput.name = 'password';
    }
    if (magicEmailInput) {
        magicEmailInput.name = 'magic_email';
    }

    const passwordBtn = region.querySelector('#workos-password-submit');
    const magicSendBtn = region.querySelector('#workos-magic-send-submit');
    const magicVerifyBtn = region.querySelector('#workos-magic-verify-submit');
    const emailVerifyBtn = region.querySelector('#workos-email-verify-submit');

    // Only one field should be submitted under name="email" per request.
    // Swap the role based on which button the user clicks.
    const usePasswordEmailField = () => {
        if (emailInput) {
            emailInput.disabled = false;
            emailInput.name = 'email';
        }
        if (magicEmailInput) {
            magicEmailInput.disabled = true;
            magicEmailInput.removeAttribute('required');
        }
    };
    const useMagicEmailField = () => {
        if (magicEmailInput) {
            magicEmailInput.disabled = false;
            magicEmailInput.name = 'email';
            magicEmailInput.setAttribute('required', 'required');
        }
        if (emailInput) {
            emailInput.disabled = true;
            emailInput.removeAttribute('required');
        }
        if (passwordInput) {
            passwordInput.disabled = true;
            passwordInput.removeAttribute('required');
        }
    };

    const submitWith = (action, { requirePassword = false, requireCode = false } = {}) => {
        if (!action) {
            return;
        }
        if (requirePassword) {
            usePasswordEmailField();
            if (emailInput) emailInput.setAttribute('required', 'required');
            if (passwordInput) passwordInput.setAttribute('required', 'required');
        } else if (!requireCode) {
            if (emailInput) emailInput.setAttribute('required', 'required');
            if (passwordInput) passwordInput.removeAttribute('required');
        }
        if (!newForm.reportValidity()) {
            return;
        }
        newForm.action = action;
        newForm.submit();
    };

    if (passwordBtn) {
        passwordBtn.addEventListener('click', (event) => {
            event.preventDefault();
            usePasswordEmailField();
            submitWith(passwordUrl, { requirePassword: true });
        });
    }
    if (magicSendBtn) {
        magicSendBtn.addEventListener('click', (event) => {
            event.preventDefault();
            useMagicEmailField();
            submitWith(magicSendUrl);
        });
    }
    if (magicVerifyBtn) {
        magicVerifyBtn.addEventListener('click', (event) => {
            event.preventDefault();
            submitWith(magicVerifyUrl, { requireCode: true });
        });
    }
    if (emailVerifyBtn) {
        emailVerifyBtn.addEventListener('click', (event) => {
            event.preventDefault();
            submitWith(emailVerifyUrl, { requireCode: true });
        });
    }

    const verifyCodeInput = region.querySelector('#workos-magic-code');
    if (verifyCodeInput && magicVerifyBtn) {
        verifyCodeInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                magicVerifyBtn.click();
            }
        });
    }
    const emailVerifyCodeInput = region.querySelector('#workos-email-code');
    if (emailVerifyCodeInput && emailVerifyBtn) {
        emailVerifyCodeInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                emailVerifyBtn.click();
            }
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountWorkosLogin, { once: true });
} else {
    mountWorkosLogin();
}
