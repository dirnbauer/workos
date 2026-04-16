// Handles WorkOS password login on the TYPO3 backend login page.
// The TYPO3 backend login form attaches its own submit handler that
// performs an AJAX username/password login and ignores per-button
// formaction attributes. To bypass that we never submit TYPO3's form:
// on click (or Enter in our inputs) we build a detached <form> outside
// of #typo3-login-form and submit that directly to our middleware.

const submitButton = document.querySelector('#workos-password-submit');
const emailInput = document.querySelector('[data-workos-field="email"]');
const passwordInput = document.querySelector('[data-workos-field="password"]');

function submitWorkosLogin() {
    if (!submitButton || !emailInput || !passwordInput) {
        return;
    }

    const email = (emailInput.value || '').trim();
    const password = passwordInput.value || '';
    const action = submitButton.getAttribute('data-workos-password-url') || '';

    if (!email || !password || !action) {
        emailInput.focus();
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

if (submitButton) {
    submitButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopImmediatePropagation();
        submitWorkosLogin();
    });
}

[emailInput, passwordInput].forEach((input) => {
    if (!input) {
        return;
    }
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            event.stopImmediatePropagation();
            submitWorkosLogin();
        }
    });
});
