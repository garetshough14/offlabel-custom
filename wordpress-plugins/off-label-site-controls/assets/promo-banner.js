(() => {
  'use strict';
  function update() {
    if (!Array.isArray(window.olrPromoBanner)) return;
    const rows = window.olrPromoBanner.filter(row => row && typeof row.text === 'string' && row.text.trim());
    document.querySelectorAll('.olr-managed-header .olr-announcement').forEach(banner => {
      const inner = banner.querySelector('.olr-announcement__inner');
      if (!inner) return;
      if (!rows.length) { banner.remove(); return; }
      const fragment = document.createDocumentFragment();
      rows.forEach((row, index) => {
        if (index) {
          const separator = document.createElement('span');
          separator.className = 'olr-announcement__separator';
          separator.setAttribute('aria-hidden', 'true');
          separator.textContent = '/';
          fragment.append(separator);
        }
        let url;
        try {
          const candidate = new URL(row.url || '', window.location.origin);
          if (row.url && ['http:', 'https:'].includes(candidate.protocol)) url = candidate.href;
        } catch (_) { /* Invalid optional links render as plain text. */ }
        const node = document.createElement(url ? 'a' : 'span');
        node.textContent = row.text;
        if (url) node.href = url;
        fragment.append(node);
      });
      inner.replaceChildren(fragment);
      banner.setAttribute('aria-label', 'Store announcements');
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', update, {once: true});
  else update();
})();
