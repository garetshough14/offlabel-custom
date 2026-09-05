(function () {
  'use strict';
  var money = window.olrOfferMoney || {symbol: '$', decimals: 2, decimal: '.', thousand: ',', format: '%1$s%2$s'};
  var decoder = document.createElement('textarea');
  var privateQuotes = {};
  decoder.innerHTML = money.symbol;
  function price(value) {
    var parts = Number(value).toFixed(Number(money.decimals)).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, money.thousand);
    return money.format.replace('%1$s', decoder.value).replace('%2$s', parts.join(money.decimal));
  }
  function text(tag, className, content) {
    var node = document.createElement(tag);
    node.className = className;
    node.textContent = content;
    return node;
  }
  function init(root) {
    var selector = root.querySelector('[data-olr-offer]');
    if (!selector || selector.dataset.offerReady) return;
    selector.dataset.offerReady = 'yes';
    var initial;
    try { initial = JSON.parse(selector.dataset.olrOffer); } catch (e) { return; }
    var data = initial;
    var queued = false;
    var observer = new MutationObserver(schedule);
    function schedule() {
      if (queued) return;
      queued = true;
      Promise.resolve().then(function () { queued = false; render(); });
    }
    function render() {
      observer.disconnect();
      selector.classList.add('olr-volume-offers');
      selector.querySelectorAll('[data-olr-quantity]').forEach(function (button) {
        var qty = Number(button.dataset.olrQuantity);
        var rate = 0;
        if (data.enabled) data.tiers.slice().sort(function (a, b) { return a.quantity - b.quantity; }).forEach(function (tier) {
          if (qty >= tier.quantity) rate = tier.percent;
        });
        var unit = Math.min(data.current, data.regular * (1 - rate / 100));
        var effective = data.regular > 0 ? Math.max(0, 100 * (1 - unit / data.regular)) : 0;
        button.replaceChildren(text('span', 'olr-tier-title', qty + (qty === 1 ? ' BOTTLE' : ' BOTTLES')));
        // Keep the badge in normal flow, with an equal-height slot on every card.
        // The storefront theme can override the card's display/padding rules.
        var bestValue = !data.variable && qty === 10 && effective > 0;
        var badge = text('span', 'olr-tier-badge-slot' + (bestValue ? ' olr-tier-badge' : ''), bestValue ? 'BEST VALUE' : '\u00a0');
        if (!bestValue) badge.setAttribute('aria-hidden', 'true');
        button.append(badge);
        if (data.variable) {
          button.append(text('strong', 'olr-tier-total', 'SELECT STRENGTH'));
          return;
        }
        // First strong remains the total: the existing bridge may update it; the observer restores this quote.
        button.append(text('strong', 'olr-tier-total', price(unit * qty) + ' TOTAL'));
        if (effective > 0) {
          button.append(text('span', 'olr-tier-saving', 'SAVE ' + Number(effective.toFixed(2)) + '%'));
          button.append(text('del', 'olr-tier-regular', price(data.regular * qty)));
        } else {
          button.append(text('span', 'olr-tier-saving', 'REGULAR PRICE'));
          var regularSlot = text('span', 'olr-tier-regular', '\u00a0');
          regularSlot.setAttribute('aria-hidden', 'true');
          button.append(regularSlot);
        }
        button.append(text('span', 'olr-tier-unit', price(unit) + ' / BOTTLE'));
      });
      if (!selector.querySelector('.olr-volume-policy')) selector.append(text('p', 'olr-volume-policy', 'Volume savings applied automatically. Offers cannot be combined. Highest eligible discount applies.'));
      observer.observe(selector, {subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['data-unit-price']});
    }
    var form = root.querySelector('form.variations_form');
    if (form && window.jQuery) {
      window.jQuery(form).on('found_variation.olrOffer', function (event, variation) {
        data = variation.olr_offer || privateQuotes[variation.variation_id] || initial;
        schedule();
      }).on('reset_data.olrOffer hide_variation.olrOffer', function () { data = initial; schedule(); });
      // Private authorization can finish after WooCommerce's first found_variation.
      // Recheck the current attributes through WooCommerce, without selecting a
      // strength or deriving quotes from the displayed price / active swatch.
      window.jQuery(form).trigger('check_variations');
    }
    render();
  }
  function start() { document.querySelectorAll('.olr-product-view').forEach(init); }
  function ready() {
    if (!window.olrOfferPreview) { start(); return; }
    // A cached script/config alone can never turn on private quotes: require fresh server authorization.
    var body = new URLSearchParams({action: 'olr_private_pricing_quotes', nonce: window.olrOfferPreview.nonce});
    document.querySelectorAll('[id^="olr-product-title-"]').forEach(function (heading) {
      var match = heading.id.match(/^olr-product-title-(\d+)$/);
      if (match) body.append('ids[]', match[1]);
    });
    fetch(window.olrOfferPreview.url, {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body})
      .then(function (response) { if (!response.ok) throw new Error('Preview authorization failed'); return response.json(); })
      .then(function (result) {
        if (!result.success || !result.data || !result.data.quotes) return;
        privateQuotes = result.data.quotes;
        document.querySelectorAll('.olr-product-view').forEach(function (root) {
          var heading = root.querySelector('[id^="olr-product-title-"]');
          var selector = root.querySelector('[data-olr-volume-pricing]');
          var match = heading && heading.id.match(/^olr-product-title-(\d+)$/);
          if (selector && match && privateQuotes[match[1]]) selector.dataset.olrOffer = JSON.stringify(privateQuotes[match[1]]);
        });
        var banner = text('div', 'olr-private-preview-banner', result.data.message + ' ');
        banner.setAttribute('role', 'status');
        var link = text('a', '', 'End preview');
        var url = new URL(result.data.endUrl, location.href);
        if (url.origin === location.origin) { link.href = url.href; banner.append(link); }
        document.body.prepend(banner);
        // The server also blocks checkout; this only makes the disabled state visible.
        document.querySelectorAll('#place_order, [name="woocommerce_checkout_place_order"], [name="woocommerce_pay"]').forEach(function (button) { button.disabled = true; button.textContent = 'Ordering disabled in preview'; });
        start();
      }).catch(function () { /* Fail closed: leave the normal storefront and prices unchanged. */ });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
}());
