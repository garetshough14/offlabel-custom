/* Local fixture QA: no live requests, cookies, database, or orders. */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.OLR_PLAYWRIGHT || 'playwright');
const repo = path.resolve(__dirname, '..');
const read = name => fs.readFileSync(path.join(repo, name), 'utf8');
const assets = 'wordpress-plugins/off-label-production-rollout/assets/';
const imagePath = path.join(repo, assets, 'product-images/epitalon-10mg.jpg');
const image = 'data:image/jpeg;base64,' + fs.readFileSync(imagePath).toString('base64');
const logo = 'data:image/webp;base64,' + fs.readFileSync(path.join(repo, 'branding/off-label-logo-cropped-black.webp')).toString('base64');
const localLogo = html => html.replaceAll('https://cdn.jsdelivr.net/gh/garetshough14/offlabel-custom@main/branding/off-label-logo-cropped-black.webp', logo);
const header = localLogo(read(assets + 'site-header.html')).replaceAll('[olr_cart_count]', '(3)');
const footer = localLogo(read(assets + 'site-footer.html'));
const css = ['styles.css', 'styles-product.css', assets + 'production-fixes.css', assets + 'customer-presentation.css'].map(name => {
  if (name === 'styles-product.css' && process.env.OLR_LEGACY_CSS === '1') {
    return require('node:child_process').execFileSync('git', ['show', 'HEAD:styles-product.css'], {cwd: repo, encoding: 'utf8'});
  }
  return read(name);
}).join('\n');

(async () => {
  const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  try {
    const page = await browser.newPage();
    // A synthetic page with real stylesheet, header/footer and canonical image.
    await page.route('**/*', route => route.request().url().startsWith('data:') ? route.continue() : route.abort());
    for (const width of [375, 390, 768, 1024, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      const html = `<base href="https://offlabelresearch.com/"><style>${css}</style>${header}
        <div class="olr-gitpress-site olr-redesign" data-olr-page="product-detail"><div class="olr-product-record__inner">
          <article class="olr-product-view"><div class="olr-product-view__gallery"><figure class="olr-product-view__media"><img class="olr-product-view__image" src="${image}"></figure></div>
          <div class="olr-product-view__summary"><h1>Epitalon</h1><div class="olr-product-view__intro">Hidden description</div><div class="olr-product-view__purchase"><p class="stock in-stock">4 in stock</p><p class="stock out-of-stock">Out of stock</p></div></div></article>
          <div class="olr-product-related__grid">${[1, 2, 3].map(() => `<a class="olr-product-related__item"><span class="olr-product-related__media"><img src="${image}"></span><span class="olr-product-related__copy"><strong>Epitalon</strong><span>$40</span></span></a>`).join('')}</div>
        </div></div>${footer}<footer class="olr-checkout-test__footer"><div><b>Support</b><a href="/shipping/">Shipping</a><a href="/returns/">Returns</a><a href="/terms-conditions/">Terms</a><a href="/privacy-policy/">Privacy</a></div></footer>`;
      const fixtureUrl = `https://offlabelresearch.com/olr-local-fixture/${width}`;
      await page.route(fixtureUrl, route => route.fulfill({contentType: 'text/html', body: html}));
      await page.goto(fixtureUrl);
      await page.addScriptTag({content: read(assets + 'footer-links.js')});
      await page.addScriptTag({content: read(assets + 'olr-site-navigation.js')});
      const metrics = await page.evaluate(() => {
        const boxes = [...document.querySelectorAll('.olr-product-view__media, .olr-product-related__media')].map(box => {
          const img = box.querySelector('img'), b = box.getBoundingClientRect(), i = img.getBoundingClientRect();
          return { w: b.width, h: b.height, iw: i.width, ih: i.height, cx: Math.abs(b.x + b.width / 2 - i.x - i.width / 2), cy: Math.abs(b.y + b.height / 2 - i.y - i.height / 2), fit: getComputedStyle(img).objectFit, background: getComputedStyle(box).backgroundColor, loaded: img.naturalHeight > img.naturalWidth };
        });
        return { boxes, hidden: ['.olr-product-view__intro', '.stock.in-stock'].every(s => getComputedStyle(document.querySelector(s)).display === 'none'), soldout: getComputedStyle(document.querySelector('.stock.out-of-stock')).display !== 'none', overflow: document.documentElement.scrollWidth > innerWidth + 1 };
      });
      for (const box of metrics.boxes) {
        assert.ok(box.w > 0 && box.h > 0 && box.loaded, JSON.stringify(box));
        assert.ok(box.cx < 1 && box.cy < 1 && box.iw <= box.w + 1 && box.ih <= box.h + 1, JSON.stringify(box));
        assert.equal(box.fit, 'contain');
        assert.equal(box.background, 'rgb(255, 255, 255)');
      }
      assert.ok(metrics.hidden && metrics.soldout && !metrics.overflow, JSON.stringify(metrics));
      assert.deepEqual(await page.locator('.olr-checkout-test__footer a').allTextContents(), ['Terms', 'Privacy']);
      assert.equal(await page.locator('.olr-checkout-test__footer a').first().getAttribute('href'), 'https://offlabelresearch.com/terms-and-conditions/');
      assert.deepEqual(await page.locator('.olr-footer-nav[aria-label="Support links"] a').allTextContents(), ['Terms', 'Privacy']);
      if (width < 896) {
        await page.locator('[data-olr-nav-toggle]').click();
        assert.ok(await page.locator('[data-olr-nav-drawer]').evaluate(e => e.open));
        assert.ok(await page.locator('[data-olr-nav-panel]').isVisible());
        await page.keyboard.press('Escape');
        assert.ok(!await page.locator('[data-olr-nav-drawer]').evaluate(e => e.open));
      }
      console.log(`PASS ${width}px: centered contained portraits, white media, no stock count, sold-out status retained, footer and navigation.`);
      if (width === 390) {
        const output = path.join(repo, 'output/presentation-update-1.0.13');
        fs.mkdirSync(output, {recursive: true});
        await page.screenshot({path: path.join(output, 'mobile-fixture.png'), fullPage: true});
      }
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
