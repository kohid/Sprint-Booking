/* Drives the real demo.js and styles in Chromium with the REST routes mocked. */
const { chromium } = require('playwright');
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const ROOT = path.resolve(__dirname, '../..');
const OUT = process.env.SB_E2E_OUT || __dirname + '/out';
fs.mkdirSync(OUT, { recursive: true });

const SERVICES = [['airport', 'Airport Transfer'], ['corporate', 'Corporate Service'], ['golf', 'Golf Transfer'], ['wedding', 'Wedding Cars'], ['minibus', 'Minibus Service'], ['tours', 'Inverness Tours']];
const CONFIG = { rest: 'http://demo.test/wp-json/sprint-booking/v1/', nonce: 'n0nce', per: 10, services: SERVICES.map(([key, label]) => ({ key, label })), counts: {} };

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || undefined, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1100, height: 800 } });
  const page = await ctx.newPage();
  const errors = [], calls = [], nonces = []; let active = 0, maxActive = 0, failGolf = true;
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
  page.on('dialog', d => { dialogs.push(d.message()); d.accept(); });
  const dialogs = [];
  await page.route('http://demo.test/**', async route => {
    const req = route.request(), url = new URL(req.url());
    if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: `<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/dashboard.css"><body style="margin:0;padding:20px;background:#f5f8fa"><div class="sb-ui"><section class="sb-ui-panel"><div class="sb-ui-panel__head"><h2>Demo data</h2></div><div class="sb-ui-panel__body"><div class="sb-demo" data-sb-demo><p class="sb-demo__state" data-sb-demo-state aria-live="polite"></p><ol class="sb-demo__list" data-sb-demo-list></ol><div class="sb-ui-sc__code"><button type="button" class="sb-d-btn sb-d-btn--primary" data-sb-demo-go>Generate 60 demo bookings</button><button type="button" class="sb-d-btn sb-d-btn--light" data-sb-demo-delete>Delete demo data</button></div></div></section></div><script>window.SB_DEMO=${JSON.stringify(CONFIG)}</script><script src="/demo.js"></script>` });
    if (url.pathname === '/dashboard.css') return route.fulfill({ contentType: 'text/css', body: fs.readFileSync(ROOT + '/assets/css/dashboard.css') });
    if (url.pathname === '/demo.js') return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(ROOT + '/assets/js/demo.js') });
    nonces.push(req.headers()['x-wp-nonce']);
    const ep = url.pathname.split('/v1/')[1];
    if (ep === 'admin/demo/generate') {
      const b = req.postDataJSON(); calls.push(b.service); active++; maxActive = Math.max(maxActive, active);
      await new Promise(r => setTimeout(r, 250)); active--;
      if (b.service === 'golf' && failGolf) { failGolf = false; return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ code: 'sb_db', message: 'We could not save your booking. Please call us instead.' }) }); }
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ service: b.service, created: 10, count: 10, estimated: b.service === 'tours' ? 4 : 0 }) });
    }
    if (ep === 'admin/demo/delete') return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ deleted: 60 }) });
    return route.fulfill({ status: 404, body: 'nope' });
  });
  await page.goto('http://demo.test/');

  // Start: nothing made yet
  await page.waitForSelector('.sb-demo__row');
  assert.strictEqual(await page.locator('.sb-demo__row').count(), 6, 'one row per service');
  assert.deepStrictEqual(await page.locator('.sb-demo__name').allTextContents(), SERVICES.map(s => s[1]));
  assert.strictEqual((await page.textContent('[data-sb-demo-state]')).trim(), 'No demo data yet.');
  assert(await page.locator('[data-sb-demo-go]').isEnabled() && await page.locator('[data-sb-demo-delete]').isDisabled(), 'generate on, delete off when empty');
  await page.screenshot({ path: OUT + '/dm1-empty.png' });

  // Generate: one service at a time; golf fails
  await page.click('[data-sb-demo-go]');
  assert(await page.locator('[data-sb-demo-go]').isDisabled(), 'buttons are locked while it runs');
  await page.waitForSelector('.sb-demo__row.is-working');
  assert(/Making bookings for Airport Transfer/.test(await page.textContent('[data-sb-demo-state]')), 'says which service is being made');
  await page.waitForSelector('.sb-demo__row.is-error', { timeout: 8000 });
  assert.deepStrictEqual(calls, ['airport', 'corporate', 'golf'], 'services run in order and it stops at the failure: ' + calls);
  assert(/Stopped at Golf Transfer/.test(await page.textContent('[data-sb-demo-state]')) && /Press Generate to continue/.test(await page.textContent('[data-sb-demo-state]')));
  assert(/could not save/.test(await page.textContent('.sb-demo__row.is-error')), 'the row shows the reason');
  assert.strictEqual(await page.locator('.sb-demo__row.is-done').count(), 2, 'the first two are done');
  assert(await page.locator('[data-sb-demo-go]').isEnabled(), 'generate is available again');
  assert.strictEqual(await page.getAttribute('.sb-demo__row >> nth=0 >> [role=progressbar]', 'aria-valuenow'), '10');
  await page.screenshot({ path: OUT + '/dm2-stopped.png' });

  // Continue: only the missing services
  calls.length = 0;
  await page.click('[data-sb-demo-go]');
  await page.waitForFunction(() => /Done\./.test(document.querySelector('[data-sb-demo-state]').textContent), null, { timeout: 8000 });
  assert.deepStrictEqual(calls, ['golf', 'wedding', 'minibus', 'tours'], 'continues with the ones that are missing: ' + calls);
  assert.strictEqual(maxActive, 1, 'never more than one request at a time');
  const done = await page.textContent('[data-sb-demo-state]');
  assert(/40 demo bookings made/.test(done) && /4 with estimated distances/.test(done), 'summary: ' + done);
  assert.strictEqual(await page.locator('.sb-demo__row.is-done').count(), 6, 'all six rows done');
  assert(await page.locator('[data-sb-demo-go]').isDisabled() && await page.locator('[data-sb-demo-delete]').isEnabled(), 'nothing left to generate; delete available');
  await page.screenshot({ path: OUT + '/dm3-done.png' });

  // Delete
  await page.click('[data-sb-demo-delete]');
  await page.waitForFunction(() => /Deleted 60/.test(document.querySelector('[data-sb-demo-state]').textContent));
  assert(/Delete all 60 demo bookings\? Real bookings are not touched\./.test(dialogs[0]), 'asks first: ' + dialogs[0]);
  assert.strictEqual(await page.locator('.sb-demo__row.is-done').count(), 0, 'rows reset');
  assert(await page.locator('[data-sb-demo-go]').isEnabled() && await page.locator('[data-sb-demo-delete]').isDisabled());
  assert(nonces.every(n => n === 'n0nce'), 'every request carries the REST nonce');

  // Declining the confirmation deletes nothing
  page.removeAllListeners('dialog'); page.on('dialog', d => d.dismiss());
  await page.click('[data-sb-demo-go]'); await page.waitForFunction(() => /Done\./.test(document.querySelector('[data-sb-demo-state]').textContent), null, { timeout: 8000 });
  let deletes = 0; page.on('request', r => { if (r.url().includes('demo/delete')) deletes++; });
  await page.click('[data-sb-demo-delete]'); await page.waitForTimeout(300);
  assert.strictEqual(deletes, 0, 'cancelling the confirmation sends nothing');
  assert.strictEqual(await page.locator('.sb-demo__row.is-done').count(), 6);

  await page.setViewportSize({ width: 390, height: 800 });
  assert((await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)) <= 0, 'no sideways scroll on a phone');
  await page.screenshot({ path: OUT + '/dm4-mobile.png', fullPage: true });

  assert.deepStrictEqual(errors, [], 'no JS errors: ' + errors.join(' | '));
  console.log('DEMO E2E PASSED');
  await browser.close();
})().catch(e => { console.error('DEMO E2E FAILED:', e.message); process.exit(1); });
