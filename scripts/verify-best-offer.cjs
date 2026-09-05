const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const { chromium } = require(process.env.OLR_PLAYWRIGHT || 'C:/Users/User/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root,p),'utf8');
const plugin = 'wordpress-plugins/off-label-best-offer/assets/';
const config = {enabled:true,tiers:[{quantity:3,percent:10},{quantity:5,percent:15},{quantity:10,percent:20}],regular:65,current:65,variable:false};
const fixture = data => `<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:16px;background:#f7f6f2;font-family:Arial}.olr-product-view{max-width:760px;margin:auto}.olr-volume-pricing button{border:1px solid #d8d5ce;background:transparent;color:#111}.olr-volume-pricing button.is-active{background:#111;color:white}.olr-quantity-stepper{display:flex;gap:12px;margin-top:20px}input{width:40px}</style><article class="olr-product-view"><h1>EPITALON</h1><div class="olr-product-view__price"><strong>$65.00</strong></div><div class="olr-volume-pricing" data-olr-volume-pricing data-unit-price="65" data-olr-offer='${JSON.stringify(data)}'><p><strong>Volume quantities</strong><span>Choose a bottle count.</span></p><div>${[1,3,5,10].map(q=>`<button type="button" data-olr-quantity="${q}"><span>${q} BOTTLES</span><strong>$${65*q}</strong></button>`).join('')}</div></div><form class="cart variations_form"><input class="qty" value="1" min="1" step="1"><button class="single_add_to_cart_button" type="button">Add to research</button></form></article>`;
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 const errors=[];
 try {
  for(const width of [320,360,390,768,1440]) {
   const page=await browser.newPage({viewport:{width,height:900}});
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',r=>r.abort());
   await page.setContent(fixture(config));
   await page.evaluate(()=>{document.body.className='olr-gitpress-site olr-redesign';document.body.dataset.olrPage='product-detail';document.body.style.setProperty('width','auto','important');});
   await page.addStyleTag({content:read(plugin+'volume.css')});
   // Match the actual page wrapper and worst-case stylesheet order. The theme
   // uses !important rules that were not active in the original bare fixture.
   await page.addStyleTag({content:read('styles-product.css')});
   await page.locator('.olr-product-view').evaluate(el=>{el.style.setProperty('display','block','important');el.style.setProperty('max-width','510px','important');});
   await page.addScriptTag({content:read('output/pricing-audit/jquery-3.7.1.min.js')});
   await page.addScriptTag({content:read('scripts/olr-product-detail.js')});
   await page.addScriptTag({content:read(plugin+'volume.js')});
   assert.match(await page.locator('[data-olr-quantity="3"]').innerText(),/\$175.50 TOTAL/);
   assert.match(await page.locator('[data-olr-quantity="3"]').innerText(),/\$58.50 \/ BOTTLE/);
   assert.match(await page.locator('[data-olr-quantity="5"]').innerText(),/\$276.25 TOTAL/);
   assert.match(await page.locator('[data-olr-quantity="10"]').innerText(),/\$520.00 TOTAL/);
   assert.equal(await page.locator('.olr-tier-badge').count(),1);
   const badgeLayout=await page.locator('[data-olr-quantity="10"]').evaluate(button=>{
    const badge=button.querySelector('.olr-tier-badge').getBoundingClientRect();
    const title=button.querySelector('.olr-tier-title').getBoundingClientRect();
    const card=button.getBoundingClientRect();
    return badge.bottom<=title.top && badge.left>=card.left && badge.right<=card.right;
   });
   assert.equal(badgeLayout,true,`badge has its own row at ${width}px`);
   await page.locator('[data-olr-quantity="5"]').click();
   assert.equal(await page.locator('input.qty').inputValue(),'5');
   await page.locator('.olr-quantity-stepper__plus').click();
   assert.equal(await page.locator('input.qty').inputValue(),'6');
   const clipping=await page.locator('[data-olr-quantity]').evaluateAll(buttons=>buttons.some(b=>b.scrollWidth>b.clientWidth+1||b.scrollHeight>b.clientHeight+1));
   assert.equal(clipping,false,`card clipping ${width}`);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   if([390,1440].includes(width)) await page.locator('.olr-product-view').screenshot({path:path.join(root,`output/pricing-audit/volume-${width}.png`)});
   await page.evaluate(data=>jQuery('form').trigger('found_variation',[{display_price:80,olr_offer:{...data,regular:80,current:80}}]),config);
   await page.waitForFunction(()=>document.querySelector('[data-olr-quantity="10"] .olr-tier-total').textContent==='$640.00 TOTAL');
   assert.match(await page.locator('[data-olr-quantity="3"]').innerText(),/\$216.00 TOTAL/);
   await page.evaluate(data=>jQuery('form').trigger('found_variation',[{display_price:52,olr_offer:{...data,regular:65,current:52}}]),config);
   await page.waitForFunction(()=>document.querySelector('[data-olr-quantity="3"] .olr-tier-total').textContent==='$156.00 TOTAL');
   await page.evaluate(data=>jQuery('form').trigger('found_variation',[{display_price:65,olr_offer:{...data,enabled:false}}]),config);
   await page.waitForFunction(()=>document.querySelector('[data-olr-quantity="10"] .olr-tier-total').textContent==='$650.00 TOTAL');
   assert.equal(await page.locator('.olr-tier-badge').count(),0);
   await page.close();
   console.log(`PASS ${width}px: quotes, savings, badge, no clipping, existing quantity controls, variation and sale changes.`);
  }
  const page=await browser.newPage();await page.route('**/*',r=>r.abort());await page.setContent(fixture({...config,variable:true}));await page.addScriptTag({content:read(plugin+'volume.js')});assert.equal(await page.getByText('SELECT STRENGTH',{exact:true}).count(),4);await page.close();
  for(const authorized of [false,true]) {
   const privatePage=await browser.newPage({viewport:{width:390,height:900}});
   privatePage.on('pageerror',e=>errors.push(e.message));
   await privatePage.route('**/*',r=>r.abort());
   await privatePage.setContent(fixture(config).replace('<h1>','<h1 id="olr-product-title-1">').replace(/ data-olr-offer='[^']*'/,''));
   await privatePage.evaluate(({authorized,config})=>{
    window.olrOfferPreview={url:'/admin-ajax.php',nonce:'fixture-only'};
    window.fetch=async(url,options)=>{
     window.previewFetch={url,method:options.method,credentials:options.credentials,cache:options.cache,body:options.body.toString()};
     return {ok:authorized,json:async()=>({success:true,data:{quotes:{1:config},message:'PRIVATE PRICING PREVIEW',endUrl:'https://example.com/admin.php'}})};
    };
   },{authorized,config});
   await privatePage.addScriptTag({content:read(plugin+'volume.js')});
   await privatePage.waitForFunction(()=>window.previewFetch);
   const fetchOptions=await privatePage.evaluate(()=>window.previewFetch);
   assert.equal(fetchOptions.method,'POST');assert.equal(fetchOptions.credentials,'same-origin');assert.equal(fetchOptions.cache,'no-store');assert.match(fetchOptions.body,/ids%5B%5D=1/);
   if(authorized) {
    await privatePage.waitForFunction(()=>document.querySelector('.olr-private-preview-banner'));
    assert.match(await privatePage.locator('[data-olr-quantity="10"]').innerText(),/\$520.00 TOTAL/);
   } else {
    assert.equal(await privatePage.locator('.olr-private-preview-banner').count(),0);
    assert.equal(await privatePage.locator('.olr-volume-offers').count(),0);
    assert.equal(await privatePage.locator('[data-olr-quantity="10"] strong').innerText(),'$650');
   }
   await privatePage.close();
  }
  console.log('PASS: private quote bootstrap requires fresh authorization; denied/cached configuration leaves customer prices unchanged.');
  // Use WooCommerce's actual 11.1.0 variation handler, not a hand-fired event,
  // to reproduce a selection made BEFORE the private authorization returns.
  for(const scenario of ['selected-before-auth','changed-during-auth','reset-during-auth','auth-before-woocommerce']) {
   const variationPage=await browser.newPage();
   variationPage.on('pageerror',e=>errors.push(e.message));
   await variationPage.route('**/*',r=>r.abort());
   await variationPage.setContent(fixture({...config,variable:true})
    .replace('<h1>','<h1 id="olr-product-title-1">')
    .replace(/ data-olr-offer='[^']*'/,'')
    .replace('<input class="qty"',`<table class="variations"><tbody><tr><td><select name="attribute_strength"><option value="">Choose strength</option><option value="10" selected>10 MG</option><option value="20">20 MG</option></select></td></tr></tbody></table><input type="hidden" name="variation_id" value=""><div class="single_variation_wrap"><div class="single_variation"></div></div><input class="qty"`)
    + '<script type="text/template" id="tmpl-variation-template">Variation ready</script><script type="text/template" id="tmpl-unavailable-variation-template">Unavailable</script>');
   await variationPage.addScriptTag({content:read('output/pricing-audit/jquery-3.7.1.min.js')});
   await variationPage.evaluate(config=>{
    window.wc_add_to_cart_variation_params={};
    const variants=[{id:11,strength:'10',price:65},{id:12,strength:'20',price:80}].map(v=>({variation_id:v.id,attributes:{attribute_strength:v.strength},display_price:v.price,variation_is_visible:true,variation_is_active:true,is_purchasable:true,is_in_stock:true,min_qty:1,max_qty:'',image:{}}));
    jQuery('form').data('product_variations',variants);
    window.olrOfferPreview={url:'/admin-ajax.php',nonce:'fixture-only'};
    window.fetch=()=>new Promise(resolve=>{window.releaseQuotes=()=>resolve({ok:true,json:async()=>({success:true,data:{quotes:{1:{...config,variable:true},11:config,12:{...config,regular:80,current:80}},message:'PRIVATE PRICING PREVIEW',endUrl:'https://example.com/'}})});});
   },config);
   await variationPage.addScriptTag({content:read('scripts/olr-product-detail.js')});
   const loadWoo=()=>variationPage.addScriptTag({content:read('output/pricing-audit/wc-add-to-cart-variation-11.1.0.js')});
   if(scenario!=='auth-before-woocommerce') {
    await loadWoo();
    await variationPage.waitForFunction(()=>document.querySelector('[name="variation_id"]').value==='11');
   }
   await variationPage.addScriptTag({content:read(plugin+'volume.js')});
   await variationPage.waitForFunction(()=>window.releaseQuotes);
   if(scenario==='changed-during-auth') await variationPage.selectOption('select','20');
   if(scenario==='reset-during-auth') await variationPage.selectOption('select','');
   await variationPage.evaluate(()=>window.releaseQuotes());
   await variationPage.waitForFunction(()=>document.querySelector('.olr-volume-offers'));
   if(scenario==='auth-before-woocommerce') await loadWoo();
   const expected=scenario==='reset-during-auth'?'SELECT STRENGTH':scenario==='changed-during-auth'?'$640.00 TOTAL':'$520.00 TOTAL';
   await variationPage.waitForFunction(expected=>document.querySelector('[data-olr-quantity="10"] .olr-tier-total').textContent===expected,expected);
   // Changing and clearing the strength must still refresh correctly afterward.
   await variationPage.selectOption('select','20');
   await variationPage.waitForFunction(()=>document.querySelector('[data-olr-quantity="10"] .olr-tier-total').textContent==='$640.00 TOTAL');
   await variationPage.selectOption('select','');
   await variationPage.waitForFunction(()=>document.querySelector('[data-olr-quantity="10"] .olr-tier-total').textContent==='SELECT STRENGTH');
   await variationPage.selectOption('select','10');
   await variationPage.waitForFunction(()=>document.querySelector('[data-olr-quantity="10"] .olr-tier-total').textContent==='$520.00 TOTAL');
   await variationPage.close();
   console.log(`PASS actual WooCommerce variation lifecycle: ${scenario}, then switch/reset/reselect.`);
  }
  assert.deepEqual(errors,[]);
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
