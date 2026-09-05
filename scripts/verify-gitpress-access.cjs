const {spawnSync} = require('node:child_process');
const assert = require('node:assert/strict');
const path = require('node:path');
const php = process.env.OLR_PHP;
function run(scenario, legacy = false, mode = 'gitpress_managed', bridge = false) {
  const r = spawnSync(php, [path.join(__dirname, 'verify-gitpress-access-worker.php'), scenario, legacy ? 'legacy' : 'patched', mode, bridge ? 'bridge' : ''], {encoding: 'utf8'});
  assert.equal(r.status, 0, r.stderr + r.stdout);
  return {html: r.stdout.replace(/\r\n/g, '\n'), meta: JSON.parse(r.stderr)};
}
for (const mode of ['gitpress_managed', 'full_canvas']) {
  const before = run('guest', true, mode);
  assert.ok(before.html.includes('[fixture_body]'));
  assert.ok(!before.meta.events.includes('before-um'));
  for (const scenario of ['guest', 'catalog', 'product', 'coas', 'cart']) {
    const after = run(scenario, false, mode);
    assert.equal(after.html, 'REDIRECT');
    assert.ok(after.meta.events.some(e => e.startsWith('redirect:https://offlabelresearch.com/login?redirect_to=')));
    assert.ok(!after.meta.events.some(e => e.startsWith('render:')));
    assert.ok(after.meta.nocache && after.meta.cache_flag);
  }
  for (const scenario of ['member', 'admin-member', 'login', 'register', 'reset', 'terms', 'privacy', 'public-site']) {
    const before = run(scenario, true, mode), after = run(scenario, false, mode);
    assert.equal(after.html, before.html, `${scenario} ${mode}: markup changed`);
    assert.ok(after.meta.events.includes('after-um'));
    assert.equal(after.meta.nocache, scenario !== 'public-site');
  }
  console.log(`PASS ${mode}: reproduced original guest bypass; actual UM redirects before any rendering; members/public exceptions preserve identical HTML.`);
}
for (const scenario of ['rest', 'xmlrpc', 'wc-api', 'ajax', 'cron', 'admin-request']) {
  const after = run(scenario);
  assert.equal(after.html, 'SERVICE_TEMPLATE');
  assert.equal(after.meta.nocache, false);
}
for (const scenario of ['confirmation', 'pay', '404']) {
  const after = run(scenario);
  assert.equal(after.html, 'THEME_TEMPLATE');
  assert.equal(after.meta.nocache, false);
}
assert.equal(run('expired-confirmation').html, 'REDIRECT');
assert.equal(run('member', false, 'theme_wrapped').html, 'THEME_TEMPLATE');
console.log('PASS: service requests and authenticated order endpoints are untouched; expired order sessions still require UM login; theme-wrapped pages retained.');
for (const scenario of ['guest', 'catalog', 'product', 'coas', 'cart', 'member', 'login', 'register', 'reset', 'terms', 'privacy', 'confirmation']) {
  const without = run(scenario), withBridge = run(scenario, false, 'gitpress_managed', true);
  assert.equal(withBridge.html, without.html, `Bridge changed access/render result: ${scenario}`);
  assert.deepEqual(withBridge.meta, without.meta, `Bridge changed lifecycle: ${scenario}`);
}
console.log('PASS: local WooCommerce bridge loaded; access outcomes and markup match without the bridge across 12 scenarios.');
