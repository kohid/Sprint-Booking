const { chromium } = require('playwright');
const assert = require('assert');
const OUT = process.env.SB_E2E_OUT || __dirname + '/out';
require('fs').mkdirSync(OUT, { recursive: true });
const BASE = process.env.SB_E2E_URL || 'http://127.0.0.1:8123/index.html';
const MI = 1609.344;
// Test-only coordinates (NOT real place data) so the mock geocoder can return something plausible.
const PLACES = {
  'inverness airport': [{ label: 'MOCK Inverness Airport', lat: 57.54, lng: -4.05 }],
  'inverness railway station': [{ label: 'MOCK Inverness Station', lat: 57.48, lng: -4.22 }],
  'castle': [{ label: 'MOCK Castle A', lat: 57.478, lng: -4.226 }, { label: 'MOCK Castle B', lat: 57.50, lng: -4.20 }],
  'nairn': [{ label: 'MOCK Nairn', lat: 57.58, lng: -3.87 }],
  'culloden': [{ label: 'MOCK Culloden', lat: 57.48, lng: -4.10 }],
  'aberdeen': [{ label: 'MOCK Aberdeen', lat: 57.15, lng: -2.09 }],
};
function hav(a, b) { const R = 6371000, r = Math.PI / 180, dp = (b.lat - a.lat) * r, dl = (b.lng - a.lng) * r;
  const h = Math.sin(dp / 2) ** 2 + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dl / 2) ** 2; return 2 * R * Math.asin(Math.sqrt(h)); }
