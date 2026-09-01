/* eslint-env node */
'use strict';

const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');

const fixturePath = path.join(__dirname, 'visual-fixture.html');
const html = fs.readFileSync(fixturePath, 'utf8');
const virtualConsole = new VirtualConsole();
virtualConsole.sendTo(console, { omitJSDOMErrors: false });

function assert(condition, message) {
  if (!condition) {
    console.error(`FAIL: ${message}`);
    process.exit(1);
  }
  console.log(`PASS: ${message}`);
}

const dom = new JSDOM(html, {
  url: `file:///${fixturePath.replace(/\\/g, '/')}`,
  runScripts: 'dangerously',
  resources: 'usable',
  pretendToBeVisual: true,
  virtualConsole
});

dom.window.addEventListener('load', () => {
  const root = dom.window.document.querySelector('[data-olr-build-box]');
  const addButtons = root.querySelectorAll('[data-add-product]');
  addButtons[0].click();
  addButtons[0].click();
  addButtons[1].click();
  addButtons[1].click();
  addButtons[1].click();

  assert(root.querySelector('[data-box-count]').textContent === '5', 'Five button selections complete The Five.');
  assert(root.querySelectorAll('.olr-build-box__summary-item').length === 2, 'Duplicate bottles aggregate into concise summary rows.');
  assert(root.querySelector('[data-subtotal]').textContent === '$340.00', 'The client subtotal uses server-provided display prices.');
  assert(root.querySelector('[data-savings]').textContent === '−$85.00', 'The Five preview calculates 25% savings.');
  assert(root.querySelector('[data-total]').textContent === '$255.00', 'The completed box preview calculates the discounted total.');
  assert(root.querySelector('[data-submit-box]').disabled === false, 'Submission unlocks only at the exact tier quantity.');

  addButtons[2].click();
  assert(root.querySelector('[data-box-count]').textContent === '5', 'The browser state cannot exceed the selected box size.');
  assert(root.querySelector('[data-builder-alert]').hidden === false, 'An accessible alert explains a full box.');

  const ten = root.querySelector('[data-box-tier][value="10"]');
  ten.click();
  ten.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert(root.querySelector('[data-box-limit]').textContent === '10', 'Customers can expand a completed Five into The Ten.');
  assert(root.querySelector('[data-submit-box]').disabled === true, 'The Ten remains locked until it reaches ten bottles.');

  root.querySelector('[data-start-over]').click();
  assert(root.querySelector('[data-box-count]').textContent === '0', 'Start over clears every selected bottle.');
  assert(root.querySelector('[data-submit-box]').disabled === true, 'An empty box cannot be submitted.');

  console.log('DOM smoke checks completed.');
  dom.window.close();
});

