(function () {
  'use strict';

  function emitChange(input) {
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function initGallery(root) {
    var stageImage = root.querySelector('[data-olr-gallery-stage] img');
    if (!stageImage) return;
    root.querySelectorAll('[data-olr-gallery-thumb]').forEach(function (button) {
      button.addEventListener('click', function () {
        root.querySelectorAll('[data-olr-gallery-thumb]').forEach(function (item) {
          var active = item === button;
          item.classList.toggle('is-active', active);
          item.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        stageImage.src = button.dataset.fullSrc;
        stageImage.removeAttribute('srcset');
      });
    });
  }

  function initVariationControl(root) {
    var control = root.querySelector('[data-olr-variation-control]');
    var form = root.querySelector('form.variations_form');
    if (!control || !form) return;

    var attributeName = control.dataset.attributeName;
    var select = form.querySelector('select[name="' + attributeName + '"]');
    if (!select) return;

    var variationTable = select.closest('table.variations');
    if (variationTable && form.querySelectorAll('table.variations select').length > 1) {
      variationTable.classList.add('olr-secondary-variations');
      var primaryRow = select.closest('tr');
      if (primaryRow) primaryRow.classList.add('olr-primary-variation-row');
    }

    var buttons = Array.from(control.querySelectorAll('[data-olr-variation-value]'));

    function syncButtons() {
      buttons.forEach(function (button) {
        var option = Array.from(select.options).find(function (item) {
          return item.value === button.dataset.olrVariationValue;
        });
        var active = select.value === button.dataset.olrVariationValue;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        button.disabled = !option || option.disabled;
      });
    }

    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        select.value = button.dataset.olrVariationValue;
        emitChange(select);
        syncButtons();
      });
    });

    select.addEventListener('change', syncButtons);
    if (!select.value) {
      var first = buttons.find(function (button) { return !button.disabled; });
      if (first) first.click();
    } else {
      syncButtons();
    }
  }

  function clampQuantity(input, requested) {
    var min = parseFloat(input.min || '1');
    var max = parseFloat(input.max || '');
    var step = parseFloat(input.step || '1');
    var value = Math.max(min, requested);
    if (!Number.isNaN(max)) value = Math.min(max, value);
    if (step > 0) value = min + Math.round((value - min) / step) * step;
    return value;
  }

  function initQuantity(root) {
    var input = root.querySelector('form.cart input.qty');
    if (!input || input.closest('.olr-quantity-stepper')) return;

    var stepper = document.createElement('div');
    stepper.className = 'olr-quantity-stepper';
    stepper.setAttribute('aria-label', 'Quantity');
    var minus = document.createElement('button');
    var plus = document.createElement('button');
    minus.type = plus.type = 'button';
    minus.className = 'olr-quantity-stepper__minus';
    plus.className = 'olr-quantity-stepper__plus';
    minus.setAttribute('aria-label', 'Decrease quantity');
    plus.setAttribute('aria-label', 'Increase quantity');
    minus.textContent = '−';
    plus.textContent = '+';
    input.parentNode.insertBefore(stepper, input);
    stepper.appendChild(minus);
    stepper.appendChild(input);
    stepper.appendChild(plus);

    function changeBy(direction) {
      var step = parseFloat(input.step || '1');
      var current = parseFloat(input.value || input.min || '1');
      input.value = clampQuantity(input, current + direction * step);
      emitChange(input);
    }

    minus.addEventListener('click', function () { changeBy(-1); });
    plus.addEventListener('click', function () { changeBy(1); });

    root.querySelectorAll('[data-olr-quantity]').forEach(function (button) {
      button.addEventListener('click', function () {
        input.value = clampQuantity(input, parseFloat(button.dataset.olrQuantity));
        emitChange(input);
        root.querySelectorAll('[data-olr-quantity]').forEach(function (item) {
          var active = item === button;
          item.classList.toggle('is-active', active);
          item.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
      });
    });

    var first = root.querySelector('[data-olr-quantity="1"]');
    if (first) {
      first.classList.add('is-active');
      first.setAttribute('aria-pressed', 'true');
    }
  }

  function formatPrice(value) {
    var params = window.wc_price_params || window.woocommerce_price_slider_params || {};
    var decimals = Number.isFinite(parseInt(params.num_decimals, 10)) ? parseInt(params.num_decimals, 10) : 2;
    var symbol = params.currency_format_symbol || '$';
    var amount = Number(value).toFixed(decimals);
    return symbol + amount;
  }

  function updateVolumePrices(root, unitPrice) {
    if (!Number.isFinite(unitPrice)) return;
    var volume = root.querySelector('[data-olr-volume-pricing]');
    if (volume) volume.dataset.unitPrice = String(unitPrice);
    root.querySelectorAll('[data-olr-quantity]').forEach(function (button) {
      var total = button.querySelector('strong');
      if (total) total.textContent = formatPrice(unitPrice * parseInt(button.dataset.olrQuantity, 10));
    });
  }

  function syncVariation(root) {
    var form = root.querySelector('form.variations_form');
    if (!form || !window.jQuery) return;
    window.jQuery(form)
      .on('found_variation', function (_event, variation) {
        var price = root.querySelector('.olr-product-view__price strong');
        if (price && variation && variation.price_html) price.innerHTML = variation.price_html;
        if (variation && Number.isFinite(Number(variation.display_price))) {
          updateVolumePrices(root, Number(variation.display_price));
        }
      })
      .on('reset_data hide_variation', function () {
        root.querySelectorAll('[data-olr-variation-value]').forEach(function (button) {
          button.classList.remove('is-active');
          button.setAttribute('aria-pressed', 'false');
        });
      });
  }

  function initAccordions(root) {
    var sections = Array.from(root.querySelectorAll('details'));
    sections.forEach(function (details) {
      details.open = false;
      details.addEventListener('toggle', function () {
        if (!details.open) return;
        sections.forEach(function (other) {
          if (other !== details) other.open = false;
        });
      });
    });
  }

  document.querySelectorAll('[data-olr-gallery]').forEach(initGallery);
  document.querySelectorAll('.olr-product-view').forEach(function (root) {
    syncVariation(root);
    initVariationControl(root);
    initQuantity(root);
    var variationForm = root.querySelector('form.variations_form');
    if (variationForm && window.jQuery) window.jQuery(variationForm).trigger('check_variations');
    var button = root.querySelector('.single_add_to_cart_button');
    if (button) button.textContent = 'Add to research  →';
  });
  document.querySelectorAll('.olr-product-information--accordion').forEach(initAccordions);
}());
