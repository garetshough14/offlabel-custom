(() => {
  'use strict';

  const selectAll = document.querySelector('[data-olr-select-all]');
  const products = Array.from(document.querySelectorAll('[data-olr-product-checkbox]:not(:disabled)'));

  if (!selectAll || !products.length) {
    return;
  }

  const refresh = () => {
    const selected = products.filter((checkbox) => checkbox.checked).length;
    selectAll.checked = selected === products.length;
    selectAll.indeterminate = selected > 0 && selected < products.length;
  };

  selectAll.addEventListener('change', () => {
    products.forEach((checkbox) => {
      checkbox.checked = selectAll.checked;
    });
    refresh();
  });

  products.forEach((checkbox) => checkbox.addEventListener('change', refresh));
  refresh();
})();
