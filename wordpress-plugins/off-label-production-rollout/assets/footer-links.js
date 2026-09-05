(function () {
  'use strict';

  function updateFooters() {
    document.querySelectorAll('.olr-managed-footer, .olr-checkout-test__footer').forEach(function (footer) {
      footer.querySelectorAll('a[href]').forEach(function (link) {
        var url = new URL(link.getAttribute('href'), window.location.origin);
        if (url.origin === window.location.origin && /^\/terms(?:-and)?-conditions\/?$/.test(url.pathname)) {
          link.href = window.location.origin + '/terms-and-conditions/';
        }
      });
      footer.querySelectorAll('.olr-footer-nav, .olr-checkout-test__footer > div').forEach(function (column) {
        var heading = column.querySelector('h2, b');
        if (!heading || heading.textContent.trim().toLowerCase() !== 'support') return;
        column.querySelectorAll('a[href]').forEach(function (link) {
          var path = new URL(link.getAttribute('href'), window.location.origin).pathname;
          if (!/^\/(terms-and-conditions|privacy-policy)\/?$/.test(path)) link.remove();
        });
      });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', updateFooters);
  else updateFooters();
})();
