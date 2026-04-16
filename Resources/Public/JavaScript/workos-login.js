// Handles WorkOS password login on the TYPO3 backend login page.
// The TYPO3 backend login form attaches its own submit handler that
// performs an AJAX username/password login and ignores per-button
// formaction attributes. To bypass that we never submit TYPO3's form:
// on click (or Enter in our inputs) we build a detached <form> outside
// of #typo3-login-form and submit that directly to our middleware.

function init() {
    const submitButton = document.querySelector('#workos-password-submit');
    const emailInput = document.querySelector('[data-workos-field="email"]');
    const passwordInput = document.querySelector('[data-workos-field="password"]');
    const loginForm = document.querySelector('#typo3-login-form');

    if (!submitButton || !emailInput || !passwordInput) {
        return;
    }

    function submitWorkosLogin() {
        const email = (emailInput.value || '').trim();
        const password = passwordInput.value || '';
        const action = submitButton.getAttribute('data-workos-password-url') || '';

        if (!email) {
            emailInput.focus();
            return;
        }
        if (!password) {
            passwordInput.focus();
            return;
        }
        if (!action) {
            return;
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = action;
        form.style.display = 'none';

        const emailField = document.createElement('input');
        emailField.type = 'hidden';
        emailField.name = 'email';
        emailField.value = email;
        form.appendChild(emailField);

        const passwordField = document.createElement('input');
        passwordField.type = 'hidden';
        passwordField.name = 'password';
        passwordField.value = password;
        form.appendChild(passwordField);

        document.body.appendChild(form);
        form.submit();
    }

    submitButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopImmediatePropagation();
        submitWorkosLogin();
    });

    const handleEnter = (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            event.stopImmediatePropagation();
            submitWorkosLogin();
        }
    };

    emailInput.addEventListener('keydown', handleEnter);
    passwordInput.addEventListener('keydown', handleEnter);

    // Safety net: if the TYPO3 login form is ever submitted while focus
    // is inside our inputs (e.g. TYPO3's JS fires submit programmatically),
    // redirect the submission through our password-auth endpoint instead.
    if (loginForm) {
        loginForm.addEventListener(
            'submit',
            (event) => {
                const active = document.activeElement;
                if (active === emailInput || active === passwordInput || active === submitButton) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    submitWorkosLogin();
                }
            },
            true,
        );
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}
