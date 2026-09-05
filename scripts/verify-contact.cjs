const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('C:/Users/User/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = path.resolve(__dirname, '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const out = path.join(root, 'output/contact-page');
fs.mkdirSync(out, { recursive: true });
// Layout fixture follows the inspected, saved Fluent Forms 3 markup. No submission endpoint or tokens.
const field = (name, label, placeholder, type = 'text', required = true) => `<div class="ff-el-group"><div class="ff-el-input--label ${required ? 'ff-el-is-required' : ''} asterisk-right"><label for="${name}">${label}</label></div><div class="ff-el-input--content">${type === 'textarea' ? `<textarea rows="6" cols="2"` : `<input type="${type}"`} id="${name}" name="${name}" class="ff-el-form-control" placeholder="${placeholder}" aria-required="${required}">${type === 'textarea' ? '</textarea>' : ''}</div></div>`;
const form = `<div class="fluentform"><form class="frm-fluent-form" onsubmit="return false"><fieldset style="border:0;margin:0;padding:0;min-inline-size:100%"><div class="ff-name-field-wrapper"><div class="ff-t-container"><div class="ff-t-cell">${field('first_name','First Name','First Name')}</div><div class="ff-t-cell">${field('last_name','Last Name','Last Name')}</div></div></div>${field('email','Email Address','Email Address','email')}${field('numeric_field','Order Number','Optional','number',false)}${field('input_text','Message','How can we help?','textarea')}<div class="ff-el-group ff-text-left ff_submit_btn_wrapper"><button class="ff-btn ff-btn-submit" type="submit">SEND MESSAGE</button></div></fieldset></form></div>`;
(async () => {
  const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const page = await browser.newPage();
  await page.route('**/*', route => route.abort());
  const source = read('gitpress/pages/contact.html');
  assert(source.includes('[fluentform id="3"]'));
  assert(!source.includes('<script'));
  for (const width of [320,390,511,768,1440]) {
    await page.setViewportSize({width,height:1000});
    await page.setContent(`<html><head><meta charset="UTF-8"><style>${read('styles.css')} body {margin:0;} .ff-el-is-required label::after {content:' *';}</style></head><body id="olr-type-system" class="olr-gitpress-site olr-native-support-page olr-native-page--contact">${source.replace('[fluentform id="3"]',form)}</body></html>`);
    const checks = await page.evaluate(() => ({overflow:document.documentElement.scrollWidth>innerWidth, textarea:document.querySelector('textarea').getBoundingClientRect().height, button:getComputedStyle(document.querySelector('button')).backgroundColor, controls:[...document.querySelectorAll('input,textarea,button')].every(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth;}), font:getComputedStyle(document.querySelector('h1')).fontFamily}));
    assert(!checks.overflow, `${width}: horizontal overflow`);
    assert(checks.controls, `${width}: control overflow`);
    assert(checks.textarea>=150, `${width}: textarea too short`);
    assert.equal(checks.button,'rgb(8, 8, 8)');
    await page.screenshot({path:path.join(out,`contact-${width}.png`),fullPage:true});
    console.log(width,checks);
  }
  for(const [file,label] of [['gitpress/emails/contact-autoresponder.html','email'],['gitpress/messages/contact-success.html','success']]) {
    for(const width of [320,600]) {
      await page.setViewportSize({width,height:850});
      await page.setContent(`<html><head><meta charset="UTF-8"></head><body style="margin:0">${read(file)}</body></html>`);
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${label} overflow`);
      await page.screenshot({path:path.join(out,`${label}-${width}.png`),fullPage:true});
    }
  }
  await browser.close();
  console.log('PASS: five page widths, two email widths, two confirmation widths. No submissions sent.');
})().catch(e=>{console.error(e);process.exit(1);});
