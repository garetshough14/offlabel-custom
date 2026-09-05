const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {chromium}=require(process.env.OLR_PLAYWRIGHT || 'playwright');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const art='data:image/png;base64,'+fs.readFileSync(path.join(root,'wordpress-plugins/off-label-site-controls/assets/build-box-transparent-v1.png')).toString('base64');
const php=execFileSync('C:/Users/User/AppData/Local/Temp/olr-presentation-qa-1013/php/php.exe',[path.join(root,'scripts/verify-site-controls.php'),'--box-fixture'],{encoding:'utf8'});
const fixture=php.split('BOX_FIXTURE_START\n')[1];
const hero=fixture.match(/<section class="olr-build-box__hero"[\s\S]*?<\/section>/)[0].replace(/https:[^"]+build-box-transparent-v1.png/,art);
(async()=>{
 const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 try {
  const page=await browser.newPage();await page.route('**/*',r=>r.abort());
  for(const width of [375,390,768,1024,1440,1920]) {
   await page.setViewportSize({width,height:1100});
   await page.setContent(`<style>html,body{margin:0}${read('styles.css')}\n${read('wordpress-plugins/off-label-build-a-box/assets/build-a-box.css')}</style><div class="olr-build-box">${hero}</div>`);
   const section=page.locator('.olr-build-box__hero');
   const before=await section.boundingBox();
   const textBefore=await page.locator('.olr-build-box__hero-copy').boundingBox();
   await page.addStyleTag({content:read('wordpress-plugins/off-label-site-controls/assets/site-controls.css')});
   await page.locator('[data-olr-box-artwork]').evaluate(i=>i.decode());
   assert.equal((await section.boundingBox()).width,before.width);
   assert.ok((await page.locator('.olr-build-box__hero-copy').boundingBox()).height<=textBefore.height,'Requested smaller headline should not enlarge the copy');
   const metrics=await page.locator('[data-olr-box-artwork]').evaluate(i=>{
    const b=i.getBoundingClientRect(),p=i.parentElement.getBoundingClientRect();
    const c=document.createElement('canvas');c.width=i.naturalWidth;c.height=i.naturalHeight;const ctx=c.getContext('2d');ctx.drawImage(i,0,0);const px=ctx.getImageData(0,0,c.width,c.height).data;let clear=0,partial=0;for(let n=3;n<px.length;n+=4){if(px[n]===0)clear++;else if(px[n]<255)partial++;}
    return {fit:getComputedStyle(i).objectFit,contained:b.left>=p.left-1&&b.right<=p.right+1&&b.top>=p.top-1&&b.bottom<=p.bottom+1,clear:clear/(px.length/4),partial,corner:px[3]};
   });
   assert.equal(metrics.fit,'contain');assert.ok(metrics.contained);assert.ok(metrics.clear>.2);assert.ok(metrics.partial>0);assert.equal(metrics.corner,0);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   await section.screenshot({path:path.join(root,`output/site-controls/box-hero-${width}.png`)});
   console.log(`PASS ${width}px: real alpha, clean fit, no overflow, preserved hero width with smaller copy.`);
  }
 } finally {await browser.close();}
})();
