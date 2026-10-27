/**
 * WorkOS login provider on the TYPO3 backend login screen.
 *
 * The Core renders a provider's fields inside its own login form, whose
 * submit handling belongs to the username/password login. The WorkOS fields
 * therefore move into a form per sign-in method. Every submit button names its
 * endpoint (data-workos-action) and the fields it needs
 * (data-workos-requires); the browser then posts natively to that endpoint.
 * Pressing Enter uses the first button of the step, as in any form.
 */
const ENDPOINTS = {
  password: 'passwordAuthUrl',
  'magic-send': 'magicSendUrl',
  'magic-verify': 'magicVerifyUrl',
  'email-verify': 'emailVerifyUrl',
  'email-verify-resend': 'emailVerifyResendUrl',
};

function mountWorkosLogin() {
  const region = document.querySelector('[data-workos-login]');
  if (!region) {
    return;
  }

  const hostForm = region.closest('form');
  if (hostForm) {
    hostForm.after(region);
  }
  const requestToken = region.querySelector('[data-workos-request-token]');
  for (const fields of region.querySelectorAll('[data-workos-form]')) {
    const form = document.createElement('form');
    form.method = 'post';
    form.noValidate = true;
    fields.before(form);
    form.append(fields);
    if (requestToken) {
      form.append(requestToken.cloneNode(true));
    }
    mountForm(form, region);
  }
  requestToken?.remove();
}

function mountForm(form, region) {
  const buttons = [...form.querySelectorAll('button[data-workos-action]')];
  for (const button of buttons) {
    const url = region.dataset[ENDPOINTS[button.dataset.workosAction] ?? ''] ?? '';
    if (url === '') {
      button.disabled = true;
    } else {
      button.formAction = url;
    }
  }

  form.addEventListener('submit', (event) => {
    const submitter = event.submitter;
    if (!(submitter instanceof HTMLButtonElement) || !buttons.includes(submitter)) {
      event.preventDefault();
      return;
    }

    const required = (submitter.dataset.workosRequires ?? '').split(' ').filter(Boolean);
    for (const control of form.querySelectorAll('input[name]:not([type="hidden"])')) {
      control.required = required.includes(control.name);
      // Code requests are independent of the password form.
      control.disabled = control.name === 'password' && !required.includes('password');
    }
    if (!form.reportValidity()) {
      event.preventDefault();
      return;
    }
    submitter.setAttribute('aria-busy', 'true');
  });

  // Coming back through the history must not leave fields disabled.
  window.addEventListener('pageshow', () => {
    for (const control of form.querySelectorAll('input[disabled]')) {
      control.disabled = false;
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountWorkosLogin, { once: true });
} else {
  mountWorkosLogin();
}
