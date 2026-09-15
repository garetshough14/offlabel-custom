// Read-only browser checks against the disposable local PHP-rendered fixtures.
const { chromium } = require('C:/Users/User/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const out = path.resolve(process.env.OLR_QA_OUTPUT || 'output/account-hub-1.2.4');
fs.mkdirSync(out, {recursive:true});
(async () => {
  const browser = await chromium.launch({headless:true, executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
  const page = await browser.newPage();
  await page.route('**/*', route => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
  const tabs = await (await page.request.get('http://127.0.0.1:8338/tabs.json')).json();
  const results=[];
  for (const width of [320,375,768,1280]) {
    await page.setViewportSize({width, height:900});
    for (const tab of tabs) {
      await page.goto('http://127.0.0.1:8338/tab-'+tab+'.html');
      // Themes may force a display value onto all account buttons. The mobile
      // toggle must still be absent on desktop and precede content on mobile.
      await page.addStyleTag({content:'.olr-account-hub button { display: inline-block !important; }'});
      const menu=page.locator('.olr-account-menu-toggle');
      if(await menu.count()!==1 || await menu.isVisible()!==(width<=800)) throw new Error('Account menu visibility: '+tab+' at '+width);
      if(width<=800) {
        if(await menu.evaluate(e=>e.getBoundingClientRect().bottom>document.querySelector('.um-account-main').getBoundingClientRect().top+2)) throw new Error('Account menu appears below content: '+tab);
        await menu.click();
        if(await menu.getAttribute('aria-expanded')!=='true' || !await page.locator('.um-account-side').isVisible()) throw new Error('Mobile menu did not open: '+tab);
        await page.keyboard.press('Escape');
        if(await menu.getAttribute('aria-expanded')!=='false' || await page.locator('.um-account-side').isVisible() || !await menu.evaluate(e=>e===document.activeElement)) throw new Error('Mobile menu did not close/focus: '+tab);
      } else if(!await page.locator('.um-account-side').isVisible()) throw new Error('Desktop navigation missing: '+tab);
      const result = await page.evaluate(() => {
        const main=document.querySelector('.um-account-main');
        const visible=e=>e.getBoundingClientRect().height>0 && getComputedStyle(e).visibility!=='hidden';
        const headings=Array.from(main.querySelectorAll('h1,h2,h3,h4')).filter(visible).map(e=>({text:e.textContent.trim(),size:parseFloat(getComputedStyle(e).fontSize)}));
        const overflow=Array.from(main.querySelectorAll('*')).filter(e=>visible(e)&&!e.closest('thead')&&e.getBoundingClientRect().width>1&&e.getBoundingClientRect().right>innerWidth+2).map(e=>({tag:e.tagName,cls:e.className,text:e.textContent.trim().slice(0,60),right:Math.round(e.getBoundingClientRect().right)}));
        const cells=Array.from(main.querySelectorAll('.olr-account-table tbody td')).filter(visible).map(e=>({text:e.textContent.trim(),width:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right,size:parseFloat(getComputedStyle(e).fontSize)}));
        const valueOverflow=Array.from(main.querySelectorAll('.olr-account-table tbody td')).filter(visible).flatMap(e=>Array.from(e.childNodes).filter(n=>n.nodeType===3&&n.textContent.trim()).flatMap(n=>{const range=document.createRange();range.selectNodeContents(n);return Array.from(range.getClientRects()).filter(r=>r.right>innerWidth+2).map(r=>n.textContent.trim());}));
        const accents=Array.from(main.querySelectorAll('*')).filter(visible).flatMap(e=>{const s=getComputedStyle(e);return ['color','backgroundColor','borderTopColor','borderBottomColor'].flatMap(k=>{const c=s[k].match(/[\d.]+/g);return c&&Number(c[3]??1)>0&&Math.max(...c.slice(0,3))-Math.min(...c.slice(0,3))>16?[{cls:e.className,property:k,color:s[k]}]:[];});});
        return {headings,overflow,cells,valueOverflow,accents,mainText:main.innerText.slice(0,140),obsoleteWarning:main.textContent.includes('Please select a payout method'),opacity:getComputedStyle(document.querySelector('.um')).opacity};
      });
      results.push({width,tab,...result});
      if ([375,1280].includes(width) && ['affiliate','payouts','general','commissions','performance','creative','overview','guidelines'].includes(tab)) await page.screenshot({path:path.join(out,tab+'-'+width+'.png'),fullPage:true});
      if (width===375 && tab==='affiliate') {
        await page.locator('.olr-affiliate-recent').screenshot({path:path.join(out,'mobile-commissions.png')});
        await page.locator('.olr-affiliate-earnings-card').screenshot({path:path.join(out,'mobile-earnings.png')});
        await page.locator('.olr-code-editor summary').click();
        await page.locator('#olr-custom-code').fill('MY-PERSONAL-CODE');
        await page.locator('.olr-affiliate-access-card').screenshot({path:path.join(out,'mobile-code-editor.png')});
        if(!await page.locator('.olr-code-editor form').evaluate(f=>f.checkValidity())) throw new Error('Valid custom coupon rejected by form');
        if(await page.locator('.olr-code-editor').evaluate(e=>e.scrollWidth>e.clientWidth+2)) throw new Error('Custom code editor overflows mobile layout');
      }
    }
  }
  fs.writeFileSync(path.join(out,'responsive.json'),JSON.stringify(results,null,2));
  await page.goto('http://127.0.0.1:8338/tab-overview.html');
  for(const width of [800,801,375,1280,800]) {
    await page.setViewportSize({width,height:900});
    await page.waitForFunction(mobile=>document.querySelector('.olr-account-menu-toggle').hidden!==mobile,width<=800);
    const menu=page.locator('.olr-account-menu-toggle');
    if(await menu.isVisible()!==(width<=800) || await menu.getAttribute('aria-expanded')!=='false') throw new Error('Menu resize mismatch at '+width);
    if(width<=800) await menu.click();
    else if(!await page.locator('.um-account-side').isVisible()) throw new Error('Sidebar lost after resize');
  }
  console.log('PASS: desktop toggle hidden, mobile menu before content, open/Escape/focus, and 800/801px resize boundary');
  const failures=results.filter(r=>r.overflow.length || r.valueOverflow.length || r.accents.length || r.obsoleteWarning || r.headings.some(h=>h.size>28) || !r.mainText.trim() || Number(r.opacity)===0 || (r.tab==='affiliate' && !r.cells.some(c=>c.text.includes('68.58'))));
  console.log(JSON.stringify({checks:results.length,failures:failures.map(r=>({tab:r.tab,width:r.width,overflow:r.overflow.slice(0,8),largeHeadings:r.headings.filter(h=>h.size>28)}))},null,2));
  let requestValid=false;
  await page.route('**/admin-ajax.php', async route=>{
    const request=route.request();
    requestValid=request.method()==='POST' && request.postData().includes('olr_prepare_affiliate_code') && request.postData().includes('olr_aff_nonce');
    await route.fulfill({json:{success:true,data:{code:'OLR-QA-REPAIRED'}}});
  });
  await page.goto('http://127.0.0.1:8338/code-setup.html');
  await page.waitForFunction(()=>document.querySelector('[data-olr-code-copy]').hidden===false);
  if(!requestValid || await page.locator('[data-olr-code-value]').textContent()!=='OLR-QA-REPAIRED') throw new Error('Code preparation UI failed');
  await page.context().grantPermissions(['clipboard-read','clipboard-write']);
  await page.locator('[data-olr-code-copy]').click();
  await page.waitForFunction(()=>document.querySelector('[data-olr-code-copy]').textContent==='Copied');
  if(await page.evaluate(()=>navigator.clipboard.readText())!=='OLR-QA-REPAIRED') throw new Error('Repaired code copy failed');
  console.log('PASS: automatic protected POST updates code and copy control (mocked API response; real service covered by PHP tests)');
  await browser.close();
  if(failures.length) process.exitCode=1;
})().catch(e=>{console.error(e);process.exitCode=1;});
