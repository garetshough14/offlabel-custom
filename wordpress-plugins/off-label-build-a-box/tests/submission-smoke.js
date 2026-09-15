'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { JSDOM, VirtualConsole } = require('jsdom');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'olr-box-submit-'));
const storage = path.join(directory, 'cart.json');
const fixture = fs.readFileSync(path.join(__dirname, 'visual-fixture.html'), 'utf8');
const source = fs.readFileSync(path.join(__dirname, '../assets/build-a-box.js'), 'utf8');

function request(data) {
  const result = spawnSync(process.env.OLR_TEST_PHP || 'php', [path.join(__dirname, 'ajax-worker.php')], {
    input: JSON.stringify({ storage, ...data }), encoding: 'utf8'
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return JSON.parse(result.stdout);
}

async function submit(options = {}) {
  const { expired = false, initial = {}, tier = 5, editBox = '', intercept, failAfter = 0 } = options;
  fs.writeFileSync(storage, JSON.stringify(initial));
  const nonce = request({ mode: 'nonce', expired }).body.data.nonce;
  const calls = [];
  const dom = new JSDOM(fixture, {
    url: 'https://example.test/build-your-box/', runScripts: 'outside-only',
    virtualConsole: new VirtualConsole()
  });
  dom.window.olrBuildBox = { ajaxUrl: '/wp-admin/admin-ajax.php', nonce, messages: {} };
  dom.window.fetch = async (url, options) => {
    const post = Object.fromEntries(options.body.entries());
    calls.push(post);
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.cache, 'no-store');
    const result = (intercept && await intercept(post, calls)) || request({ mode: 'ajax', post, failAfter });
    return { ok: result.status < 400, status: result.status, json: async () => result.body };
  };
  const root = dom.window.document.querySelector('[data-olr-build-box]');
  root.dataset.tier = String(tier);
  root.dataset.editBox = editBox;
  dom.window.eval(source);
  const buttons = root.querySelectorAll('[data-add-product]');
  const selections = options.selections || [[0, tier]];
  for (const [index, quantity] of selections) for (let i = 0; i < quantity; i++) buttons[index].click();
  const button = root.querySelector('[data-submit-box]');
  const label = button.textContent;
  button.click();
  assert.equal(button.disabled, true, 'submission disables the button immediately');
  assert.equal(button.getAttribute('aria-busy'), 'true');
  assert.match(button.textContent, /Adding your box/);
  button.click(); // A second click during submission must not create a second save.
  for (let i = 0; i < 20; i++) await new Promise(resolve => setImmediate(resolve));
  const cart = JSON.parse(fs.readFileSync(storage, 'utf8'));
  const message = root.querySelector('[data-builder-alert]').textContent;
  const failed = !root.querySelector('[data-builder-alert]').hidden;
  if (failed) {
    assert.equal(root.querySelector('[data-box-count]').textContent, String(tier), 'failed requests preserve selected bottles');
    assert.equal(button.disabled, false, 'failed requests unlock the submit button');
    assert.equal(button.getAttribute('aria-busy'), 'false');
    assert.equal(button.textContent, label);
  }
  dom.window.close();
  return { cart, calls, message, failed };
}

function boxes(cart) { return Object.values(cart).filter(item => item.olr_box_id); }
function quantity(cart) { return boxes(cart).reduce((sum, item) => sum + item.quantity, 0); }
function actions(result) { return result.calls.map(call => call.action); }
const recovery = ['olr_save_box', 'olr_box_nonce', 'olr_save_box'];
function verify(condition, message) { assert.ok(condition, message); console.log(`PASS: ${message}`); }

(async () => {
  try {
    const current = await submit();
    verify(quantity(current.cart) === 5, 'a current-token submission persists five bottles');
    assert.deepEqual(actions(current), ['olr_save_box']);
    const expired = await submit({ expired: true });
    verify(quantity(expired.cart) === 5, `an expired page token still saves the completed box (saved=${quantity(expired.cart)}, alert=${expired.message})`);
    assert.deepEqual(actions(expired), recovery);
    const [first, , retry] = expired.calls;
    assert.deepEqual({ ...first, nonce: '' }, { ...retry, nonce: '' }, 'recovery resubmits exactly the original selection/tier/edit ID');
    assert.notEqual(first.nonce, retry.nonce);
    verify(new Set(boxes(expired.cart).map(item => item.olr_box_id)).size === 1, 'recovery and repeated clicks create exactly one box');
    verify(boxes(expired.cart).filter(item => item.olr_box_role === 'parent').length === 1, 'saved box has one visible parent and its native allocations');

    const ordinary = { product_id: 102, variation_id: 0, variation: [], quantity: 2 };
    const ten = await submit({ expired: true, tier: 10, selections: [[0, 4], [1, 6]], initial: { ordinary } });
    verify(quantity(ten.cart) === 10 && boxes(ten.cart).every(item => item.olr_box_rate === 0.35), 'mixed ten-bottle recovery preserves the 35% discount');
    assert.deepEqual(ten.cart.ordinary, ordinary);
    verify(boxes(expired.cart).every(item => item.olr_box_rate === 0.25), 'five-bottle recovery preserves the 25% discount');

    const boxId = boxes(ten.cart)[0].olr_box_id;
    const edit = await submit({ expired: true, initial: ten.cart, editBox: boxId, selections: [[0, 2], [1, 3]] });
    verify(quantity(edit.cart) === 5 && boxes(edit.cart).every(item => item.olr_box_id === boxId), 'expired-token editing replaces only the requested box');
    assert.deepEqual(edit.cart.ordinary, ordinary);

    const legacy = await submit({ intercept: (post, calls) => calls.length === 1 ? { status: 403, body: -1 } : null });
    verify(quantity(legacy.cart) === 5, 'legacy WordPress -1 nonce rejection recovers during a rolling update');
    assert.deepEqual(actions(legacy), recovery);

    const rejected = await submit({ expired: true, intercept: post => post.action === 'olr_save_box' ? { status: 403, body: { success: false, data: { code: 'invalid_nonce', message: 'Expired' } } } : null });
    verify(rejected.failed && quantity(rejected.cart) === 0, 'a second nonce rejection stops and keeps the selection');
    assert.deepEqual(actions(rejected), recovery);

    const failedRenewal = await submit({ expired: true, intercept: post => post.action === 'olr_box_nonce' ? { status: 503, body: { success: false } } : null });
    verify(failedRenewal.failed && quantity(failedRenewal.cart) === 0, 'nonce renewal failure does not issue another save');
    assert.deepEqual(actions(failedRenewal), ['olr_save_box', 'olr_box_nonce']);

    for (const status of [400, 403, 409, 500, 503]) {
      const failed = await submit({ intercept: () => ({ status, body: { success: false, data: { message: 'Bottle unavailable' } } }) });
      verify(failed.failed && quantity(failed.cart) === 0, `HTTP ${status} errors preserve the selection and are not automatically retried`);
      assert.deepEqual(actions(failed), ['olr_save_box']);
      assert.equal(failed.message, 'Bottle unavailable');
    }
    const lostResponse = await submit({ intercept: post => {
      request({ mode: 'ajax', post }); // Server saved, but the client never receives success.
      throw new Error('Connection interrupted');
    } });
    verify(lostResponse.failed && quantity(lostResponse.cart) === 5, 'a lost success response is not replayed and cannot automatically duplicate the box');
    assert.deepEqual(actions(lostResponse), ['olr_save_box']);

    const rollback = await submit({ initial: edit.cart, editBox: boxId, failAfter: 2 });
    verify(rollback.failed, 'a rejected component reports a save failure');
    assert.deepEqual(rollback.cart, edit.cart, 'failed editing restores the original box and unrelated cart products');

    fs.writeFileSync(storage, JSON.stringify(edit.cart));
    const invalid = request({ mode: 'ajax', post: { action: 'olr_save_box', nonce: 'invalid', tier: '5', items: JSON.stringify([{ product_id: 101, quantity: 5 }]) } });
    assert.equal(invalid.status, 403);
    assert.equal(invalid.body.data.code, 'invalid_nonce');
    assert.deepEqual(JSON.parse(fs.readFileSync(storage, 'utf8')), edit.cart);
    const renewal = request({ mode: 'ajax', post: { action: 'olr_box_nonce' } });
    assert.equal(renewal.body.success, true);
    assert.deepEqual(JSON.parse(fs.readFileSync(storage, 'utf8')), edit.cart);
    verify(true, 'invalid nonces remain rejected and token renewal never changes the cart');
  }
  finally { fs.rmSync(directory, { recursive: true, force: true }); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
