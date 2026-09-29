// Inspect only the disposable local admin fixture, never submit a form or reach TaxBandits.
const { chromium } = require('C:/Users/User/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const out = path.resolve('output/account-hub-1.2.7');
fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try {
  const page=await browser.newPage();
  await page.route('**/*',r=>new URL(r.request().url()).hostname==='127.0.0.1'?r.continue():r.abort());
  const results=[];
  for(const width of [320,375,768,1280]) {
   await page.setViewportSize({width,height:900});
   await page.goto('http://127.0.0.1:8338/taxbandits-sandbox.html');
   const result=await page.evaluate(()=>{
    const region=document.querySelector('#olr-taxbandits');
    const overflow=Array.from(region.querySelectorAll('*')).filter(e=>e.getBoundingClientRect().width>0&&e.getBoundingClientRect().right>innerWidth+1).map(e=>e.tagName+':'+e.textContent.slice(0,60));
    const forms=Array.from(region.querySelectorAll('form'));
    return {overflow,nested:!!region.querySelector('form form'),nonces:forms.every(f=>f.method==='post'&&f.querySelector('[name=_wpnonce]')),blankSecrets:Array.from(region.querySelectorAll('input[type=password]')).every(e=>e.value===''),passwordFields:region.querySelectorAll('input[type=password]').length,bodyText:region.textContent};
   });
   if(result.overflow.length||result.nested||!result.nonces||!result.blankSecrets||result.passwordFields!==7||!result.bodyText.includes('Administrator testing only')) throw Error(JSON.stringify({width,...result}));
   const view=page.locator('form').filter({has:page.locator('input[name=command][value=view]')});
   if(await view.count()!==1||await view.getAttribute('target')!=='_blank'||!await view.getByRole('button',{name:'View completed W-9',exact:true}).isVisible()) throw Error('Completed W-9 viewer missing');
   if([375,1280].includes(width)) await page.screenshot({path:path.join(out,'taxbandits-'+width+'.png'),fullPage:true});
   delete result.bodyText; results.push({width,...result});
  }
  const form=page.locator('form').filter({has:page.locator('input[name=command][value=save]')});
  if(await form.evaluate(f=>f.checkValidity())) throw Error('Empty credentials incorrectly accepted');
  await page.getByLabel('Client ID',{exact:true}).fill('fake-client-id-123');
  await page.getByLabel('Client Secret',{exact:true}).fill('fake-client-secret-123');
  await page.getByLabel('User Token',{exact:true}).fill('fake-user-token-123');
  if(!await form.evaluate(f=>f.checkValidity())) throw Error('Filled credential form rejected');
  fs.writeFileSync(path.join(out,'sandbox-layout.json'),JSON.stringify(results,null,2));
  console.log('PASS: four admin viewport sizes; no overflow/nested forms; nonce fields; blank masked secrets; credential form validity. No forms submitted.');
 } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
