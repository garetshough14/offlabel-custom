(function () {
  'use strict';

  function initGallery(root) {
    var stageImage = root.querySelector('[data-olr-gallery-stage] img');
    if (!stageImage) return;
    root.querySelectorAll('[data-olr-gallery-thumb]').forEach(function (button) {
      button.addEventListener('click', function () {
        root.querySelectorAll('[data-olr-gallery-thumb]').forEach(function (item) {
          item.classList.toggle('is-active', item === button);
        });
        stageImage.src = button.dataset.fullSrc;
        stageImage.removeAttribute('srcset');
      });
    });
  }

  function initVariationChips(form) {
    var purchase = form.closest('.olr-product-view__purchase');
    var summary = form.closest('.olr-product-view__summary');
    form.querySelectorAll('table.variations select').forEach(function (select) {
      if (select.options.length < 2) return;
      var chips = document.createElement('div');
      chips.className = 'olr-variation-chips';
      chips.setAttribute('aria-label', select.closest('tr').querySelector('label')?.textContent || 'Product options');
      Array.from(select.options).filter(function (option) { return option.value; }).forEach(function (option) {
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = option.textContent;
        button.classList.toggle('is-active', option.selected);
        button.addEventListener('click', function () {
          select.value = option.value;
          select.dispatchEvent(new Event('change', { bubbles: true }));
          chips.querySelectorAll('button').forEach(function (item) {
            item.classList.toggle('is-active', item === button);
          });
        });
        chips.appendChild(button);
      });
      select.hidden = true;
      select.after(chips);
      if (purchase && summary) summary.insertBefore(chips, purchase);
    });
    var variationTable = form.querySelector('table.variations');
    if (variationTable && form.querySelector('.olr-variation-chips') === null) variationTable.hidden = true;
  }

  function initQuantity(root) {
    var quantityInput = root.querySelector('form.cart input.qty');
    if (!quantityInput) return;
    root.querySelectorAll('[data-olr-quantity]').forEach(function (button) {
      button.addEventListener('click', function () {
        quantityInput.value = button.dataset.olrQuantity;
        quantityInput.dispatchEvent(new Event('change', { bubbles: true }));
        root.querySelectorAll('[data-olr-quantity]').forEach(function (item) {
          item.classList.toggle('is-active', item === button);
        });
      });
    });
    var first = root.querySelector('[data-olr-quantity="1"]');
    if (first) first.classList.add('is-active');
  }

  function placePurchaseLast(root) {
    var purchase = root.querySelector('.olr-product-view__purchase');
    var volume = root.querySelector('.olr-volume-pricing');
    var notice = root.querySelector('.olr-product-view__notice');
    if (!purchase) return;
    if (volume) volume.after(purchase);
    else if (notice) notice.before(purchase);
  }

  function initAccordions(root) {
    root.querySelectorAll('details').forEach(function (details) {
      details.open = false;
    });
  }

  document.querySelectorAll('[data-olr-gallery]').forEach(initGallery);
  document.querySelectorAll('.olr-product-view__purchase form.cart').forEach(initVariationChips);
  document.querySelectorAll('.olr-product-view').forEach(initQuantity);
  document.querySelectorAll('.olr-product-view').forEach(placePurchaseLast);
  document.querySelectorAll('.olr-product-information--accordion').forEach(initAccordions);
}());
