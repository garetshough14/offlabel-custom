(function () {
  'use strict';
  const config = window.olrPhonePopup;
  const dialog = document.getElementById('olr-pp-dialog');
  if (!config || !dialog || typeof dialog.showModal !== 'function') return;
  const form = dialog.querySelector('form');
  const phone = dialog.querySelector('#olr-pp-phone');
  const consent = dialog.querySelector('#olr-pp-consent');
  const error = dialog.querySelector('#olr-pp-error');
  const submit = form.querySelector('[type="submit"]');
  const buttonText = submit.textContent;
  let busy = false;
  let saved = false;
  let previousFocus = null;

  async function request(action, values) {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), 45000);
    try {
      const response = await fetch(config.url, {
        method: 'POST', credentials: 'same-origin', signal: controller.signal,
        headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
        body: new URLSearchParams(Object.assign({action: 'olr_pp_' + action, nonce: config.nonce, page: config.page, revision: config.revision}, values || {}))
      });
      const result = await response.json();
      if (!response.ok || !result || result.success !== true) {
        const failure = new Error(result?.data?.message || 'Your session may have expired. Refresh the page and try again.');
        failure.field = result?.data?.field;
        throw failure;
      }
      return result.data;
    } finally {
      window.clearTimeout(timer);
    }
  }

  function close() {
    dialog.close();
    if (!saved && !busy) request('dismiss').catch(() => {});
  }
  dialog.querySelectorAll('[data-pp-close]').forEach(button => button.addEventListener('click', close));
  dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
  dialog.addEventListener('close', () => {
    document.documentElement.classList.remove('olr-pp-open');
    if (previousFocus?.isConnected) previousFocus.focus({preventScroll: true});
  });
  [phone, consent].forEach(input => input.addEventListener('input', () => {
    input.removeAttribute('aria-invalid');
    error.textContent = '';
  }));
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    error.textContent = '';
    phone.removeAttribute('aria-invalid');
    consent.removeAttribute('aria-invalid');
    let invalid = null;
    if (!phone.value.trim()) {
      error.textContent = 'Enter your mobile number.';
      invalid = phone;
    } else if (consent.required && !consent.checked) {
      error.textContent = 'Please check the SMS consent box to join the text list, or choose Not now.';
      invalid = consent;
    }
    if (invalid) {
      invalid.setAttribute('aria-invalid', 'true');
      invalid.focus();
      return;
    }
    busy = true;
    submit.disabled = true;
    submit.textContent = 'Saving…';
    form.setAttribute('aria-busy', 'true');
    try {
      const result = await request('save', {phone: phone.value, consent: consent.checked ? '1' : '0'});
      saved = true;
      form.hidden = true;
      dialog.querySelector('#olr-pp-success').hidden = false;
      const message = dialog.querySelector('#olr-pp-result');
      message.textContent = result.message;
      if (dialog.open) message.focus({preventScroll: true});
    } catch (failure) {
      error.textContent = failure.name === 'AbortError' ? 'This is taking longer than expected. Your number may already be saved. Try again to check.' : (failure.message === 'Failed to fetch' ? 'Check your connection, then try again.' : failure.message);
      const field = failure.field === 'phone' ? phone : failure.field === 'consent' ? consent : null;
      if (field) { field.setAttribute('aria-invalid', 'true'); field.focus(); }
    } finally {
      busy = false;
      submit.disabled = false;
      submit.textContent = buttonText;
      form.removeAttribute('aria-busy');
    }
  });

  function anotherModalIsOpen() {
    return [...document.querySelectorAll('dialog[open], [aria-modal="true"]')].some(element =>
      element !== dialog && element.getClientRects().length > 0 && getComputedStyle(element).visibility !== 'hidden'
    );
  }
  async function openWhenReady() {
    // The site's research-access gate and other modal dialogs take precedence.
    if (document.hidden || anotherModalIsOpen() || document.documentElement.classList.contains('olr-gate-open')) {
      window.setTimeout(openWhenReady, 1000);
      return;
    }
    try {
      const status = await request('status');
      if (!status.visible) return;
      if (document.hidden || anotherModalIsOpen()) {
        window.setTimeout(openWhenReady, 1000);
        return;
      }
      phone.value = status.phone || '';
      previousFocus = document.activeElement;
      dialog.showModal();
      document.documentElement.classList.add('olr-pp-open');
      const title = dialog.querySelector('h2');
      title.setAttribute('tabindex', '-1');
      title.focus({preventScroll: true});
    } catch (_) {
      // Do not interrupt browsing when the session or eligibility check fails.
    }
  }
  window.setTimeout(openWhenReady, Math.max(0, Number(config.delay) || 0) * 1000);
}());
