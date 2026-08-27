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
    var backLabel = stage === 'information' ? 'Return to bag' : 'Back';
    var nextLabel = stage === 'information' ? 'Continue to shipping' : 'Continue to payment';
    var back = stage === 'information'
      ? '<a href="' + ((window.olrCheckoutTest && olrCheckoutTest.cartUrl) || '/cart/') + '">← ' + backLabel + '</a>'
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

  function normalizeThirdPartyOptIns() {
    if (!form) return;
    form.querySelectorAll('label').forEach(function (label) {
      var copy = label.textContent.replace(/\s+/g, ' ').trim().toLowerCase();
      if (copy.indexOf('exclusive emails') === -1 && copy.indexOf('discounts and product information') === -1) return;
      var row = label.closest('.form-row') || label.closest('p');
      if (row && !row.classList.contains('olr-research-updates-field')) row.style.display = 'none';
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
      var code = input.value.trim();
      if (!code || !window.wc_checkout_params) return;
      promo.setAttribute('aria-busy', 'true');
      $.ajax({
        type: 'POST',
        url: wc_checkout_params.wc_ajax_url.toString().replace('%%endpoint%%', 'apply_coupon'),
        data: { security: wc_checkout_params.apply_coupon_nonce, coupon_code: code },
        success: function (response) {
          $('.woocommerce-error, .woocommerce-message').remove();
          form.before(response);
          $(document.body).trigger('update_checkout', { update_shipping_method: false });
          announce('Promo code response updated.');
        },
        complete: function () { promo.removeAttribute('aria-busy'); }
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
    normalizeThirdPartyOptIns();
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
