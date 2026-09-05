const fs = require('node:fs');
const assert = require('node:assert/strict');
const {chromium} = require('C:/Users/User/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const read = p => fs.readFileSync(p,'utf8');
(async()=>{
 const browser = await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 try {
  const page = await browser.newPage();
  const header = read('gitpress/partials/header.html').replaceAll('[olr_cart_count]','0').replaceAll('<a href="https://offlabelresearch.com/coas/" data-nav="coas">','<a href="https://offlabelresearch.com/build-your-box/" data-nav="build-your-box">Build Your Box</a><a href="https://offlabelresearch.com/coas/" data-nav="coas">');
  const css = read('wordpress-plugins/off-label-production-rollout/assets/production-fixes.css') + read('wordpress-plugins/off-label-site-controls/assets/site-controls.css');
  await page.route('**/*',route=>route.abort());
  await page.route('https://offlabelresearch.com/contact-us/',route=>route.fulfill({contentType:'text/html',body:`<meta name="viewport" content="width=device-width,initial-scale=1">${header}<style>${css}</style>`}));
  for (const width of [320,390,768,900,1024,1440]) {
   await page.setViewportSize({width,height:900});
   await page.goto('https://offlabelresearch.com/contact-us/');
   await page.addScriptTag({content:read('scripts/olr-site-navigation.js')});
   const links = page.locator('.olr-primary-nav a[data-nav="contact"]');
   assert.equal(await links.count(),2);
   assert.equal(await links.first().getAttribute('aria-current'),'page');
   assert.equal(await links.last().getAttribute('href'),'https://offlabelresearch.com/contact-us/');
   if (width <= 896) {
    await page.locator('[data-olr-nav-toggle]').click();
    await page.locator('.olr-primary-nav--mobile a[data-nav="contact"]').waitFor({state:'visible'});
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('[data-olr-nav-drawer]').getAttribute('open'),null);
   } else {
    assert(await links.first().isVisible());
    const bounds=await page.locator('.site-header__inner').evaluate(e=>{const nav=e.querySelector('.olr-primary-nav--desktop').getBoundingClientRect(),brand=e.querySelector('.olr-brand').getBoundingClientRect(),cart=e.querySelector('.olr-header-actions').getBoundingClientRect();return {left:nav.left,right:nav.right,brand:brand.right,cartLeft:cart.left,cartRight:cart.right};});
    assert(bounds.left>=bounds.brand&&(bounds.right<=bounds.cartLeft||bounds.left>=bounds.cartRight),`desktop navigation overlap: ${JSON.stringify(bounds)}`);
   }
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'page overflow');
   console.log(`PASS ${width}px: Contact link, active state, menu layout`);
  }
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
