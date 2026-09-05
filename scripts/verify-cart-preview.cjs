const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.OLR_PLAYWRIGHT||'playwright');
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8');
const fixture=JSON.parse(execFileSync('C:/Users/User/AppData/Local/Temp/olr-presentation-qa-1013/php/php.exe',[path.join(root,'scripts/verify-cart-preview.php'),'--fixture'],{encoding:'utf8'}).split('CART_FIXTURE_START\n')[1]);
const dir='wordpress-plugins/off-label-site-controls/assets/';
const bottle='data:image/jpeg;base64,'+fs.readFileSync(path.join(root,'exports/website-product-image-library/images/kpv-10mg.jpg')).toString('base64');
fixture.payload.html=fixture.payload.html.replace(/src="[^"]+"/g,'src="'+bottle+'"');
fixture.header=fixture.header.replace(/https:[^"]+off-label-logo-cropped-black.webp/,'data:image/webp;base64,'+fs.readFileSync(path.join(root,'branding/off-label-logo-cropped-black.webp')).toString('base64'));
(async()=>{
 const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 try {
 for(const width of [320,360,390,768,900,1024,1440,1920]) {
  const height=width===320?568:850;
  const page=await browser.newPage({viewport:{width,height}});let response={success:true,data:fixture.payload};let fail=false;
  await page.route('**/*',r=>{
   if(r.request().url().includes('wc-ajax=olr_cart_preview')) return fail?r.fulfill({status:500,body:'Server error'}):r.fulfill({contentType:'application/json',body:JSON.stringify(response)});
   if(r.request().resourceType()==='document') return r.fulfill({contentType:'text/html',body:`<style>html,body{margin:0}${read('styles.css')}${read('wordpress-plugins/off-label-production-rollout/assets/production-fixes.css')}${read('wordpress-plugins/off-label-production-rollout/assets/customer-presentation.css')}${read(dir+'site-controls.css')}</style>${fixture.header}${fixture.sections}<button id="add">Add to research</button>`});
   return r.abort();
  });
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('https://offlabel.test/catalog/epitalon/');
  await page.evaluate(()=>{window.olrCartPreview={endpoint:'/?wc-ajax=olr_cart_preview',checkout:'/cart/',cart:'/cart/',shop:'/catalog/'};window.jQuery=()=>({on:(name,fn)=>{window.testWooAdd=fn;}});});
  await page.locator('#add').focus();await page.addScriptTag({content:read(dir+'cart-preview.js')});
  const modal=page.locator('dialog.olr-cart-preview');await modal.waitFor({state:'visible'});
  assert.equal(await modal.locator('li').count(),3);assert.equal(await modal.locator('.olr-cart-preview__checkout').getAttribute('href'),'https://offlabel.test/cart/');
  const bounds=await modal.boundingBox();assert.ok(bounds.x>=0&&bounds.x+bounds.width<=width&&bounds.y>=0&&bounds.y+bounds.height<=height);
  assert.ok(await page.locator('#olr-cart-preview-title').evaluate(e=>e===document.activeElement));
  assert.equal(await modal.locator('img').count(),3,'Duplicate checkout thumbnail');
  assert.equal(await modal.locator('.olr-checkout-test__product').count(),0,'Checkout layout leaked');
  const rows=await modal.locator('.olr-cart-preview__item').evaluateAll(items=>items.map(e=>{const name=e.querySelector('.olr-cart-preview__name').getBoundingClientRect(),price=e.querySelector('.olr-cart-preview__price').getBoundingClientRect();return {gap:price.x-name.right,top:Math.abs(name.y-(price.y+parseFloat(getComputedStyle(e.querySelector('.olr-cart-preview__price')).paddingTop)))};}));
  assert.ok(rows.every(r=>r.gap>=8&&r.top<1),'Product/price alignment');
  await page.keyboard.press('Tab');await page.keyboard.press('Tab');await page.keyboard.press('Tab');
  assert.ok(await modal.evaluate(e=>e.contains(document.activeElement)),'Focus escaped dialog');
  await modal.locator('h2').focus();await modal.locator('img').evaluateAll(images=>Promise.all(images.map(i=>i.decode())));await modal.screenshot({path:path.join(root,`output/site-controls/cart-preview-108-${width}.png`)});
  await page.keyboard.press('Escape');assert.equal(await modal.isVisible(),false);assert.ok(await page.locator('#add').evaluate(e=>e===document.activeElement));
  await page.evaluate(()=>window.testWooAdd());await page.waitForTimeout(150);assert.equal(await modal.isVisible(),false,'Duplicate event popup');
  response={success:true,data:{...fixture.payload,token:'next-'+width}};await page.evaluate(()=>window.testWooAdd());await modal.waitFor({state:'visible'});
  await modal.getByRole('button',{name:'Keep shopping'}).click();assert.equal(await modal.isVisible(),false);assert.ok(page.url().includes('/catalog/epitalon/'));
  response={success:true,data:{...fixture.payload,token:'block-'+width}};await page.evaluate(()=>document.dispatchEvent(new Event('wc-blocks_added_to_cart')));await modal.waitFor({state:'visible'});await page.keyboard.press('Escape');
  fail=true;await page.evaluate(()=>window.testWooAdd());await page.waitForTimeout(150);assert.equal(await modal.isVisible(),false);
  assert.equal(await page.locator('[data-nav="build-your-box"]').count(),2);assert.equal(await page.locator('.olr-cart-label').count(),2);
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Horizontal overflow');
  if(width<=768) {await page.locator('[data-olr-nav-drawer]').evaluate(e=>e.open=true);assert.ok(await page.locator('.olr-primary-nav--mobile [data-nav="build-your-box"]').isVisible());}
  if(width>=900) {
   const brand=await page.locator('.olr-brand').boundingBox(),nav=await page.locator('.olr-primary-nav--desktop').boundingBox(),cart=await page.locator('.olr-header-actions').boundingBox();
   assert.ok(brand.x+brand.width<=nav.x+1&&(nav.x+nav.width<=cart.x+1||cart.x+cart.width<=nav.x+1),'Header overlaps '+JSON.stringify({brand,nav,cart}));
  }
  await page.locator('.site-header').screenshot({path:path.join(root,`output/site-controls/header-107-${width}.png`)});
  if(width===390) {
   // A completed box lands on the cart through the unchanged builder redirect.
   fail=false;response={success:true,data:{...fixture.payload,token:'box-after-redirect'}};
   await page.goto('https://offlabel.test/cart/');
   await page.evaluate(()=>{window.olrCartPreview={endpoint:'/?wc-ajax=olr_cart_preview',checkout:'/cart/',cart:'/cart/',shop:'/catalog/'};});
   await page.addScriptTag({content:read(dir+'cart-preview.js')});await page.locator('dialog').waitFor({state:'visible'});
   await page.getByRole('button',{name:'Keep shopping'}).click();await page.waitForURL('**/catalog/');
   await page.evaluate(()=>{window.olrCartPreview={endpoint:'/?wc-ajax=olr_cart_preview',checkout:'/cart/',cart:'/cart/',shop:'/catalog/'};});
   await page.addScriptTag({content:read(dir+'cart-preview.js')});await page.waitForTimeout(150);assert.equal(await page.locator('dialog[open]').count(),0,'Popup repeated after navigation');
   const single=fixture.payload.html.match(/<li class="olr-cart-preview__item">[\s\S]*?<\/li>/)[0].replace('Epitalon','TZ-2 - 10').replace('Qty 2','Qty 1').replace('$80.00','$110.00');
   response={success:true,data:{...fixture.payload,token:'single-style-preview',html:'<ul class="olr-cart-preview__items">'+single+'</ul>'}};
   await page.evaluate(()=>document.dispatchEvent(new Event('wc-blocks_added_to_cart')));await page.locator('dialog').waitFor({state:'visible'});
   await page.locator('dialog img').evaluate(e=>e.decode());await page.locator('dialog').screenshot({path:path.join(root,'output/site-controls/cart-preview-108-single.png')});
  }
  assert.deepEqual(errors,[]);console.log('PASS',width,'cart modal, keyboard, contents, repeat/failed additions, header and overflow');await page.close();
 }
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
