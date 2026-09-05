const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const { chromium } = require('C:/Users/User/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = path.resolve(__dirname,'..');
const out = path.join(root,'output/checkout-update-1.4.4');
const assets = path.join(out,'off-label-checkout-test/assets');
const read = p => fs.readFileSync(p,'utf8');
const bottle = 'data:image/jpeg;base64,' + fs.readFileSync(path.join(root,'images/products/sermorelin.jpg')).toString('base64');
const money = n => `<span class="woocommerce-Price-amount amount"><bdi>$${n}</bdi></span>`;
const product = (id, name, regular, total, box=false) => `<tr class="cart_item ${box?'olr-box-cart-item--parent':''}" data-test-id="${id}"><td class="product-name"><span class="olr-checkout-test__product"><img class="olr-checkout-test__product-image ${box?'olr-box-tier-image':''}" src="${bottle}" alt="Bottle"><span class="olr-checkout-test__product-copy">${name}</span></span>${box?'<span class="olr-box-checkout-actions"><button class="olr-checkout-remove" type="button" data-olr-remove-box="box">Remove box</button><a class="olr-checkout-edit" href="#edit-box">Edit contents</a></span>':`<span class="olr-checkout-quantity" data-olr-cart-item="${id}" data-quantity="3"><button type="button" data-olr-quantity-change="-1">−</button><output>3</output><button type="button" data-olr-quantity-change="1">+</button></span><button type="button" class="olr-checkout-remove" data-olr-remove-item="${id}">Remove</button>`}<dl class="variation">${box?'<dt class="variation-Contents">Contents:</dt><dd class="variation-Contents"><ul class="olr-box-manifest"><li>2 × CJC-1295 / Ipamorelin (No DAC)</li><li>3 × BPC-157 / TB-500 Blend</li><li>5 × MOTS-C</li></ul></dd>':id==='long'?'<dt class="variation-Strength">Strength:</dt><dd class="variation-Strength"><p>10 MG</p></dd>':''}<dt class="variation-Appliedoffer">Applied offer:</dt><dd class="variation-Appliedoffer"><p>${box?'Build Your Box':'testbogo'}</p></dd></dl></td><td class="product-total"><del>${money(regular)}</del> <ins>${money(total)}</ins><small class="olr-offer-message">Offer included</small></td></tr>`;
const table = () => `<table class="shop_table woocommerce-checkout-review-order-table"><tbody>${product('simple','MOTS-C - 10','195.00','130.00')}${product('long','CJC-1295 / Ipamorelin (No DAC) — Research Compound','1,950.00','1,300.00')}${product('box','The Ten Research Box','610.00','427.00',true)}</tbody><tfoot><tr class="cart-subtotal"><th>Subtotal</th><td>${money('2,755.00')}</td></tr><tr class="cart-discount"><th>Coupon: testbogo</th><td>−${money('715.00')}<br><a href="#remove-coupon">[Remove]</a></td></tr><tr><th>Automatic best offer</th><td>−${money('183.00')}</td></tr><tr class="olr-checkout-shipping-summary"><th>Shipping</th><td>${money('8.99')}</td></tr><tr class="woocommerce-shipping-totals"><th>Shipping</th><td><ul class="woocommerce-shipping-methods"><li><input type="hidden" class="shipping_method" name="shipping_method[0]" value="flat_rate:1" id="shipping_method_0"><label for="shipping_method_0">Flat rate: ${money('8.99')}</label></li></ul></td></tr><tr class="tax-total"><th>Tax</th><td>${money('153.20')}</td></tr><tr class="order-total"><th>Total</th><td>${money('2,019.19')}</td></tr></tfoot></table>`;
const fixture = () => `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:Arial,sans-serif}*{box-sizing:border-box}a{color:inherit}table{border-spacing:0}dl.variation dt{float:left;margin-right:4px}dl.variation dd{margin:0}td{vertical-align:top}</style></head><body class="olr-checkout-test-page"><div class="olr-checkout-test woocommerce" data-olr-page="checkout-test" data-olr-checkout-stage="information"><header class="olr-checkout-test__header"><h2>OFF LABEL</h2><p>Secure checkout</p></header><nav class="olr-checkout-test__steps">${['information','shipping','payment'].map((s,i)=>`<button type="button" data-olr-stage-target="${s}"><b>${i+1}.</b>${s}</button>${i<2?'<span></span>':''}`).join('')}</nav><div class="olr-checkout-test__status" role="status"></div><main class="olr-checkout-test__main"><form class="checkout woocommerce-checkout"><div id="customer_details"><div class="col-1"><div class="woocommerce-billing-fields"><h3>Contact</h3><div class="woocommerce-billing-fields__field-wrapper"><p class="form-row" id="billing_email_field"><label for="billing_email">Email *</label><input type="email" class="input-text" id="billing_email" value="qa@example.invalid"></p><p class="form-row" id="billing_first_name_field"><label>First name</label><input class="input-text" value="QA"></p></div></div></div></div><h3 id="order_review_heading">Your order</h3><div id="order_review">${table()}<div id="payment" class="woocommerce-checkout-payment"><button type="submit" id="place_order">Place order</button></div></div></form></main></div></body></html>`;
async function verifyBounds(page) {
 const clipped = await page.locator('#order_review .product-total bdi, #order_review tfoot td bdi, #order_review .olr-checkout-meta-entry').evaluateAll(nodes=>nodes.filter(el=>{
  const bounds=el.getBoundingClientRect();
  const limit=document.querySelector('#order_review').getBoundingClientRect();
  const range=document.createRange();range.selectNodeContents(el);
  return bounds.right>limit.right+1 || bounds.left<limit.left-1 || Array.from(range.getClientRects()).some(r=>r.right>limit.right+1 || r.left<limit.left-1);
 }).map(el=>el.textContent));
 assert.deepEqual(clipped,[],'Prices and metadata must stay inside the summary, not merely be clipped by body CSS');
}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 const errors=[];
 try {
  for (const width of [320,360,390,768,900,1024,1440]) {
   const page=await browser.newPage({viewport:{width,height:1000}});page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',r=>r.abort());
   await page.setContent(fixture());
   await page.addStyleTag({content:read(path.join(assets,'checkout-test.css'))});
   // Later-loaded box/pricing plugin styles reproduce the production cascade.
   await page.addStyleTag({content:read(path.join(root,'wordpress-plugins/off-label-build-a-box/assets/build-a-box.css'))});
   await page.addStyleTag({content:read(path.join(root,'wordpress-plugins/off-label-best-offer/assets/volume.css'))});
   await page.addScriptTag({content:read(path.join(root,'output/pricing-audit/jquery-3.7.1.min.js'))});
   await page.evaluate(()=>{
    window.olrCheckoutTest={homeUrl:'/',ajaxUrl:'/fixture-ajax',cartNonce:'fixture',stageLabels:{}};
    window.wc_checkout_params={wc_ajax_url:'/fixture?wc-ajax=%%endpoint%%',apply_coupon_nonce:'fixture'};
    window.requests=[];
    jQuery.ajax=settings=>{requests.push(settings.data);return jQuery.Deferred().resolve({success:true,data:{cartCount:4,quantity:4,cartEmpty:false}}).promise();};
   });
   await page.addScriptTag({content:read(path.join(assets,'checkout-test.js'))});
   await page.waitForSelector('.olr-checkout-line-details');
   const layout=await page.evaluate(()=>{
    const c=document.querySelector('#customer_details'),h=document.querySelector('#order_review_heading'),r=document.querySelector('#order_review');
    return {customer:c.getBoundingClientRect().toJSON(),heading:h.getBoundingClientRect().toJSON(),review:r.getBoundingClientRect().toJSON(),first:c.parentElement.children[0].id};
   });
   if(width<=900){assert.equal(layout.first,'order_review_heading');assert.ok(layout.review.bottom<=layout.customer.top+1);}
   else {assert.equal(layout.first,'customer_details');assert.ok(layout.review.left>layout.customer.left);}
   await verifyBounds(page);
   for(const stage of ['shipping','payment','information']) {
    await page.locator(`[data-olr-stage-target="${stage}"]`).click();
    await verifyBounds(page);
   }
   const originalCount=await page.locator('.olr-checkout-meta-entry').count();
   await page.evaluate(()=>{jQuery(document.body).trigger('updated_checkout');jQuery(document.body).trigger('updated_checkout');});
   assert.equal(await page.locator('.olr-checkout-meta-entry').count(),originalCount,'Metadata not duplicated');
   assert.equal(await page.locator('.olr-checkout-line-details').count(),3);
   await page.locator('[data-test-id="simple"] [data-olr-quantity-change="1"]').click();
   assert.equal(await page.evaluate(()=>requests.at(-1).quantity),4,'Moved + button retains correct cart key and quantity');
   assert.equal(await page.evaluate(()=>requests.at(-1).cart_item_key),'simple');
   await page.locator('[data-test-id="simple"] [data-olr-remove-item]').click();
   assert.equal(await page.evaluate(()=>requests.at(-1).quantity),0,'Moved Remove still removes correct item');
   assert.equal(await page.locator('.olr-box-checkout-actions .olr-checkout-edit').getAttribute('href'),'#edit-box');
   assert.equal(await page.locator('.olr-box-manifest li').count(),3,'Box contents retained');
   assert.equal(await page.locator('.product-total .olr-offer-message').first().isVisible(),false);
   assert.equal(await page.locator('.olr-checkout-meta-entry').filter({hasText:'Applied offer'}).count(),3,'Named offers retained');
   // Fresh native AJAX table replacement must regain the same formatting/actions.
   await page.evaluate(html=>{document.querySelector('table.shop_table').outerHTML=html;jQuery(document.body).trigger('updated_checkout');},table());
   await verifyBounds(page);assert.equal(await page.locator('.olr-checkout-line-details').count(),3);
   if([320,390,1440].includes(width)) await page.screenshot({path:path.join(out,`layout-${width}.png`),fullPage:true});
   // Resizing must preserve the exact input node, its form owner and typed code.
   await page.locator('#olr-checkout-coupon').fill('UNSENT');
   await page.setViewportSize({width:width<=900?1440:390,height:1000});
   await page.waitForFunction(()=>document.querySelector('form.checkout').firstElementChild.id===(innerWidth<=900?'order_review_heading':'customer_details'));
   assert.equal(await page.locator('#olr-checkout-coupon').inputValue(),'UNSENT');
   assert.equal(await page.locator('#olr-checkout-coupon').evaluate(e=>e.form.id),'olr-checkout-coupon-form');
   await verifyBounds(page);
   await page.close();console.log(`PASS ${width}px: mobile-first summary; desktop columns; all steps; long names/stacked prices/box contents; bounds; retained controls; fragments and resize.`);
  }
  assert.deepEqual(errors,[]);
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
