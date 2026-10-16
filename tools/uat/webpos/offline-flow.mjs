import { chromium } from 'playwright';
const BASE = 'http://127.0.0.1:8125';
const log = (k, v) => console.log(`CHECK ${k}: ${typeof v === 'string' ? v : JSON.stringify(v)}`);
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }).catch(() => chromium.launch());
const ctx = await b.newContext();
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
await page.goto(BASE + '/login');
await page.fill('#username', 'uatcashier');
await page.fill('#password', 'Uat-Pass-2026!');
await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
await page.goto(BASE + '/pos');
await page.waitForFunction(() => window.Alpine && Alpine.$data(document.body).catalogCount > 0, null, { timeout: 30000 });
const app = (fn, arg) => page.evaluate(fn, arg);
log('catalog_in_indexeddb', await app(() => Alpine.$data(document.body).catalogCount));
log('active_shift', await app(() => Alpine.$data(document.body).activeShift?.shift_no));
await page.waitForFunction(() => navigator.serviceWorker && navigator.serviceWorker.controller, null, { timeout: 15000 }).catch(() => {});
if (!(await app(() => !!navigator.serviceWorker.controller))) { await page.reload(); await page.waitForFunction(() => window.Alpine && Alpine.$data(document.body).catalogCount > 0); }
log('service_worker_controls_page', await app(() => !!navigator.serviceWorker.controller));

// keyboard wedge: same barcode twice fast -> qty 2
const input = page.locator('.pos-search-input');
for (let i = 0; i < 2; i++) { await input.fill('8850000000013'); await input.press('Enter'); await page.waitForTimeout(150); }
await page.waitForTimeout(1200);
log('wedge_qty_after_two_fast_scans', await app(() => Alpine.$data(document.body).cart.reduce((s, i) => s + Number(i.qty), 0)));

// camera path (acceptScannerCode) -> must add (bug C1)
await app(() => Alpine.$data(document.body).acceptScannerCode('8850000000013', 'camera'));
await page.waitForTimeout(800);
log('qty_after_camera_scan', await app(() => Alpine.$data(document.body).cart.reduce((s, i) => s + Number(i.qty), 0)));

// online checkout
await app(async () => { const a = Alpine.$data(document.body); a.method = 'cash'; a.received = a.totalAmount; await a.processPayment(); });
log('online_receipt', await app(() => Alpine.$data(document.body).lastDocNumber));
await app(() => { const a = Alpine.$data(document.body); a.receiptOpen = false; a.newBill?.(); });

// go offline
await ctx.setOffline(true);
await page.waitForTimeout(500);
log('host_online_after_offline', await app(() => Alpine.$data(document.body).hostOnline));
await input.fill('8850000000013'); await input.press('Enter'); await page.waitForTimeout(800);
log('offline_cart_qty', await app(() => Alpine.$data(document.body).cart.reduce((s, i) => s + Number(i.qty), 0)));
await app(async () => { const a = Alpine.$data(document.body); a.method = 'cash'; a.received = a.totalAmount; await a.processPayment(); });
log('offline_receipt', await app(() => Alpine.$data(document.body).lastDocNumber));
log('queue_count', await app(() => Alpine.$data(document.body).offlineQueueCount));

// reopen the app while offline
await page.reload().catch((e) => log('reload_error', e.message));
await page.waitForFunction(() => window.Alpine && Alpine.$data(document.body).offlineQueueCount !== undefined, null, { timeout: 15000 }).catch(() => {});
await page.waitForTimeout(1500);
log('reopened_offline_title', await page.title());
log('queue_after_reopen', await app(() => Alpine.$data(document.body)?.offlineQueueCount).catch((e) => 'ERR ' + e.message));

// back online -> auto sync
await ctx.setOffline(false);
await page.waitForFunction(() => Alpine.$data(document.body).offlineQueueCount === 0, null, { timeout: 45000 }).catch(() => {});
log('queue_after_online', await app(() => Alpine.$data(document.body).offlineQueueCount));
log('page_errors', errors.slice(0, 5));
await b.close();
