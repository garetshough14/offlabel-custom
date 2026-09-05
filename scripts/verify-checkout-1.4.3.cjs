const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const { chromium } = require('C:/Users/User/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = path.resolve(__dirname, '..');
const version = process.argv.includes('--1.4.4') ? '1.4.4' : '1.4.3';
const out = path.join(root, 'output/checkout-update-' + version);
const plugin = path.join(out, 'off-label-checkout-test');
const read = p => fs.readFileSync(p, 'utf8');
const baseline = process.argv.includes('--baseline');
const shipping = (amount = '$8.99', packages = 1) => `<tr class="olr-checkout-shipping-summary"><th>Shipping</th><td>${amount}</td></tr>` + Array.from({length:packages}, (_,i) => `<tr class="woocommerce-shipping-totals"><th>Shipping ${i+1}</th><td><ul class="woocommerce-shipping-methods"><li><input class="shipping_method" name="shipping_method[${i}]" type="radio" id="rate${i}" value="flat_rate:${i}" checked><label for="rate${i}">Flat rate: <span>${amount}</span></label></li></ul></td></tr>`).join('');
const table = (amount = '$8.99', packages = 1) => `<table class="shop_table woocommerce-checkout-review-order-table"><tbody><tr><td>MOTS-C — 3 bottles</td><td>$130.00</td></tr></tbody><tfoot><tr class="cart-subtotal"><th>Subtotal</th><td>$195.00</td></tr><tr class="cart-discount"><th>Coupon</th><td>−$65.00</td></tr>${shipping(amount, packages)}<tr class="tax-total"><th>Tax</th><td>$10.73</td></tr><tr class="order-total"><th>Total</th><td>$149.72</td></tr></tfoot></table>`;
const fixture = () => `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="olr-checkout-test-page"><div class="olr-checkout-test woocommerce" data-olr-page="checkout-test" data-olr-checkout-stage="information"><header class="olr-checkout-test__header"><h1>OFF LABEL</h1><p>Secure checkout</p></header><nav class="olr-checkout-test__steps">${['information','shipping','payment'].map(s=>`<button type="button" data-olr-stage-target="${s}">${s}</button>`).join('')}</nav><div class="olr-checkout-test__status" role="status"></div><main class="olr-checkout-test__main"><form class="checkout woocommerce-checkout"><div id="customer_details"><div class="woocommerce-billing-fields"><h3>Contact</h3><p class="form-row" id="billing_email_field"><label>Email</label><input type="email" id="billing_email" name="billing_email" value="qa@example.invalid"></p></div></div><h3 id="order_review_heading">Your order</h3><div id="order_review">${table()}<div id="payment" class="woocommerce-checkout-payment"><input type="radio" name="payment_method" id="payment_method_fixture" value="fixture" checked><button id="place_order" type="submit">Place order</button></div></div></form></main></div></body></html>`;
(async () => {
 const browser = await chromium.launch({headless:true, executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 const errors=[];
 try {
  for (const width of [390,1440]) {
   const page = await browser.newPage({viewport:{width,height:1000}});
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',r=>r.abort()); // No store, payment or external requests permitted.
   await page.setContent(fixture());
   await page.addStyleTag({content:read(path.join(plugin,'assets/checkout-test.css'))});
   await page.addScriptTag({content:read(path.join(root,'output/pricing-audit/jquery-3.7.1.min.js'))});
   await page.evaluate(() => {
    window.olrCheckoutTest = {homeUrl:'/',stageLabels:{}};
    window.wc_checkout_params = {is_checkout:'0',option_guest_checkout:'yes',wc_ajax_url:'https://fixture.invalid/?wc-ajax=%%endpoint%%',apply_coupon_nonce:'fixture',checkout_url:'https://fixture.invalid/?wc-ajax=checkout'};
    window.wc = {customPlaceOrderButton:new Proxy({}, {get:()=>()=>{}})};
    jQuery.blockUI = {defaults:{overlayCSS:{}}};
    jQuery.fn.block = jQuery.fn.unblock = function(){return this;};
    jQuery.scroll_to_notices = function(){};
    window.requests=[]; window.responses=[]; window.checkoutAttempts=0; window.orderSubmitEvents=0;
    jQuery.ajax = function(settings){
     window.requests.push({url:settings.url,data:settings.data});
     const d=jQuery.Deferred(); const xhr=d.promise(); xhr.abort=()=>{};
     if(settings.url.includes('wc-ajax=checkout')) {window.checkoutAttempts++; return xhr;}
     if(settings.url.includes('apply_coupon')) {
      window.responses.push((success=true)=>{
       if(success && settings.success) settings.success('<div class="woocommerce-message">Coupon code applied successfully.</div>');
       else if(settings.error) settings.error();
       if(settings.complete) settings.complete();
       d.resolve();
      });
     }
     return xhr;
    };
    document.querySelector('form.checkout').addEventListener('submit',()=>window.orderSubmitEvents++);
   });
   // Actual WooCommerce 11.1.0 submission/event code, with network endpoints mocked above.
   await page.addScriptTag({content:read(path.join(root,'output/checkout-update-1.4.3/wc-checkout-11.1.0.js'))});
   await page.waitForFunction(()=>!!jQuery._data(document.querySelector('form.checkout'),'events')?.submit);
   await page.addScriptTag({content:read(baseline ? 'C:/Users/User/Downloads/off-label-checkout-test/assets/checkout-test.js' : path.join(plugin,'assets/checkout-test.js'))});
   await page.waitForSelector('#olr-checkout-coupon');
   if (baseline) {
    await page.evaluate(()=>document.querySelector('form.checkout').addEventListener('click',event=>{if(event.target.closest('#order_review button'))jQuery('form.checkout').trigger('submit');}));
    await page.locator('#olr-checkout-coupon').fill('testbogo');
    await page.locator('.olr-checkout-test__promo button').click();
    assert.equal(await page.evaluate(()=>checkoutAttempts),1,'Baseline must reproduce the simulated delegated-click conflict');
    console.log(`CONFIRMED ${width}px: original 1.4.1 reaches mock order endpoint under simulated broad delegated click handler. Not proof of the exact live handler.`);
    await page.close(); continue;
   }
   assert.equal(await page.locator('#olr-checkout-coupon').evaluate(e=>e.form.id),'olr-checkout-coupon-form');
   assert.equal(await page.locator('form.checkout form').count(),0,'No nested coupon form');
   assert.equal(await page.locator('.olr-checkout-shipping-summary').isVisible(),true);
   assert.equal(await page.locator('.woocommerce-shipping-totals').isVisible(),false);
   // Simulate a third-party delegated listener that mistakenly submits ANY checkout button.
   await page.evaluate(()=>{
    window.badIntegration=event=>{if(event.target.closest('#order_review button'))jQuery('form.checkout').trigger('submit');};
    document.querySelector('form.checkout').addEventListener('click',window.badIntegration);
   });
   const input=page.locator('#olr-checkout-coupon'), apply=page.locator('.olr-checkout-test__promo button');
   await input.fill('testbogo'); await apply.click();
   assert.equal(await page.evaluate(()=>requests.filter(r=>r.url.includes('apply_coupon')).length),1);
   assert.equal(await page.evaluate(()=>checkoutAttempts),0,'Apply must not reach order endpoint');
   await page.evaluate(()=>jQuery('form.checkout').trigger('submit'));
   assert.equal(await page.evaluate(()=>checkoutAttempts),0,'Order blocked while coupon is pending');
   await page.evaluate(()=>document.querySelector('.olr-checkout-test__promo button').dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true})));
   assert.equal(await page.evaluate(()=>responses.length),1,'Duplicate click blocked');
   await page.evaluate(()=>responses.shift()(true));
   assert.equal(await input.inputValue(),'');
   // Keyboard path belongs to coupon form as well.
   await input.fill('test'); await input.press('Enter');
   assert.equal(await page.evaluate(()=>responses.length),1);
   await page.evaluate(()=>responses.shift()(false));
   assert.equal(await input.inputValue(),'test','Failed request retains code');
   assert.equal(await apply.isEnabled(),true,'Failed request allows retry');
   // Even a third-party mutation back to type=submit cannot submit checkout.
   await apply.evaluate(e=>e.type='submit'); await apply.click();
   assert.equal(await page.evaluate(()=>checkoutAttempts),0);
   assert.equal(await page.evaluate(()=>orderSubmitEvents),0);
   await page.evaluate(()=>responses.shift()(true));
   await input.fill('test2');
   await page.evaluate(()=>document.querySelector('#olr-checkout-coupon-form').requestSubmit());
   assert.equal(await page.evaluate(()=>responses.length),1);
   assert.equal(await page.evaluate(()=>orderSubmitEvents),0);
   await page.evaluate(()=>responses.shift()(true));
   // Fragment refresh: controls rebind without duplicate handlers, shipping updates from server HTML.
   await page.evaluate(html=>{
    document.querySelector('.woocommerce-checkout-review-order-table').outerHTML=html;
    document.querySelector('.olr-checkout-test__promo').remove();
    jQuery(document.body).trigger('updated_checkout');
    jQuery(document.body).trigger('updated_checkout');
   },table('Free!',2));
   assert.equal(await page.locator('.olr-checkout-shipping-summary').innerText(),'SHIPPING\tFree!');
   assert.equal(await page.locator('.olr-shipping-methods input').count(),2,'All shipping packages kept');
   assert.equal(await page.locator('#olr-checkout-coupon-form').count(),1);
   await input.fill('again'); await apply.click();
   assert.equal(await page.evaluate(()=>responses.length),1,'No duplicate handler after refresh');
   await page.evaluate(()=>responses.shift()(true));
   // Restore a paid shipping response for visual verification.
   await page.evaluate(html=>{
    document.querySelector('.woocommerce-checkout-review-order-table').outerHTML=html;
    jQuery(document.body).trigger('updated_checkout');
   },table());
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'No horizontal overflow');
   await page.screenshot({path:path.join(out,`checkout-${width}.png`),fullPage:true});
   await page.locator('[data-olr-stage-target="shipping"]').click();
   assert.equal(await page.locator('.olr-shipping-methods').isVisible(),true);
   assert.equal(await page.locator('.olr-checkout-shipping-summary').isVisible(),true);
   await page.locator('[data-olr-stage-target="payment"]').click();
   // Only explicitly intended checkout should still work through WooCommerce (mock endpoint).
   await page.evaluate(()=>document.querySelector('form.checkout').removeEventListener('click',window.badIntegration));
   await page.locator('#place_order').click();
   assert.equal(await page.evaluate(()=>checkoutAttempts),1,'Intentional Place order preserved in non-preview fixture');
   // Empty/no-rate refresh clears stale method controls.
   await page.evaluate(()=>{
    document.querySelectorAll('.woocommerce-shipping-totals,.olr-checkout-shipping-summary').forEach(e=>e.remove());
    jQuery(document.body).trigger('updated_checkout');
   });
   assert.equal(await page.locator('.olr-shipping-methods input').count(),0);
   await page.close();
   console.log(`PASS ${width}px: actual WC checkout JS; isolated click/Enter/form submit; duplicate/retry/refresh; intended order path; native shipping row and multi-package controls.`);
  }
  assert.deepEqual(errors,[]);
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
