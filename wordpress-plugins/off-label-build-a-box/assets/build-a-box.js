(function () {
  'use strict';

  var roots = document.querySelectorAll('[data-olr-build-box]');
  if (!roots.length) return;

  document.body.classList.add('olr-build-box-page');

  roots.forEach(function (root) {
    var config = window.olrBuildBox || {};
    var state = new Map();
    var tier = parseInt(root.dataset.tier || '5', 10) === 10 ? 10 : 5;
    var editBoxId = root.dataset.editBox || '';
    var cards = Array.prototype.slice.call(root.querySelectorAll('[data-product-card]'));
    var submit = root.querySelector('[data-submit-box]');
    var alertBox = root.querySelector('[data-builder-alert]');
    var summary = root.querySelector('[data-summary-items]');
    var isSaving = false;

    function currentDiscount() {
      return tier === 10 ? 30 : 25;
    }

    function keyFor(productId, variationId) {
      return String(productId) + ':' + String(variationId || 0);
    }

    function countItems() {
      var count = 0;
      state.forEach(function (item) { count += item.quantity; });
      return count;
    }

    function subtotal() {
      var amount = 0;
      state.forEach(function (item) { amount += item.regularPrice * item.quantity; });
      return amount;
    }

    function formatMoney(amount) {
      var decimals = Number.isInteger(parseInt(config.decimals, 10)) ? parseInt(config.decimals, 10) : 2;
      var decimalSeparator = config.decimalSeparator || '.';
      var thousandSeparator = config.thousandSeparator || ',';
      var symbol = config.currencySymbol || '$';
      var parts = Number(amount || 0).toFixed(decimals).split('.');
      parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSeparator);
      var number = parts[0] + (decimals ? decimalSeparator + parts[1] : '');
      var format = config.currencyPosition || '%1$s%2$s';
      return format.replace('%1$s', symbol).replace('%2$s', number);
    }

    function showAlert(message) {
      if (!alertBox) return;
      alertBox.textContent = message || '';
      alertBox.hidden = !message;
      if (message) alertBox.focus({ preventScroll: true });
    }

    function selectedCardData(card) {
      if (!card) return null;
      var productId = parseInt(card.dataset.productId || '0', 10);
      var variationId = 0;
      var name = card.dataset.productName || '';
      var image = card.dataset.productImage || '';
      var regularPrice = parseFloat(card.dataset.regularPrice || '0');
      if (card.dataset.productType === 'variable') {
        var select = card.querySelector('[data-variation-select]');
        var option = select && select.options[select.selectedIndex];
        variationId = select ? parseInt(select.value || '0', 10) : 0;
        if (!variationId || !option) return null;
        regularPrice = parseFloat(option.dataset.price || '0');
        image = option.dataset.image || image;
        name += option.dataset.name ? ' — ' + option.dataset.name : '';
      }
      if (!productId || !regularPrice) return null;
      return {
        key: keyFor(productId, variationId),
        productId: productId,
        variationId: variationId,
        name: name,
        image: image,
        regularPrice: regularPrice
      };
    }

    function updateQuantity(card, change) {
      var selected = selectedCardData(card);
      if (!selected) {
        showAlert((config.messages && config.messages.variationNeeded) || 'Choose an option before adding this bottle.');
        return;
      }
      var existing = state.get(selected.key);
      var next = Math.max(0, (existing ? existing.quantity : 0) + change);
      var totalWithout = countItems() - (existing ? existing.quantity : 0);
      if (totalWithout + next > tier) {
        showAlert((config.messages && config.messages.boxFull) || 'Your selected box is already full.');
        return;
      }
      if (next === 0) {
        state.delete(selected.key);
      } else {
        selected.quantity = next;
        state.set(selected.key, selected);
      }
      showAlert('');
      render();
    }

    function makeSummaryItem(item, index) {
      var li = document.createElement('li');
      li.className = 'olr-build-box__summary-item';
      var image = document.createElement('img');
      image.src = item.image;
      image.alt = '';
      image.loading = 'lazy';
      var number = document.createElement('span');
      number.className = 'olr-build-box__summary-number';
      number.textContent = String(index + 1).padStart(2, '0');
      var copy = document.createElement('span');
      copy.className = 'olr-build-box__summary-copy';
      var name = document.createElement('strong');
      name.textContent = item.name;
      var quantity = document.createElement('small');
      quantity.textContent = '× ' + item.quantity;
      copy.appendChild(name);
      copy.appendChild(quantity);
      var price = document.createElement('span');
      price.className = 'olr-build-box__summary-price';
      price.textContent = formatMoney(item.regularPrice * item.quantity);
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.dataset.summaryRemove = item.key;
      remove.setAttribute('aria-label', 'Remove one ' + item.name);
      remove.textContent = '×';
      li.appendChild(image);
      li.appendChild(number);
      li.appendChild(copy);
      li.appendChild(price);
      li.appendChild(remove);
      return li;
    }

    function render() {
      var count = countItems();
      var amount = subtotal();
      var savings = amount * (currentDiscount() / 100);
      var complete = count === tier;

      root.dataset.tier = String(tier);
      root.querySelectorAll('[data-box-count]').forEach(function (node) { node.textContent = String(count); });
      root.querySelectorAll('[data-box-limit], [data-tier-copy]').forEach(function (node) { node.textContent = String(tier); });
      root.querySelectorAll('[data-discount-copy]').forEach(function (node) { node.textContent = String(currentDiscount()); });
      root.querySelectorAll('[data-total-items]').forEach(function (node) { node.textContent = String(count); });
      root.querySelectorAll('[data-subtotal]').forEach(function (node) { node.textContent = formatMoney(amount); });
      root.querySelectorAll('[data-savings]').forEach(function (node) { node.textContent = '−' + formatMoney(savings); });
      root.querySelectorAll('[data-total]').forEach(function (node) { node.textContent = formatMoney(amount - savings); });

      root.querySelectorAll('[data-box-tier]').forEach(function (input) {
        input.checked = parseInt(input.value, 10) === tier;
        var card = input.closest('.olr-build-box__tier');
        if (card) card.classList.toggle('is-selected', input.checked);
      });

      var dots = root.querySelector('[data-progress-dots]');
      if (dots) {
        dots.innerHTML = '';
        for (var dotIndex = 0; dotIndex < tier; dotIndex += 1) {
          var dot = document.createElement('span');
          dot.className = dotIndex < count ? 'is-filled' : '';
          dots.appendChild(dot);
        }
      }

      cards.forEach(function (card) {
        var selected = selectedCardData(card);
        var quantity = selected && state.has(selected.key) ? state.get(selected.key).quantity : 0;
        var output = card.querySelector('[data-product-quantity]');
        if (output) output.textContent = String(quantity);
        card.classList.toggle('has-selection', quantity > 0);
      });

      if (summary) {
        summary.innerHTML = '';
        var index = 0;
        state.forEach(function (item) {
          summary.appendChild(makeSummaryItem(item, index));
          index += 1;
        });
        var remainingSlots = Math.max(0, tier - count);
        for (var slot = 0; slot < remainingSlots; slot += 1) {
          var empty = document.createElement('li');
          empty.className = 'olr-build-box__summary-empty';
          empty.innerHTML = '<span>' + String(index + slot + 1).padStart(2, '0') + '</span><i></i>';
          summary.appendChild(empty);
        }
      }

      var remaining = Math.max(0, tier - count);
      root.querySelectorAll('[data-remaining-count]').forEach(function (node) { node.textContent = String(remaining); });
      root.querySelectorAll('[data-remaining-label]').forEach(function (node) { node.textContent = remaining === 1 ? 'bottle' : 'bottles'; });
      var unlock = root.querySelector('[data-unlock-message]');
      if (unlock) {
        unlock.classList.toggle('is-unlocked', complete);
        unlock.querySelector('p').innerHTML = complete
          ? '<strong>Box complete.</strong><br>Your ' + currentDiscount() + '% discount is unlocked.'
          : 'Add <strong data-remaining-count>' + remaining + '</strong> more <span data-remaining-label>' + (remaining === 1 ? 'bottle' : 'bottles') + '</span> to<br><b>unlock ' + currentDiscount() + '% off</b>';
      }

      if (submit) {
        submit.disabled = !complete || isSaving;
        submit.setAttribute('aria-disabled', submit.disabled ? 'true' : 'false');
      }
    }

    function submitBox() {
      if (!submit || submit.disabled || isSaving) return;
      isSaving = true;
      render();
      showAlert('');
      var payload = [];
      state.forEach(function (item) {
        payload.push({ product_id: item.productId, variation_id: item.variationId, quantity: item.quantity });
      });
      var body = new FormData();
      body.append('action', 'olr_save_box');
      body.append('nonce', config.nonce || '');
      body.append('tier', String(tier));
      body.append('box_id', editBoxId);
      body.append('items', JSON.stringify(payload));
      fetch(config.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (response) {
          if (!response || !response.success) {
            throw new Error(response && response.data && response.data.message ? response.data.message : ((config.messages && config.messages.genericError) || 'Your box could not be updated.'));
          }
          window.location.assign((response.data && response.data.cartUrl) || config.cartUrl || '/cart/');
        })
        .catch(function (error) {
          isSaving = false;
          showAlert(error.message || ((config.messages && config.messages.genericError) || 'Your box could not be updated.'));
          render();
        });
    }

    root.addEventListener('click', function (event) {
      var changeButton = event.target.closest('[data-quantity-change]');
      if (changeButton && root.contains(changeButton)) {
        updateQuantity(changeButton.closest('[data-product-card]'), parseInt(changeButton.dataset.quantityChange || '0', 10));
        return;
      }
      var addButton = event.target.closest('[data-add-product]');
      if (addButton && root.contains(addButton)) {
        updateQuantity(addButton.closest('[data-product-card]'), 1);
        return;
      }
      var removeButton = event.target.closest('[data-summary-remove]');
      if (removeButton && root.contains(removeButton)) {
        var item = state.get(removeButton.dataset.summaryRemove);
        if (item) {
          item.quantity -= 1;
          if (item.quantity <= 0) state.delete(item.key);
          else state.set(item.key, item);
          showAlert('');
          render();
        }
        return;
      }
      if (event.target.closest('[data-view-all]')) {
        var expanded = root.classList.toggle('is-showing-all');
        root.querySelectorAll('[data-view-all]').forEach(function (button) {
          if (button.classList.contains('olr-build-box__browse')) button.firstChild.nodeValue = expanded ? 'Show fewer bottles ' : 'Browse all bottles ';
          button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
        return;
      }
      if (event.target.closest('[data-start-over]')) {
        state.clear();
        showAlert('');
        render();
        return;
      }
      if (event.target.closest('[data-submit-box]')) submitBox();
    });

    root.addEventListener('change', function (event) {
      if (event.target.matches('[data-box-tier]')) {
        var requested = parseInt(event.target.value || '5', 10) === 10 ? 10 : 5;
        if (countItems() > requested) {
          showAlert((config.messages && config.messages.tierTooSmall) || 'Remove bottles before switching to the smaller box.');
          render();
          return;
        }
        tier = requested;
        showAlert('');
        render();
        return;
      }
      if (event.target.matches('[data-variation-select]')) {
        var card = event.target.closest('[data-product-card]');
        var option = event.target.options[event.target.selectedIndex];
        if (card && option) {
          var image = card.querySelector('.olr-build-box__product-media img');
          if (image) {
            image.src = option.dataset.image || card.dataset.productImage || image.src;
            var responsiveSources = option.dataset.imageSrcset || card.dataset.productImageSrcset || '';
            if (responsiveSources) image.srcset = responsiveSources;
            else image.removeAttribute('srcset');
            image.sizes = '(max-width: 360px) 100vw, (max-width: 768px) 50vw, (max-width: 1100px) 33vw, 25vw';
          }
        }
        render();
      }
    });

    var initialScript = root.querySelector('[data-initial-box-state]');
    if (initialScript) {
      try {
        var initialItems = JSON.parse(initialScript.textContent || '[]');
        initialItems.forEach(function (item) {
          var productId = parseInt(item.product_id || '0', 10);
          var variationId = parseInt(item.variation_id || '0', 10);
          var quantity = parseInt(item.quantity || '0', 10);
          if (!productId || !quantity) return;
          state.set(keyFor(productId, variationId), {
            key: keyFor(productId, variationId),
            productId: productId,
            variationId: variationId,
            quantity: quantity,
            name: item.name || '',
            image: item.image || '',
            regularPrice: parseFloat(item.regular_price || '0')
          });
          var card = root.querySelector('[data-product-card][data-product-id="' + productId + '"]');
          var select = card && card.querySelector('[data-variation-select]');
          if (select && variationId) select.value = String(variationId);
        });
      } catch (error) {
        state.clear();
      }
    }

    render();
  });
})();
