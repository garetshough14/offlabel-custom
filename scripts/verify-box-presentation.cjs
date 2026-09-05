const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {chromium}=require(process.env.OLR_PLAYWRIGHT||'playwright');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const data=(p,m='image/png')=>'data:'+m+';base64,'+fs.readFileSync(path.join(root,p)).toString('base64');
const controls='wordpress-plugins/off-label-site-controls/assets/';
const php=execFileSync('C:/Users/User/AppData/Local/Temp/olr-presentation-qa-1013/php/php.exe',[path.join(root,'scripts/verify-site-controls.php'),'--box-fixture'],{encoding:'utf8'});
let fixture=php.split('BOX_FIXTURE_START\n')[1].match(/<body>[\s\S]*?<\/body>/)[0].replace(/<script[\s\S]*?<\/script>/g,'').replace(/<header[\s\S]*?<\/header>/,'');
fixture=fixture.replace(/https:[^"]+build-box-transparent-v1.png/,data(controls+'build-box-transparent-v1.png'));
let imageIndex=0;const images=['semax-10mg.jpg','bpc-157-10mg.jpg','kpv-10mg.jpg'];
fixture=fixture.replace(/\.\.\/\.\.\/\.\.\/images\/products\/[^" ]+/g,()=>data('exports/website-product-image-library/images/'+images[imageIndex++%images.length],'image/jpeg'));
(async()=>{
const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
try {
 const page=await browser.newPage();await page.route('**/*',r=>r.abort());
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 for(const width of [360,375,390,768,1024,1440,1920]) {
  await page.setViewportSize({width,height:1100});
  await page.setContent(`<style>html,body{margin:0}${read('styles.css')}\n${read('wordpress-plugins/off-label-production-rollout/assets/production-fixes.css')}\n${read('wordpress-plugins/off-label-build-a-box/assets/build-a-box.css')}</style>${fixture}`);
  await page.evaluate(()=>{
   const grid=document.querySelector('[data-product-grid]');
   const extra=grid.querySelector('[data-product-card]').cloneNode(true);extra.classList.add('is-extra');extra.dataset.productId='999';grid.append(extra);
   grid.insertAdjacentHTML('beforeend','<button class="olr-build-box__view-all-tile" type="button" data-view-all><span aria-hidden="true">▢ ▢ ▢</span><strong>View all<br>bottles</strong><b aria-hidden="true">→</b></button>');
   grid.insertAdjacentHTML('afterend','<button class="olr-build-box__browse" type="button" data-view-all>Browse all bottles <span aria-hidden="true">→</span></button>');
  });
  const before=await page.locator('.olr-build-box__hero-copy h1').evaluate(e=>parseFloat(getComputedStyle(e).fontSize));
  await page.addStyleTag({content:read(controls+'site-controls.css')});
  await page.addScriptTag({content:read('wordpress-plugins/off-label-build-a-box/assets/build-a-box.js')});
  await page.locator('.olr-build-box__product-media img').evaluateAll(images=>Promise.all(images.map(i=>i.decode())));
  const after=await page.locator('.olr-build-box__hero-copy h1').evaluate(e=>parseFloat(getComputedStyle(e).fontSize));assert.ok(after<before);
  const tiers=await page.locator('.olr-build-box__tier').evaluateAll(t=>t.map(e=>{const b=e.getBoundingClientRect(),c=e.querySelector('.olr-build-box__tier-copy').getBoundingClientRect();return Math.abs(b.x+b.width/2-c.x-c.width/2);}));assert.ok(tiers.every(n=>n<1),JSON.stringify(tiers));
  const media=await page.locator('.olr-build-box__product:not(.is-extra) .olr-build-box__product-media').evaluateAll(items=>items.map(e=>{const b=e.getBoundingClientRect(),i=e.querySelector('img'),r=i.getBoundingClientRect();return{w:b.width,h:b.height,fit:getComputedStyle(i).objectFit,transform:getComputedStyle(i).transform,within:r.x>=b.x-1&&r.y>=b.y-1&&r.right<=b.right+1&&r.bottom<=b.bottom+1};}));
  assert.ok(media.every(m=>m.fit==='contain'&&m.transform==='none'&&m.within));assert.ok(Math.max(...media.map(m=>m.h))-Math.min(...media.map(m=>m.h))<1);
  const tile=await page.locator('.olr-build-box__view-all-tile').boundingBox(),grid=await page.locator('[data-product-grid]').boundingBox();assert.ok(Math.abs(tile.width-grid.width)<1);assert.ok(tile.height<=100);
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  if([390,1440].includes(width)) {
   for(const [selector,name] of [['.olr-build-box__hero','hero'],['.olr-build-box__tiers','tiers'],['[data-product-grid]','products']]) await page.locator(selector).screenshot({path:path.join(root,`output/site-controls/box-${name}-106-${width}.png`)});
  }
  await page.locator('.olr-build-box__view-all-tile').click();assert.ok(await page.locator('[data-product-id="999"]').isVisible());assert.equal(await page.locator('.olr-build-box__view-all-tile').isVisible(),false);
  await page.locator('.olr-build-box__browse').click();assert.equal(await page.locator('[data-product-id="999"]').isVisible(),false);
  await page.locator('.olr-build-box__tier').filter({has:page.locator('[value="10"]')}).click();assert.equal(await page.locator('[data-box-limit]').first().innerText(),'10');
  await page.locator('[data-product-card]').first().locator('[data-add-product]').click();assert.equal(await page.locator('[data-box-count]').first().innerText(),'1');
  const first=page.locator('[data-product-card]').first();
  await first.locator('[data-quantity-change="1"]').click();assert.equal(await page.locator('[data-box-count]').first().innerText(),'2');
  await first.locator('[data-quantity-change="-1"]').click();assert.equal(await page.locator('[data-box-count]').first().innerText(),'1');
  await first.locator('[data-quantity-change="-1"]').click();assert.equal(await page.locator('[data-box-count]').first().innerText(),'0');
  await first.locator('[data-quantity-change="-1"]').click();assert.equal(await page.locator('[data-box-count]').first().innerText(),'0');
  const stepper=await first.locator('.olr-build-box__quantity').boundingBox();assert.equal(stepper.width,90);assert.equal(stepper.height,30);
  const buttonStyle=await first.locator('[data-quantity-change="1"]').evaluate(e=>{const s=getComputedStyle(e);return{w:s.width,h:s.height,bg:s.backgroundColor,color:s.color,size:s.fontSize,border:s.borderLeftColor};});
  assert.deepEqual(buttonStyle,{w:'28px',h:'28px',bg:'rgba(0, 0, 0, 0)',color:'rgb(10, 10, 9)',size:'17px',border:'rgb(185, 182, 176)'});
  if([390,1440].includes(width)) await first.screenshot({path:path.join(root,`output/site-controls/box-quantity-109-${width}.png`)});
  console.log(`PASS ${width}px: hero ${before}->${after}px; tier centers aligned; equal contained images; compact CTA expands/collapses; tier selection/add work.`);
 }
 assert.deepEqual(errors,[]);
}finally{await browser.close();}
})();
