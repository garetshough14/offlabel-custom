const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {chromium} = require(process.env.OLR_PLAYWRIGHT || 'playwright');
const root = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const controls = 'wordpress-plugins/off-label-site-controls/assets/';
const data = (p, mime) => `data:${mime};base64,${fs.readFileSync(path.join(root, p)).toString('base64')}`;
const logo = data('branding/off-label-logo-cropped-black.webp', 'image/webp');
const bottle = data('wordpress-plugins/off-label-production-rollout/assets/product-images/epitalon-10mg.jpg', 'image/jpeg');
const header = read('wordpress-plugins/off-label-production-rollout/assets/site-header.html').replace(/https:\/\/cdn[^" ]+off-label-logo-cropped-black.webp/g, logo).replaceAll('[olr_cart_count]', '(0)');
let home = read('gitpress/pages/home.html').split('<section class="olr-proof-strip"')[0] + '</div></div>';
home = home.replace(/https:\/\/cdn[^" ]+hero-molecular-studio.png/g, data('images/editorial/hero-molecular-studio.png', 'image/png')).replace(/https:\/\/cdn[^" ]+hero-three-vials-transparent-glass.png/g, data('images/editorial/hero-three-vials-transparent-glass.png', 'image/png'));
const catalog = `<div class="olr-gitpress-site olr-redesign" data-olr-page="research"><div class="olr-shell"><div class="olr-research-card-grid">${['Epitalon', 'Semax', 'Selank'].map(name => `<article class="olr-research-card"><a class="olr-research-card__media" href="/catalog/epitalon/"><span class="olr-research-card__badge">Featured</span><img class="olr-research-card__image" src="${bottle}"></a><div class="olr-research-card__body"><p>RESEARCH COMPOUNDS</p><h2><a>${name}</a></h2><span class="olr-research-card__detail">10 MG</span><div class="olr-research-card__price">$40.00</div></div></article>`).join('')}</div></div></div>`;
(async () => {
 const browser = await chromium.launch({executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true});
 try {
  const page = await browser.newPage();
  await page.route('**/*', route => route.abort());
  for (const width of [375, 390, 768, 1024, 1440]) {
   await page.setViewportSize({width, height: 900});
   const fixtureUrl = `https://offlabelresearch.com/local-site-controls-fixture/${width}`;
   await page.route(fixtureUrl, route => route.fulfill({contentType:'text/html',body:`<style>${read('styles.css')}\n${read('wordpress-plugins/off-label-production-rollout/assets/production-fixes.css')}</style>${header}${home}${catalog}`}));
   await page.goto(fixtureUrl);
   const before = await page.locator('#olr-home-title').evaluate(e => parseFloat(getComputedStyle(e).fontSize));
   const bannerBefore = await page.locator('.olr-announcement').evaluate(e => ({bg:getComputedStyle(e).backgroundColor, color:getComputedStyle(e).color, font:getComputedStyle(e).fontSize}));
   await page.addStyleTag({content: read(controls + 'site-controls.css')});
   await page.evaluate(() => window.olrPromoBanner = [{text:'Research use only',url:''},{text:'Not for human consumption',url:''},{text:'Document archive',url:'https://offlabelresearch.com/coas/'}]);
   await page.addScriptTag({content:read(controls + 'promo-banner.js')});
   const after = await page.locator('#olr-home-title').evaluate(e => parseFloat(getComputedStyle(e).fontSize));
   assert.ok(width <= 768 ? after < before : after === before, `${width}: ${before} -> ${after}`);
   assert.deepEqual(await page.locator('.olr-announcement').evaluate(e => ({bg:getComputedStyle(e).backgroundColor,color:getComputedStyle(e).color,font:getComputedStyle(e).fontSize})),bannerBefore);
   const cards = await page.locator('.olr-research-card').evaluateAll(cards => cards.map(c => {
    const i=c.querySelector('img'), b=c.querySelector('.olr-research-card__media'), h=c.querySelector('h2');
    const ir=i.getBoundingClientRect(),br=b.getBoundingClientRect(),hr=h.getBoundingClientRect();
    return {offset:Math.abs(ir.x+ir.width/2-hr.x-hr.width/2),fit:getComputedStyle(i).objectFit,hidden:getComputedStyle(c.querySelector('p')).display==='none',contained:ir.y>=br.y-1 && ir.bottom<=br.bottom+1};
   }));
   assert.ok(cards.every(c=>c.hidden));
   if(width<=768) assert.ok(cards.every(c=>c.offset<1 && c.fit==='contain' && c.contained),JSON.stringify(cards));
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth+1));
   if(width===390) {
    fs.mkdirSync(path.join(root,'output/site-controls'),{recursive:true});
    await page.screenshot({path:path.join(root,'output/site-controls/mobile-home.png')});
    await page.locator('[data-olr-page="research"]').screenshot({path:path.join(root,'output/site-controls/mobile-catalog.png')});
   }
   console.log(`PASS ${width}px: headline ${before}px -> ${after}px; banner design preserved; categories hidden; image alignment.`);
  }
  await page.evaluate(()=>window.olrPromoBanner=[{text:'<img src=x onerror=alert(1)>',url:'javascript:alert(1)'},{text:'Shop this month',url:'https://offlabelresearch.com/catalog/'}]);
  await page.addScriptTag({content:read(controls+'promo-banner.js')});
  assert.equal(await page.locator('.olr-announcement img').count(),0);
  assert.equal(await page.locator('.olr-announcement a').count(),1);
  assert.equal(await page.locator('.olr-announcement__separator').count(),1);
  await page.evaluate(()=>window.olrPromoBanner=[]);
  await page.addScriptTag({content:read(controls+'promo-banner.js')});
  assert.equal(await page.locator('.olr-announcement').count(),0);
  console.log('PASS: editable text/link updates, empty messages, unsafe URL rejection and text-only rendering.');
  const documentation = read('gitpress/pages/research.html').match(/<section class="olr-research-documentation"[\s\S]*?<\/section>/)[0];
  const art = data(controls+'catalog-documentation-light-v2.png','image/png');
  const oldArt = data(controls+'catalog-documentation-approved-v1.png','image/png');
  const luminances = await page.evaluate(async sources=>Promise.all(sources.map(src=>new Promise((resolve,reject)=>{
   const i=new Image();i.onload=()=>{const c=document.createElement('canvas');c.width=96;c.height=64;const ctx=c.getContext('2d');ctx.drawImage(i,0,0,96,64);const p=ctx.getImageData(0,0,96,64).data;let sum=0;for(let n=0;n<p.length;n+=4)sum+=p[n]*.2126+p[n+1]*.7152+p[n+2]*.0722;resolve(sum/(96*64));};i.onerror=reject;i.src=src;
  }))),[oldArt,art]);
  assert.ok(luminances[1]>luminances[0]+8,'Catalog artwork must be visibly lighter');
  console.log(`PASS: catalog mean luminance increased ${luminances[0].toFixed(1)} -> ${luminances[1].toFixed(1)}.`);
  for (const width of [375,390,768,1024,1440,1920]) {
   await page.setViewportSize({width,height:1000});
   await page.setContent(`<style>${read('styles.css')}\n${read('wordpress-plugins/off-label-production-rollout/assets/production-fixes.css')}</style><div class="olr-gitpress-site olr-redesign" data-olr-page="research">${documentation}</div>`);
   const section = page.locator('.olr-research-documentation');
   const before = await section.boundingBox();
   await page.addStyleTag({content:read(controls+'site-controls.css').replace('url("catalog-documentation-light-v2.png")',`url("${art}")`)});
   assert.deepEqual(await section.boundingBox(),before,'Artwork must not change section layout');
   const media = await page.locator('.olr-research-documentation__media').evaluate(e=>({width:e.clientWidth,height:e.clientHeight,bg:getComputedStyle(e).backgroundImage}));
   assert.ok(media.bg.includes('data:image/png;base64,'));
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   await page.evaluate(src=>new Promise((resolve,reject)=>{const img=new Image();img.onload=()=>resolve();img.onerror=reject;img.src=src;}),art);
   await section.screenshot({path:path.join(root,`output/site-controls/documentation-${width}.png`)});
   console.log(`PASS ${width}px: local documentation artwork loaded; section dimensions unchanged (${media.width}x${media.height}); no horizontal overflow.`);
  }
  for (const width of [375,390,768,1024,1440,1920]) {
   const php = require('node:child_process').execFileSync('C:/Users/User/AppData/Local/Temp/olr-presentation-qa-1013/php/php.exe',[path.join(root,'scripts/verify-site-controls.php'),'--about-fixture'],{encoding:'utf8'});
   const aboutSection = php.match(/<section class="olr-philosophy-report"[\s\S]*?<\/section>/)[0];
   const aboutArt = data(controls+'about-philosophy-approved-v1.png','image/png');
   const aboutWide = data(controls+'about-philosophy-wide-v2.png','image/png');
   await page.setViewportSize({width,height:1100});
   await page.setContent(`<style>${read('styles.css')}\n${read('wordpress-plugins/off-label-production-rollout/assets/production-fixes.css')}</style><div class="olr-gitpress-site olr-redesign" data-olr-page="about"><main class="olr-page olr-page--about">${aboutSection.replace(/https:[^"]+about-philosophy-approved-v1.png/,aboutArt).replace(/https:[^"]+about-philosophy-wide-v2.png/,aboutWide)}</main></div>`);
   const section = page.locator('.olr-philosophy-report');
   await page.locator('[data-olr-about-artwork]').evaluate(img=>img.decode());
   const before = await section.boundingBox();
   await page.addStyleTag({content:read(controls+'site-controls.css')});
   assert.deepEqual(await section.boundingBox(),before,'About section dimensions changed');
   const img = await page.locator('[data-olr-about-artwork]').evaluate(e=>({fit:getComputedStyle(e).objectFit,filter:getComputedStyle(e).filter,width:e.naturalWidth,height:e.naturalHeight,source:e.currentSrc}));
   assert.equal(img.source,width<=782?aboutArt:aboutWide,'Incorrect mobile/desktop artwork');
   assert.equal(img.fit,'cover');
   assert.equal(img.filter,'none');
   assert.ok(img.width>0 && img.height>0);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   await section.screenshot({path:path.join(root,`output/site-controls/about-${width}.png`)});
   console.log(`PASS ${width}px: correct responsive About image loaded, edge-to-edge and unfiltered; layout unchanged.`);
  }
  for (const width of [375,390,768,1024,1440]) {
   await page.setViewportSize({width,height:900});
   await page.setContent(`<style>${read('styles.css')}\n${read(controls+'site-controls.css')}</style><div class="olr-gitpress-site olr-redesign" data-olr-page="testing"><div data-olr-coa><form class="olr-coa-search__form"><input id="olr-coa-search-input"><button>Search</button></form><nav class="olr-coa-tabs">${['all','compounds','metabolic','other'].map(c=>`<button data-coa-filter="${c}">${c}</button>`).join('')}</nav><div data-coa-row data-coa-category="compounds" data-coa-search="epitalon">Epitalon</div><div data-coa-row data-coa-category="metabolic" data-coa-search="rt-3">RT-3</div><div class="olr-coa-empty" hidden>No results</div></div></div><nav class="olr-coa-tabs" id="unrelated">Unrelated</nav>`);
   await page.addScriptTag({content:read('scripts/olr-coa.js')});
   assert.equal(await page.locator('[data-olr-coa] .olr-coa-tabs').isVisible(),false);
   assert.equal(await page.locator('#unrelated').isVisible(),true);
   assert.equal(await page.locator('[data-coa-row]:visible').count(),2);
   await page.locator('#olr-coa-search-input').fill('rt-3');
   assert.equal(await page.locator('[data-coa-row]:visible').innerText(),'RT-3');
   await page.locator('#olr-coa-search-input').fill('not-a-record');
   assert.equal(await page.locator('.olr-coa-empty').isVisible(),true);
   await page.locator('#olr-coa-search-input').press('Escape');
   assert.equal(await page.locator('[data-coa-row]:visible').count(),2);
   console.log(`PASS ${width}px: COA tabs absent; cross-category search, empty results and reset work; unrelated navigation unchanged.`);
  }
 } finally {await browser.close();}
})();
