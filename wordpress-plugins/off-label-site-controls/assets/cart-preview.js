(function () {
  'use strict';
  var config = window.olrCartPreview;
  if (!config || !config.endpoint || !window.fetch || !window.HTMLDialogElement) return;
  var dialog, previousFocus, busy = false, again = false, lastToken = '';
  try { lastToken = sessionStorage.getItem('olr-cart-preview-seen') || ''; } catch (_) {}
  function url(value) {
    try { var u = new URL(value, location.href); return u.origin === location.origin ? u.href : ''; } catch (_) { return ''; }
  }
  function close() { if (dialog && dialog.open) dialog.close(); }
  function show(data) {
    if (!dialog) {
      dialog = document.createElement('dialog');
      dialog.className = 'olr-cart-preview';
      dialog.setAttribute('aria-labelledby', 'olr-cart-preview-title');
      dialog.innerHTML = '<header class="olr-cart-preview__header"><p class="olr-cart-preview__eyebrow"><span aria-hidden="true">&#10003;</span> Cart updated</p><h2 id="olr-cart-preview-title" tabindex="-1">Added to your cart</h2><button class="olr-cart-preview__close" type="button" aria-label="Close cart preview"><svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="1.5"/></svg></button></header><div class="olr-cart-preview__contents"></div><footer class="olr-cart-preview__footer"><p class="olr-cart-preview__note">Shipping &amp; applicable taxes calculated at checkout.</p><div class="olr-cart-preview__actions"><a class="olr-cart-preview__checkout">Checkout <span aria-hidden="true">&#8594;</span></a><button class="olr-cart-preview__continue" type="button">Keep shopping</button></div></footer>';
      document.body.appendChild(dialog);
      dialog.querySelector('.olr-cart-preview__close').addEventListener('click', close);
      dialog.querySelector('.olr-cart-preview__continue').addEventListener('click', function () {
        close();
        // Completed boxes keep their existing redirect. From cart/checkout, return to catalog.
        var path = location.pathname.replace(/\/$/, '');
        if ([config.cart, config.checkout].some(function (value) { return url(value) && new URL(url(value)).pathname.replace(/\/$/, '') === path; })) location.assign(url(config.shop) || '/catalog/');
      });
      dialog.addEventListener('click', function (event) { if (event.target === dialog) { var r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) close(); } });
      dialog.addEventListener('close', function () { document.documentElement.classList.remove('olr-cart-preview-open'); if (previousFocus && previousFocus.isConnected) previousFocus.focus(); });
    }
    // HTML is exclusively server-rendered with wp_kses_post, never taken from URL/user input.
    dialog.querySelector('.olr-cart-preview__contents').innerHTML = data.html;
    dialog.querySelector('.olr-cart-preview__checkout').href = url(config.checkout) || '/cart/';
    document.querySelectorAll('.olr-cart-count').forEach(function (node) { node.textContent = String(Number(data.count) || 0); });
    if (!dialog.open) { previousFocus = document.activeElement; dialog.showModal(); document.documentElement.classList.add('olr-cart-preview-open'); dialog.querySelector('h2').focus({ preventScroll: true }); }
    lastToken = data.token;
    try { sessionStorage.setItem('olr-cart-preview-seen', lastToken); } catch (_) {}
  }
  function check() {
    if (busy) { again = true; return; }
    if (!url(config.endpoint)) return;
    busy = true;
    var controller = new AbortController();
    var timeout = setTimeout(function () { controller.abort(); }, 10000);
    fetch(url(config.endpoint), { method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal })
      .then(function (response) { if (!response.ok) throw new Error('Cart preview unavailable'); return response.json(); })
      .then(function (response) { var data = response && response.success && response.data; if (data && data.pending && data.token !== lastToken && typeof data.html === 'string') show(data); })
      .catch(function () { /* Leave normal WooCommerce cart and error handling untouched. */ })
      .finally(function () { clearTimeout(timeout); busy = false; if (again) { again = false; check(); } });
  }
  if (window.jQuery) window.jQuery(document.body).on('added_to_cart.olrPreview', check);
  document.addEventListener('wc-blocks_added_to_cart', check);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', check, { once: true }); else check();
}());
