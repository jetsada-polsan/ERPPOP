// Generate HTML with WF_BROWSER_FIXTURES=/tmp/wf-browser-fixtures php vendor/bin/phpunit tests/Feature/WorkflowPhaseOneTest.php
// Run with NODE_PATH=<directory containing playwright> node tests/browser/workflow-phase-one.cjs
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const fixtures = process.env.WF_BROWSER_FIXTURES || '/tmp/wf-browser-fixtures';
(async () => {
  const browser = await chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : { channel: 'chrome' }), headless: true });
  let checks = 0;
  try {
    const context = await browser.newContext({ viewport: { width: 1024, height: 600 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('dialog', dialog => dialog.accept());
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      const name = url.pathname.slice(1);
      if (['bookings', 'purchase-orders', 'pos', 'executive'].includes(name)) {
        return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(fixtures, name + '.html')) });
      }
      if (url.pathname.startsWith('/search/')) {
        return route.fulfill({ json: [] });
      }
      const file = path.join(root, 'public', name);
      if (file.startsWith(path.join(root, 'public') + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
        return route.fulfill({ path: file });
      }
      return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    });
    for (const name of ['bookings', 'purchase-orders']) {
      await page.goto('http://localhost/' + name);
      await page.waitForFunction(() => window.Alpine);
      const selector = name === 'bookings' ? '[x-data^="docEntryPage"]' : '[x-data="poPage()"]';
      await page.waitForFunction(selector => !!document.querySelector(selector)?._x_dataStack, selector);
      await page.evaluate(selector => {
        const state = Alpine.$data(document.querySelector(selector));
        state.modalOpen = true;
        state.items = Array.from({length: 50}, (_, i) => ({product_id: i+1, sku_code:'WF-'+i, name_th:'ทดสอบ', qty:i+1, unit_price:25, unit_name:'กก.'}));
      }, selector);
      await page.waitForTimeout(100);
      assert.equal(await page.locator(name === 'bookings' ? '.doc-modal tbody tr' : '.po-modal tbody tr').count(), 50); checks++;
      await page.locator(name === 'bookings' ? '.doc-modal input[name="items[0][qty]"]' : '.po-modal input[name="items[0][qty]"]').fill('123');
      await page.keyboard.press('Enter');
      assert.equal(new URL(page.url()).pathname, '/' + name); checks++;
      // Opening/closing the portaled picker must preserve the parent and all rows.
      await page.evaluate(selector => Alpine.$data(document.querySelector(selector)).openProductPicker(0), selector);
      await page.waitForTimeout(120);
      await page.keyboard.press('Escape');
      assert.equal(await page.evaluate(selector => Alpine.$data(document.querySelector(selector)).modalOpen, selector), true); checks++;
      await page.evaluate(selector => Alpine.$data(document.querySelector(selector)).closeModal(), selector);
      await page.reload();
      await page.waitForTimeout(250);
      const recovered = await page.evaluate(selector => {
        const state = Alpine.$data(document.querySelector(selector));
        return { count: state.items.length, qty: state.items[0].qty, dirty: state.draftDirty };
      }, selector);
      assert.equal(recovered.count, 50); assert.equal(recovered.qty, 123); assert.equal(recovered.dirty, true); checks += 3;
      // Recovery keeps a tab-local draft until the server confirms success.
      assert.equal(await page.evaluate(() => Object.keys(sessionStorage).some(key => key.startsWith('erp:draft:v1:'))), true); checks++;
      await page.evaluate(() => sessionStorage.clear());
    }
    // Out-of-order party responses: old results cannot replace the latest query.
    await page.goto('http://localhost/bookings');
    await page.waitForTimeout(150);
    const stale = await page.evaluate(async () => {
      const state = Alpine.$data(document.querySelector('[x-data^="docEntryPage"]'));
      const original = window.fetch;
      const pending = [];
      window.fetch = () => new Promise(resolve => pending.push(resolve));
      try {
        state.partyQuery = 'old'; const first = state.searchParty();
        state.partyQuery = 'new'; const second = state.searchParty();
        pending[1]({ok:true, json:async()=>[{id:2, code:'NEW'}]}); await second;
        pending[0]({ok:true, json:async()=>[{id:1, code:'OLD'}]}); await first;
        return state.partyResults[0].code;
      } finally { window.fetch = original; }
    });
    assert.equal(stale, 'NEW'); checks++;
    await page.evaluate(() => sessionStorage.clear());
    // Real web POS markup: footer remains reachable with body content scrolled.
    await page.goto('http://localhost/pos');
    await page.waitForTimeout(200);
    await page.evaluate(() => {
      const dialog = document.querySelector('.close-shift-dialog');
      dialog.parentElement.style.display = 'flex';
      dialog.parentElement.removeAttribute('x-show');
      dialog.querySelector('.shift-modal-body').scrollTop = 10000;
    });
    for (const viewport of [{width:1024,height:600},{width:375,height:420}]) {
      await page.setViewportSize(viewport);
      const box = await page.locator('.close-shift-dialog .btn-confirm').boundingBox();
      assert.ok(box && box.y >= 0 && box.y + box.height <= viewport.height); checks++;
    }
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ passed: checks, browser: await browser.version(), pageErrors: errors }));
    await context.close();
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