const VEH = { saloon: [1, 4, 2], estate: [1.1, 4, 3], mpv: [1.35, 6, 4], minibus8: [1.6, 8, 8], minibus16: [2.2, 16, 16] };

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || undefined, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const errors = [], posted = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error' && !/tiles\.test|ERR_/.test(m.text())) errors.push('console: ' + m.text()); });
  await page.route('http://tiles.test/**', r => r.abort());
  await page.route('**/wp-json/sprint-booking/v1/**', async route => {
    const req = route.request(); const url = new URL(req.url()); const ep = url.pathname.split('/').pop();
    const json = (b, s = 200) => route.fulfill({ status: s, contentType: 'application/json', body: JSON.stringify(b) });
    if (ep === 'geocode') { const q = (url.searchParams.get('q') || '').toLowerCase(); const k = Object.keys(PLACES).find(p => q.includes(p)); return json({ results: k ? PLACES[k] : [] }); }
    const b = req.postDataJSON();
    if (ep === 'quote') {
      let legs = [], g = [];
      for (let i = 0; i < b.stops.length - 1; i++) legs.push(Math.round(hav(b.stops[i], b.stops[i + 1]) * 1.3));
      b.stops.forEach(s => g.push([s.lng, s.lat]));
      const dist = legs.reduce((a, c) => a + c, 0), vias = b.stops.length - 2;
      const quoteOnly = ['wedding', 'tours'].includes(b.service);
      const price = (veh) => { const [m] = VEH[veh]; const sub = 350 + Math.round(dist / MI * 240); const f = Math.round(sub * m) ; const j = Math.max(f, 600) + vias * 150; return j + (b.is_return ? j : 0) + Math.max(0, b.luggage - 2) * 150; };
      const vk = b.service === 'minibus' ? ['minibus8', 'minibus16'] : Object.keys(VEH);
      const vehicles = {}; if (!quoteOnly) vk.forEach(k => vehicles[k] = price(k));
      const veh = b.vehicle || vk[0];
      const lines = quoteOnly ? [] : [{ key: 'base', pence: 350 }, { key: 'distance', pence: Math.round(dist / MI * 240) }, ...(vias ? [{ key: 'vias', pence: vias * 150 }] : []), ...(b.luggage > 2 ? [{ key: 'luggage', pence: (b.luggage - 2) * 150 }] : []), ...(b.is_return ? [{ key: 'return', pence: price(veh) - Math.max(0, b.luggage - 2) * 150 - (price(veh) - Math.max(0, b.luggage - 2) * 150) / 2 }] : [])];
      return json({ distance_m: dist, duration_s: Math.round(dist / 11), legs, geometry: g, estimated: false, quote_only: quoteOnly, reason: quoteOnly ? 'service' : null, lines, total_pence: quoteOnly ? null : price(veh), vehicles });
    }
    if (ep === 'bookings') { posted.push(b); return json({ reference: 'SB-TEST42', status: 'new', quote_only: ['wedding', 'tours'].includes(b.service), total_pence: 4200 }); }
    return json({ message: 'nope' }, 404);
  });

  await page.goto(BASE);
  await page.waitForSelector('.sb-stop');
  await page.screenshot({ path: OUT + '/01-initial.png', fullPage: true });

  // Step 1: quick fill pickup, search drop-off with 2 results, add two vias.
  await page.click('[data-quick="Inverness Airport"]');
  await page.waitForSelector('.sb-stop--pickup.is-resolved');
  await page.fill('.sb-stop--dropoff input', 'castle');
  await page.click('.sb-stop--dropoff .sb-find');
  await page.waitForSelector('.sb-results li');
  assert.strictEqual(await page.locator('.sb-results li').count(), 2, 'two search results shown');
  await page.click('.sb-results li >> nth=0');
  await page.waitForSelector('.sb-stop--dropoff.is-resolved');

  await page.click('[data-sb-add-via]');
  await page.fill('.sb-stop--via >> nth=0 >> input', 'nairn'); await page.keyboard.press('Enter');
  await page.waitForSelector('.sb-stop--via.is-resolved');
  await page.click('[data-sb-add-via]');
  await page.fill('.sb-stop--via >> nth=1 >> input', 'culloden'); await page.keyboard.press('Enter');
  await page.waitForSelector('.sb-stop--via >> nth=1 >> xpath=self::*[contains(@class,"is-resolved")]');
  await page.waitForSelector('.sb-total');
  const legs = await page.locator('.sb-legrow').allTextContents();
  assert.strictEqual(legs.length, 3, '3 legs for A + 2 vias + B'); assert(legs.every(t => /miles/.test(t)), 'leg distances shown: ' + legs);
  const total1 = await page.textContent('.sb-total'); assert(/^£\d+\.\d\d$/.test(total1), 'total shown: ' + total1);
  const trip = await page.textContent('.sb-trip'); assert(/miles ·/.test(trip), 'trip line: ' + trip);
  assert((await page.textContent('.sb-lines')).includes('Via stops (2)'), 'via fee line');
  await page.screenshot({ path: OUT + '/02-step1.png', fullPage: true });

  // Return journey doubles the price.
  await page.check('[data-sb-return]');
  await page.waitForFunction(t => document.querySelector('.sb-total').textContent !== t, total1);
  const totalRet = await page.textContent('.sb-total');
  const n = s => parseFloat(s.replace('£', ''));
  assert(Math.abs(n(totalRet) - 2 * n(total1)) < 0.011, `return ≈ double: ${total1} -> ${totalRet}`);
  await page.fill('[name=return_date]', await page.inputValue('[name=pickup_date]'));
  await page.fill('[name=return_time]', '23:55');

  // Past pickup is rejected client-side.
  await page.fill('[name=pickup_date]', '2020-01-01');
  await page.click('[data-sb-next]');
  assert(/at least 1 hour/.test(await page.textContent('[data-sb-errors]')), 'past pickup error');
  const d0 = (await page.evaluate(() => window.SB_CONFIG.minPickup)).split('T')[0];
  await page.fill('[name=pickup_date]', d0);

  // Step 2.
  await page.click('[data-sb-next]');
  await page.waitForSelector('[data-panel="2"]:not([hidden])');
  assert.strictEqual(await page.locator('.sb-vehicle').count(), 5, '5 cars for airport transfer');
  await page.screenshot({ path: OUT + '/03-step2.png', fullPage: true });

  // 5 passengers: saloon + estate disabled; 4 suitcases: MPV (4 bags) still ok.
  await page.fill('[name=passengers]', '5'); await page.dispatchEvent('[name=passengers]', 'change');
  assert.strictEqual(await page.locator('.sb-vehicle.is-disabled').count(), 2, 'saloon+estate too small for 5');
  assert.strictEqual(await page.inputValue('input[name=vehicle]:checked'), 'mpv', 'auto-switched to MPV');
  await page.fill('[name=luggage]', '5'); await page.dispatchEvent('[name=luggage]', 'change');
  assert.strictEqual(await page.locator('.sb-vehicle:not(.is-disabled)').count(), 2, 'only minibuses carry 5 suitcases');
  assert.strictEqual(await page.inputValue('input[name=vehicle]:checked'), 'minibus8', 'auto-switched to minibus');
  await page.waitForFunction(() => /Extra suitcases/.test(document.querySelector('.sb-lines').textContent));
  await page.screenshot({ path: OUT + '/04-step2-luggage.png', fullPage: true });

  // Step 3.
  await page.click('[data-sb-next]');
  await page.waitForSelector('[data-panel="3"]:not([hidden])');
  assert(/MOCK Nairn/.test(await page.textContent('.sb-review')), 'review lists via stop');
  assert(!(await page.locator('[data-sb-only="airport"]').isHidden()), 'flight field visible for airport');
  await page.click('[data-sb-submit]');
  assert(/Enter your full name/.test(await page.textContent('[data-sb-errors]')), 'validation errors listed');
  await page.fill('[name=name]', 'Test Person'); await page.selectOption('[name=title]', 'Dr');
  await page.fill('[name=email]', 'test@example.com'); await page.fill('[name=phone]', '07700 900123');
  await page.fill('[name=pickup_detail]', 'Terminal 1'); await page.fill('[name=flight_no]', 'ba1234');
  await page.screenshot({ path: OUT + '/05-step3.png', fullPage: true });
  await page.check('[name=terms]');
  await page.waitForTimeout(3100);
  await page.click('[data-sb-submit]');
  await page.waitForSelector('.sb-done:not([hidden])');
  assert(/SB-TEST42/.test(await page.textContent('.sb-done')), 'reference shown');
  const b = posted[0];
  assert.strictEqual(b.stops.length, 4, 'payload has 4 stops'); assert.strictEqual(b.is_return, true);
  assert.strictEqual(b.luggage, 5); assert.strictEqual(b.vehicle, 'minibus8'); assert.strictEqual(b.title, 'Dr');
  assert(/^\d{4}-\d\d-\d\dT\d\d:\d\d$/.test(b.pickup_at) && /T23:55$/.test(b.return_at), 'times formatted: ' + b.pickup_at + ' / ' + b.return_at);
  assert(b.elapsed_ms >= 3000 && b.website === '' && b.terms === true, 'honeypot/timing fields');
  await page.screenshot({ path: OUT + '/06-done.png', fullPage: true });

  // Quote-only service (Wedding Cars).
  await page.reload(); await page.waitForSelector('.sb-stop');
  await page.check('input[name=service][value=wedding]');
  await page.click('[data-quick="Inverness Airport"]'); await page.waitForSelector('.sb-stop--pickup.is-resolved');
  await page.fill('.sb-stop--dropoff input', 'aberdeen'); await page.keyboard.press('Enter');
  await page.waitForSelector('.sb-stop--dropoff.is-resolved');
  await page.waitForSelector('.sb-total--text');
  assert(/quote this for you/.test(await page.textContent('.sb-total--text')), 'quote-only summary');
  await page.click('[data-sb-next]'); await page.waitForSelector('[data-panel="2"]:not([hidden])');
  assert(/Quote/.test(await page.textContent('.sb-vehicle-price >> nth=0')), 'cars show Quote');
  await page.click('[data-sb-next]'); await page.waitForSelector('[data-panel="3"]:not([hidden])');
  assert.strictEqual((await page.textContent('[data-sb-submit]')).trim(), 'Send quote request');

  // Minibus Service only offers minibuses.
  await page.reload(); await page.waitForSelector('.sb-stop');
  await page.check('input[name=service][value=minibus]');
  assert.strictEqual(await page.locator('.sb-vehicle').count(), 0 + 2 - 0 === 2 ? 2 : -1, 'minibus service lists 2 cars');

  // Mobile layout.
  const m = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const mp = await m.newPage(); await mp.route('http://tiles.test/**', r => r.abort());
  await mp.goto(BASE); await mp.waitForSelector('.sb-stop');
  const overflow = await mp.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  assert(overflow <= 0, 'no horizontal scroll on mobile, overflow=' + overflow);
  await mp.screenshot({ path: OUT + '/07-mobile.png', fullPage: true });

  assert.deepStrictEqual(errors, [], 'no JS errors: ' + errors.join(' | '));
  console.log('E2E PASSED');
  await browser.close();
})().catch(e => { console.error('E2E FAILED:', e.message); process.exit(1); });
