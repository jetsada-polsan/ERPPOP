const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH, headless: true });
    const page = await browser.newPage();
    await page.setContent(`<form data-confirm="Confirm"><input required name="amount"><button name="post" value="yes">Save</button><button name="reset" value="1" formnovalidate data-confirm="Reset">Reset</button></form>`);
    await page.evaluate(() => {
        window.choice = false; window.prompts = 0; window.submissions = [];
        window.erpConfirm = async () => { window.prompts++; return { isConfirmed: window.choice }; };
        window.confirm = () => { throw new Error('Native confirm must never be called'); };
    });
    await page.addScriptTag({ content: fs.readFileSync('resources/views/components/confirm-action.blade.php', 'utf8').replace(/<\/?script>/g, '') });
    await page.evaluate(() => document.querySelector('form').addEventListener('submit', event => {
        event.preventDefault(); window.submissions.push(event.submitter?.name);
    }));
    await page.getByText('Save', { exact: true }).click();
    assert.equal(await page.evaluate(() => window.prompts), 0, 'HTML validation runs before confirmation');
    await page.locator('input').fill('15');
    await page.getByText('Save', { exact: true }).click();
    assert.equal(await page.evaluate(() => window.submissions.length), 0, 'Cancel never submits');
    await page.evaluate(() => window.choice = true);
    await page.getByText('Save', { exact: true }).click();
    await page.waitForFunction(() => window.submissions.length === 1);
    assert.deepEqual(await page.evaluate(() => window.submissions), ['post']);
    await page.locator('input').fill('');
    await page.getByText('Reset', { exact: true }).click();
    await page.waitForFunction(() => window.submissions.length === 2);
    assert.deepEqual(await page.evaluate(() => window.submissions), ['post', 'reset']);
    assert.equal(await page.evaluate(() => window.prompts), 3, 'Each action prompts once');
    await browser.close();
    console.log('Passed confirmation cancel, validation, submit-once and submitter preservation checks');
})().catch(e => { console.error(e); process.exit(1); });
