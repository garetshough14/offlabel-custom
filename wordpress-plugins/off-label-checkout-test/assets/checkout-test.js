(function ($) {
  'use strict';

  var root = document.querySelector('[data-olr-page="checkout-test"].olr-checkout-test');
  if (!root) return;

  var stage = 'information';
  var stages = ['information', 'shipping', 'payment'];
  var status = root.querySelector('.olr-checkout-test__status');
  var form;

  root.classList.add('olr-checkout-enhanced');

  function announce(message) {
    if (!status) return;
    status.textContent = '';
    window.setTimeout(function () {
      status.textContent = message;
    }, 30);
  }

  function fieldIsVisible(field) {
    var row = field.closest('.form-row');
    var target = row || field;
    return !!(target.offsetWidth || target.offsetHeight || target.getClientRects().length);
  }

  function clearFieldError(field) {
    var row = field.closest('.form-row');
    var id = field.id ? field.id + '-olr-error' : '';
    if (row) row.classList.remove('woocommerce-invalid');
    field.removeAttribute('aria-invalid');
    if (id) {
      var error = document.getElementById(id);
      if (error) error.remove();
      var describedBy = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (value) { return value && value !== id; });
      if (describedBy.length) field.setAttribute('aria-describedby', describedBy.join(' '));
      else field.removeAttribute('aria-describedby');
    }
  }

  function setFieldError(field) {
    var row = field.closest('.form-row');
    if (!row || !field.id) return;
    var id = field.id + '-olr-error';
    row.classList.add('woocommerce-invalid');
    field.setAttribute('aria-invalid', 'true');
    var describedBy = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
    if (describedBy.indexOf(id) === -1) describedBy.push(id);
    field.setAttribute('aria-describedby', describedBy.join(' '));
    if (!document.getElementById(id)) {
      var error = document.createElement('span');
      error.id = id;
      error.className = 'olr-field-error';
      error.textContent = field.type === 'email' ? 'Enter a valid email address.' : 'This field is required.';
      row.appendChild(error);
    }
  }

  function fieldsForStage(targetStage) {
    if (!form) return [];
    if (targetStage === 'information') {
      return Array.prototype.slice.call(form.querySelectorAll('#billing_email'));
    }
    if (targetStage === 'shipping') {
      return Array.prototype.slice.call(form.querySelectorAll('#customer_details .validate-required input, #customer_details .validate-required select, #customer_details .validate-required textarea')).filter(function (field) {
        return field.id !== 'billing_email' && fieldIsVisible(field) && !field.disabled;
      });
    }
    return [];
  }

  function validateStage(targetStage) {
    var fields = fieldsForStage(targetStage);
    var firstInvalid = null;
    fields.forEach(function (field) {
      clearFieldError(field);
      var valid = field.type === 'checkbox' ? field.checked : field.value.trim() !== '';
      if (valid && field.type === 'email') valid = field.checkValidity();
      if (!valid) {
        setFieldError(field);
        if (!firstInvalid) firstInvalid = field;
      }
    });
    if (firstInvalid) {
      announce((window.olrCheckoutTest && olrCheckoutTest.requiredError) || 'Complete the required fields before continuing.');
      firstInvalid.focus();
      return false;
    }
    return true;
  }

  function setStage(nextStage, validateCurrent) {
    var currentIndex = stages.indexOf(stage);
    var nextIndex = stages.indexOf(nextStage);
    if (nextIndex < 0) return;
    if (validateCurrent && nextIndex > currentIndex && !validateStage(stage)) return;

    stage = nextStage;
    root.setAttribute('data-olr-checkout-stage', stage);
    updateSectionHeading();
    root.querySelectorAll('[data-olr-stage-target]').forEach(function (button) {
      var buttonStage = button.getAttribute('data-olr-stage-target');
      var buttonIndex = stages.indexOf(buttonStage);
      button.removeAttribute('aria-current');
      if (buttonIndex <= nextIndex) button.disabled = false;
      if (buttonStage === stage) button.setAttribute('aria-current', 'step');
    });

    refreshNavigation();
    var labels = window.olrCheckoutTest && olrCheckoutTest.stageLabels;
    announce((labels && labels[stage] ? labels[stage] : stage) + ' step');
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    root.querySelector('.olr-checkout-test__steps').scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' });
  }

  function navigationMarkup() {
    var backLabel = stage === 'information' ? 'Return to home' : 'Back';
    var nextLabel = stage === 'information' ? 'Continue to shipping' : 'Continue to payment';
    var back = stage === 'information'
      ? '<a href="' + ((window.olrCheckoutTest && olrCheckoutTest.homeUrl) || '/') + '">← ' + backLabel + '</a>'
      : '<button type="button" class="olr-checkout-back">← ' + backLabel + '</button>';
    var next = stage === 'payment' ? '' : '<button type="button" class="olr-checkout-next">' + nextLabel + ' →</button>';
    return back + next;
  }

  function refreshNavigation() {
    if (!form) return;
    var navigation = form.querySelector('.olr-checkout-navigation');
    if (!navigation) {
      navigation = document.createElement('div');
      navigation.className = 'olr-checkout-navigation';
      form.querySelector('#customer_details').appendChild(navigation);
    }
    navigation.innerHTML = navigationMarkup();
    var back = navigation.querySelector('.olr-checkout-back');
    var next = navigation.querySelector('.olr-checkout-next');
    if (back) back.addEventListener('click', function () { setStage(stages[Math.max(0, stages.indexOf(stage) - 1)], false); });
    if (next) next.addEventListener('click', function () { setStage(stages[stages.indexOf(stage) + 1], true); });
  }

  function addBenefits() {
    if (!form || form.querySelector('.olr-checkout-test__benefits')) return;
    var benefits = document.createElement('div');
    benefits.className = 'olr-checkout-test__benefits';
    benefits.innerHTML = '<div><strong>Fast shipping</strong><span>Orders ship quickly from our facility.</span></div><div><strong>Tracking provided</strong><span>Track your order every step of the way.</span></div><div><strong>Secure checkout</strong><span>Your information is always protected.</span></div>';
    form.querySelector('#customer_details').insertBefore(benefits, form.querySelector('.olr-checkout-navigation'));
  }

  function normalizeConsentRows() {
    if (!form) return;
    form.querySelectorAll('#customer_details input[type="checkbox"]').forEach(function (input) {
      var row = input.closest('.form-row') || input.closest('p');
      if (row) row.classList.add('olr-checkout-consent-field');
    });
  }

  function setCartCount(count) {
    document.querySelectorAll('.olr-cart-count').forEach(function (counter) {
      counter.textContent = String(count);
      counter.setAttribute('aria-label', count + (count === 1 ? ' item in bag' : ' items in bag'));
    });
  }

  function updateCartItem(cartItemKey, quantity, control) {
    if (!window.olrCheckoutTest || !olrCheckoutTest.ajaxUrl || !olrCheckoutTest.cartNonce || root.hasAttribute('data-olr-cart-updating')) return;

    root.setAttribute('data-olr-cart-updating', 'true');
    root.setAttribute('aria-busy', 'true');
    control.setAttribute('aria-busy', 'true');
    control.querySelectorAll('button').forEach(function (button) {
      button.setAttribute('data-olr-was-disabled', button.disabled ? 'true' : 'false');
      button.disabled = true;
    });
    announce(quantity === 0 ? 'Removing product from your bag.' : 'Updating product quantity.');

    $.ajax({
      type: 'POST',
      url: olrCheckoutTest.ajaxUrl,
      dataType: 'json',
      data: {
        action: 'olr_checkout_test_update_cart',
        nonce: olrCheckoutTest.cartNonce,
        cart_item_key: cartItemKey,
        quantity: quantity
      }
    }).done(function (response) {
      if (!response || !response.success || !response.data) {
        var errorMessage = response && response.data && response.data.message ? response.data.message : olrCheckoutTest.cartUpdateError;
        announce(errorMessage || 'Your bag could not be updated. Please try again.');
        return;
      }

      setCartCount(Number(response.data.cartCount) || 0);
      if (response.data.cartEmpty) {
        window.location.reload();
        return;
      }

      announce('Your bag has been updated.');
      $(document.body).trigger('update_checkout', { update_shipping_method: false });
    }).fail(function (xhr) {
      var response = xhr && xhr.responseJSON;
      var errorMessage = response && response.data && response.data.message ? response.data.message : olrCheckoutTest.cartUpdateError;
      announce(errorMessage || 'Your bag could not be updated. Please try again.');
    }).always(function () {
      root.removeAttribute('data-olr-cart-updating');
      root.removeAttribute('aria-busy');
      control.removeAttribute('aria-busy');
      control.querySelectorAll('button').forEach(function (button) {
        button.disabled = button.getAttribute('data-olr-was-disabled') === 'true';
        button.removeAttribute('data-olr-was-disabled');
      });
    });
  }

  function updateSectionHeading() {
    if (!form) return;
    var heading = form.querySelector('.woocommerce-billing-fields > h3');
    if (!heading) return;
    heading.textContent = stage === 'information' ? 'Contact' : (stage === 'shipping' ? 'Shipping address' : 'Order information');
  }

  function moveShippingMethods() {
    if (!form) return;
    var customer = form.querySelector('#customer_details');
    var row = form.querySelector('#order_review .woocommerce-shipping-totals');
    var methods = row && row.querySelector('.woocommerce-shipping-methods');
    var slot = customer.querySelector('.olr-shipping-methods');
    if (!slot) {
      slot = document.createElement('section');
      slot.className = 'olr-shipping-methods';
      slot.innerHTML = '<h3 class="olr-shipping-methods__title">Shipping method</h3><div class="olr-shipping-methods__body"></div>';
      customer.insertBefore(slot, customer.querySelector('.olr-checkout-test__benefits'));
    }
    if (methods) {
      row.classList.add('is-moved');
      slot.querySelector('.olr-shipping-methods__body').innerHTML = '';
      slot.querySelector('.olr-shipping-methods__body').appendChild(methods);
    } else if (!slot.querySelector('.woocommerce-shipping-methods')) {
      slot.querySelector('.olr-shipping-methods__body').innerHTML = '<p>Enter your address to see available shipping methods.</p>';
    }
  }

  function parseCouponResponse(response) {
    var markup = typeof response === 'string' ? response.trim() : '';
    var container = document.createElement('div');
    var source;

    for (var pass = 0; pass < 2; pass += 1) {
      container.innerHTML = markup;
      source = container.querySelector('.woocommerce-error, .woocommerce-message, .woocommerce-info');
      if (source) break;
      var decoded = container.textContent.trim();
      if (!decoded || decoded === markup || decoded.indexOf('<') === -1) break;
      markup = decoded;
    }

    var message = (source ? source.textContent : container.textContent).replace(/\s+/g, ' ').trim();
    var success = !!(source && source.classList.contains('woocommerce-message'));
    var notice = document.createElement('div');
    notice.className = success ? 'woocommerce-message' : 'woocommerce-error';
    notice.setAttribute('role', success ? 'status' : 'alert');
    notice.textContent = message || (success ? 'Coupon code applied.' : 'That coupon could not be applied.');

    return { element: notice, message: notice.textContent, success: success };
  }

  function addPromo() {
    if (!form) return;
    var review = form.querySelector('#order_review');
    if (!review || review.querySelector('.olr-checkout-test__promo')) return;
    var promo = document.createElement('div');
    promo.className = 'olr-checkout-test__promo';
    promo.innerHTML = '<label for="olr-checkout-coupon">Promo code</label><div><input id="olr-checkout-coupon" type="text" autocomplete="off" placeholder="Enter code"><button type="button">Apply</button></div>';
    var payment = review.querySelector('#payment');
    review.insertBefore(promo, payment || null);
    promo.querySelector('button').addEventListener('click', function () {
      var input = promo.querySelector('input');
      var button = promo.querySelector('button');
      var billingEmail = form.querySelector('#billing_email');
      var code = input.value.trim();
      if (!code || !window.wc_checkout_params) return;
      promo.setAttribute('aria-busy', 'true');
      button.disabled = true;
      $.ajax({
        type: 'POST',
        url: wc_checkout_params.wc_ajax_url.toString().replace('%%endpoint%%', 'apply_coupon'),
        dataType: 'html',
        data: { security: wc_checkout_params.apply_coupon_nonce, coupon_code: code, billing_email: billingEmail ? billingEmail.value : '' },
        success: function (response) {
          root.querySelectorAll('.woocommerce-error, .woocommerce-message, .woocommerce-info').forEach(function (notice) { notice.remove(); });
          var result = parseCouponResponse(response);
          form.before(result.element);
          announce(result.message);
          if (result.success) {
            input.value = '';
            $(document.body).trigger('applied_coupon_in_checkout', [code]);
            $(document.body).trigger('update_checkout', { update_shipping_method: false });
          }
        },
        error: function () {
          root.querySelectorAll('.woocommerce-error, .woocommerce-message, .woocommerce-info').forEach(function (notice) { notice.remove(); });
          var notice = document.createElement('div');
          notice.className = 'woocommerce-error';
          notice.setAttribute('role', 'alert');
          notice.textContent = 'That coupon could not be applied. Please try again.';
          form.before(notice);
          announce(notice.textContent);
        },
        complete: function () {
          promo.removeAttribute('aria-busy');
          button.disabled = false;
        }
      });
    });
  }

  function addSecureNotice() {
    if (!form) return;
    var review = form.querySelector('#order_review');
    if (!review || review.querySelector('.olr-checkout-test__secure')) return;
    var notice = document.createElement('p');
    notice.className = 'olr-checkout-test__secure';
    notice.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="1"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg><span>Testing checkout. No payment details are collected or charged.</span>';
    review.appendChild(notice);
  }

  function prepare() {
    form = root.querySelector('form.checkout');
    if (!form) return;
    form.setAttribute('data-olr-enhanced', 'true');
    refreshNavigation();
    addBenefits();
    normalizeConsentRows();
    updateSectionHeading();
    moveShippingMethods();
    addPromo();
    addSecureNotice();
  }

  root.querySelectorAll('[data-olr-stage-target]').forEach(function (button) {
    button.addEventListener('click', function () {
      var nextStage = button.getAttribute('data-olr-stage-target');
      setStage(nextStage, stages.indexOf(nextStage) > stages.indexOf(stage));
    });
  });

  root.addEventListener('click', function (event) {
    var quantityButton = event.target.closest('[data-olr-quantity-change]');
    var removeButton = event.target.closest('[data-olr-remove-item]');
    var button = quantityButton || removeButton;
    if (!button || !root.contains(button)) return;

    var control = button.closest('[data-olr-cart-item]') || button.parentElement;
    var cartItemKey = quantityButton
      ? control.getAttribute('data-olr-cart-item')
      : removeButton.getAttribute('data-olr-remove-item');
    var quantityControl = quantityButton ? control : removeButton.previousElementSibling;
    var currentQuantity = Number(quantityControl && quantityControl.getAttribute('data-quantity')) || 1;
    var nextQuantity = removeButton ? 0 : Math.max(0, currentQuantity + Number(quantityButton.getAttribute('data-olr-quantity-change')));

    event.preventDefault();
    updateCartItem(cartItemKey, nextQuantity, quantityButton ? control.parentElement : removeButton.parentElement);
  });

  $(document.body).on('updated_checkout', function () {
    prepare();
    root.setAttribute('data-olr-checkout-stage', stage);
    announce('Order totals updated.');
  });

  $(document.body).on('checkout_error', function () {
    var error = root.querySelector('.woocommerce-error');
    if (error) {
      error.setAttribute('role', 'alert');
      error.setAttribute('tabindex', '-1');
      error.focus();
    }
  });

  prepare();
})(jQuery);
