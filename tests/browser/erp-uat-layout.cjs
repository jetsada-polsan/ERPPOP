// Run after UatLocalVisualFixtureTest exports local HTML snapshots. Never connects to ERP.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, headless: true });
    const page = await browser.newPage();
    const base = 'http://127.0.0.1:8876';
    await page.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    let checks = 0;
    for (const width of [375, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        for (const layout of ['classic', 'odoo']) {
            for (const name of ['quotations', 'billing-notes', 'scale-prices']) {
                await page.goto(`${base}/${name}-${layout}.html`);
                await page.locator('.erp-page-heading h1').waitFor();
                const overlap = await page.evaluate(() => {
                    const title = document.querySelector('.erp-page-heading h1').getBoundingClientRect();
                    return [...document.querySelectorAll('.app-header > .container-fluid > :not(.erp-page-heading)')].some(el => {
                        const r = el.getBoundingClientRect();
                        return r.width && r.height && r.left < title.right && r.right > title.left && r.top < title.bottom && r.bottom > title.top;
                    });
                });
                assert.equal(overlap, false, `${name}/${layout}/${width}: header overlaps title`);
                if (name === 'scale-prices' && width <= 1024) {
                    const issue = page.locator('.scale-plu-table td[data-label="ปัญหา / วิธีแก้"]').first();
                    const r = await issue.boundingBox();
                    assert.ok(r && r.x >= 0 && r.x + r.width <= width + 1, `${layout}/${width}: issue clipped`);
                    assert.ok((await issue.textContent()).trim().length > 0, 'Issue text must be present');
                    assert.equal(await page.locator('.scale-plu-table').evaluate(el => el.scrollWidth > el.clientWidth + 1), false);
                }
                checks++;
            }
        }
    }
    await page.screenshot({ path: '/tmp/erp-uat-preview/layout-1440.png', fullPage: true });
    await page.setViewportSize({ width: 768, height: 1000 });
    await page.goto(`${base}/scale-prices-odoo.html`);
    await page.screenshot({ path: '/tmp/erp-uat-preview/plu-768.png', fullPage: true });
    console.log(`Passed ${checks} responsive page/layout/viewport checks`);
    await browser.close();
})().catch(error => { console.error(error); process.exit(1); });
