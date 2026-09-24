(function () {
  'use strict';
  const settings = document.getElementById('olr-pp-settings');
  const dialog = document.getElementById('olr-pp-dialog');
  if (!settings || !dialog) return;
  const get = id => document.getElementById(id);
  let trigger = null;
  const defaultHelp = 'US / Canada: 10 digits. Elsewhere: include + and your country code.';
  const pageRows = [...settings.querySelectorAll('.olr-pp-admin__page')];
  function filterPages() {
    const needle = get('pp-page-search').value.toLocaleLowerCase();
    pageRows.forEach(row => { row.hidden = !row.textContent.toLocaleLowerCase().includes(needle); });
    const count = pageRows.filter(row => row.querySelector('input').checked).length;
    get('pp-page-count').textContent = count + (count === 1 ? ' page selected' : ' pages selected');
    get('pp-page-picker').hidden = get('pp-all-pages').checked;
  }
  get('pp-page-search').addEventListener('input', filterPages);
  get('pp-all-pages').addEventListener('change', filterPages);
  pageRows.forEach(row => row.addEventListener('change', filterPages));
  filterPages();
  function safePreviewLink(value) {
    try { const url = new URL(value); return url.protocol === 'https:' ? url.href : '#'; } catch (_) { return '#'; }
  }
  settings.querySelectorAll('[data-pp-preview]').forEach(button => button.addEventListener('click', () => {
    trigger = button;
    dialog.querySelector('#olr-pp-title').textContent = get('pp-title').value || 'Join the Off Label Text Club.';
    dialog.querySelector('#olr-pp-description').textContent = get('pp-body').value || 'Get exclusive text club deals and special offers sent straight to your phone.';
    dialog.querySelector('#olr-pp-form [type="submit"]').textContent = get('pp-button').value || 'Join the Text Club';
    dialog.querySelector('#olr-pp-phone-help').textContent = get('pp-local').checked ? defaultHelp : 'Include + and your country code.';
    dialog.querySelector('.olr-pp__consent').hidden = !get('pp-enable-sms').checked;
    dialog.querySelector('.olr-pp__consent label span').textContent = get('pp-consent').value;
    dialog.querySelector('#olr-pp-consent').checked = false;
    dialog.querySelector('#olr-pp-consent').required = get('pp-enable-sms').checked;
    dialog.querySelector('[data-pp-terms]').href = safePreviewLink(get('pp-terms').value);
    dialog.querySelector('[data-pp-privacy]').href = safePreviewLink(get('pp-privacy').value);
    dialog.querySelector('#olr-pp-form').hidden = false;
    dialog.querySelector('#olr-pp-success').hidden = true;
    dialog.querySelector('#olr-pp-phone').value = '';
    dialog.showModal();
    document.documentElement.classList.add('olr-pp-open');
    const title = dialog.querySelector('h2');
    title.setAttribute('tabindex', '-1');
    title.focus({preventScroll: true});
  }));
  dialog.querySelectorAll('[data-pp-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('close', () => {
    document.documentElement.classList.remove('olr-pp-open');
    trigger?.focus({preventScroll: true});
  });
  dialog.querySelector('form').addEventListener('submit', event => {
    event.preventDefault();
    dialog.querySelector('#olr-pp-form').hidden = true;
    dialog.querySelector('#olr-pp-success').hidden = false;
    const result = dialog.querySelector('#olr-pp-result');
    result.textContent = 'Preview complete. A live submission saves to the member’s billing phone' + (get('pp-enable-sms').checked ? ' and sends their SMS opt-in to Omnisend.' : '.');
    result.focus();
  });
}());
