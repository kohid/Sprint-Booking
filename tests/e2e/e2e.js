/* Drives the real booking form in Chromium with the REST endpoints mocked.
 * Place names and coordinates below are fake test data, not real locations. */
const { chromium } = require('playwright');
const assert = require('assert');
const OUT = process.env.SB_E2E_OUT || __dirname + '/out';
require('fs').mkdirSync(OUT, { recursive: true });
const BASE = process.env.SB_E2E_URL || 'http://127.0.0.1:8123';
const MI = 1609.344;

// Longest key first so "inverness airport" wins over "inverness air".
const PLACES = [
  ['inverness airport', [{ label: 'MOCK Inverness Airport, Dalcross', lat: 57.54, lng: -4.05 }]],
  ['inverness air', [{ label: 'MOCK Inverness Airport, Dalcross', lat: 57.54, lng: -4.05 }, { label: 'MOCK Inverness Air Cadets', lat: 57.47, lng: -4.23 }]],
  ['castle', [{ label: 'MOCK Castle A', lat: 57.478, lng: -4.226 }, { label: 'MOCK Castle B', lat: 57.50, lng: -4.20 }]],
  ['nairn', [{ label: 'MOCK Nairn', lat: 57.58, lng: -3.87 }]],
  ['culloden', [{ label: 'MOCK Culloden', lat: 57.48, lng: -4.10 }]],
  ['aberdeen', [{ label: 'MOCK Aberdeen', lat: 57.15, lng: -2.09 }]],
];
function hav(a, b) { const R = 6371000, r = Math.PI / 180, dp = (b.lat - a.lat) * r, dl = (b.lng - a.lng) * r;
  const h = Math.sin(dp / 2) ** 2 + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dl / 2) ** 2; return 2 * R * Math.asin(Math.sqrt(h)); }
const VEH = { saloon: [1, 4, 2], estate: [1.1, 4, 3], mpv: [1.35, 6, 4], minibus8: [1.6, 8, 8], minibus16: [2.2, 16, 16] };

async function newPage(browser, opts = {}) {
  const ctx = await browser.newContext({ viewport: opts.viewport || { width: 1280, height: 900 }, deviceScaleFactor: opts.dpr || 1 });
  const page = await ctx.newPage();
  const log = { errors: [], posted: [], geocodeCalls: [], headers: [] };
  page.on('pageerror', e => log.errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error' && !/tiles\.test|ERR_/.test(m.text())) log.errors.push('console: ' + m.text()); });
  await page.route('http://tiles.test/**', r => r.abort());
  await page.route('**/wp-json/sprint-booking/v1/**', async route => {
    const req = route.request(); const url = new URL(req.url()); const ep = url.pathname.split('/').pop();
    const json = (b, s = 200) => route.fulfill({ status: s, contentType: 'application/json', body: JSON.stringify(b) });
    log.headers.push({ ep, nonce: req.headers()['x-wp-nonce'] || '' });
    if (ep === 'geocode') {
      const q = (url.searchParams.get('q') || '').toLowerCase(); log.geocodeCalls.push(q);
      const hit = PLACES.find(([k]) => q.includes(k)); return json({ results: hit ? hit[1] : [] });
    }
    const b = req.postDataJSON();
    if (ep === 'quote') {
      let legs = [], g = [];
      for (let i = 0; i < b.stops.length - 1; i++) legs.push(Math.round(hav(b.stops[i], b.stops[i + 1]) * 1.3));
      b.stops.forEach(s => g.push([s.lng, s.lat]));
      const dist = legs.reduce((a, c) => a + c, 0), vias = b.stops.length - 2;
      const quoteOnly = ['wedding', 'tours'].includes(b.service);
      const price = (veh) => { const [m] = VEH[veh]; const sub = 350 + Math.round(dist / MI * 240); const f = Math.round(sub * m); const j = Math.max(f, 600) + vias * 150; return j + (b.is_return ? j : 0) + Math.max(0, b.luggage - 2) * 150; };
      const vk = b.service === 'minibus' ? ['minibus8', 'minibus16'] : Object.keys(VEH);
      const vehicles = {}; if (!quoteOnly) vk.forEach(k => vehicles[k] = price(k));
      const veh = b.vehicle || vk[0];
      const lines = quoteOnly ? [] : [{ key: 'base', pence: 350 }, { key: 'distance', pence: Math.round(dist / MI * 240) }, ...(vias ? [{ key: 'vias', pence: vias * 150 }] : []), ...(b.luggage > 2 ? [{ key: 'luggage', pence: (b.luggage - 2) * 150 }] : []), ...(b.is_return ? [{ key: 'return', pence: 0 }] : [])];
      return json({ distance_m: dist, duration_s: Math.round(dist / 11), legs, geometry: g, estimated: false, quote_only: quoteOnly, reason: quoteOnly ? 'service' : null, lines, total_pence: quoteOnly ? null : price(veh), vehicles });
    }
    if (ep === 'bookings') { log.posted.push(b); return json({ reference: 'SB-TEST42', status: 'new', quote_only: ['wedding', 'tours'].includes(b.service), total_pence: 4200, registered: b.account_mode === 'register', signed_in: ['register', 'login'].includes(b.account_mode) }); }
    return json({ message: 'nope' }, 404);
  });
  return { page, ctx, log };
}

const setDate = (page, name, value) => page.evaluate(([n, v]) => document.querySelector(`[name=${n}]`)._flatpickr.setDate(v, true), [name, value]);
const val = (page, name) => page.inputValue(`[name=${name}]`);
async function suggest(page, stopSel, text, optionCount) {
  await page.fill(`${stopSel} >> input`, text);
  await page.waitForSelector(`${stopSel} >> .sb-results li`);
  if (optionCount != null) assert.strictEqual(await page.locator(`${stopSel} >> .sb-results li`).count(), optionCount, `${optionCount} suggestions for "${text}"`);
}
const resolved = `xpath=self::*[contains(@class,"is-resolved")]`;
async function pickFirst(page, stopSel) { await page.click(`${stopSel} >> .sb-results li >> nth=0`); await page.waitForSelector(`${stopSel} >> ${resolved}`); }

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || undefined, args: ['--no-sandbox'] });
  const { page, log } = await newPage(browser);
  await page.goto(BASE + '/index.html');
  await page.waitForSelector('.sb-stop');

  // ── Layout: service select, no Find buttons, Add-via sits between Pickup and Drop-off ──
  assert.strictEqual(await page.locator('select[name=service] option').count(), 6, 'six services in a select');
  assert.strictEqual(await page.locator('.sb-chip, .sb-find').count(), 0, 'no service chips or Find buttons');
  const y = async sel => (await page.locator(sel).first().boundingBox()).y;
  const [yp, ya, yd] = [await y('.sb-stop--pickup'), await y('.sb-addrow'), await y('.sb-stop--dropoff')];
  assert(yp < ya && ya < yd, `Add via stop is between pickup (${yp}) and drop-off (${yd}): ${ya}`);
  assert(await page.locator('[data-sb-only=airport]').first().isVisible(), 'airport transfer direction select shown for Airport Transfer');
  assert.strictEqual(log.geocodeCalls.length, 0, 'no address lookups on page load');
  await page.screenshot({ path: OUT + '/01-initial.png', fullPage: true });

  // ── Suggestions while typing ──
  assert.strictEqual(await page.inputValue('[name=airport_direction]'), '', 'departure/arrival must be chosen');
  await page.fill('.sb-stop--dropoff input', 'in'); await page.waitForTimeout(500);
  assert.strictEqual(log.geocodeCalls.length, 0, 'nothing is searched under 3 characters');
  await page.selectOption('[name=airport_direction]', 'arrival'); // fills the pickup with the airport
  await page.waitForSelector('.sb-stop--pickup.is-resolved');
  assert(/Inverness Airport/.test(await page.inputValue('.sb-stop--pickup input')), 'Arrival puts the airport in the pickup');
  await suggest(page, '.sb-stop--dropoff', 'castle', 2);
  assert.strictEqual(await page.getAttribute('.sb-stop--dropoff input', 'aria-expanded'), 'true', 'combobox expanded');
  await page.keyboard.press('Escape');
  assert.strictEqual(await page.locator('.sb-stop--dropoff .sb-results').isHidden(), true, 'Escape closes suggestions');
  await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowDown');
  const activeId = await page.getAttribute('.sb-stop--dropoff input', 'aria-activedescendant');
  assert(/Castle B/.test(await page.textContent('#' + activeId)), 'arrow keys move through suggestions');
  await page.keyboard.press('ArrowUp'); await page.keyboard.press('Enter');
  await page.waitForSelector('.sb-stop--dropoff.is-resolved');
  assert(/Castle A/.test(await page.inputValue('.sb-stop--dropoff input')), 'Enter picks the highlighted suggestion');

  // Typing quickly only searches for the final text.
  const before = log.geocodeCalls.length;
  await page.click('.sb-add-via'); await page.waitForSelector('.sb-stop--via');
  await page.locator('.sb-stop--via input').pressSequentially('nairn', { delay: 40 });
  await page.waitForSelector('.sb-stop--via .sb-results li');
  assert.strictEqual(log.geocodeCalls.length, before + 1, 'debounced to one lookup: ' + log.geocodeCalls.slice(before));
  await pickFirst(page, '.sb-stop--via');
  await page.click('.sb-add-via'); await page.waitForSelector('.sb-stop--via >> nth=1');
  await suggest(page, '.sb-stop--via >> nth=1', 'culloden'); await pickFirst(page, '.sb-stop--via >> nth=1');
  const yv = await y('.sb-stop--via >> nth=1'), ya2 = await y('.sb-addrow'), yd2 = await y('.sb-stop--dropoff');
  assert(yv < ya2 && ya2 < yd2, 'Add via stop stays just above drop-off after adding vias');
  await page.waitForSelector('.sb-total');
  const legs = await page.locator('.sb-legrow').allTextContents();
  assert.strictEqual(legs.length, 3); assert(legs.every(t => /miles/.test(t)), 'leg distances: ' + legs);
  const total1 = await page.textContent('.sb-total'); assert(/^£\d+\.\d\d$/.test(total1), 'fare shown: ' + total1);
  await page.screenshot({ path: OUT + '/02-step1.png', fullPage: true });

  // ── Calendar (flatpickr, Metronic-style) ──
  await page.click('input.sb-date-alt >> nth=0');
  await page.waitForSelector('.flatpickr-calendar.open');
  const cal = await page.locator('.flatpickr-calendar.open').boundingBox();
  assert(Math.abs(cal.width - 315) < 2, 'calendar is 315px wide like Metronic: ' + cal.width);
  assert((await page.locator('.flatpickr-calendar.open .flatpickr-day').first().evaluate(n => getComputedStyle(n).height)) === '36px', '36px day cells');
  assert((await page.locator('.flatpickr-calendar.open .flatpickr-weekday').first().textContent()).trim().startsWith('M'), 'week starts on Monday');
  await page.screenshot({ path: OUT + '/03-calendar.png', fullPage: true });
  const pickable = page.locator('.flatpickr-calendar.open .flatpickr-day:not(.flatpickr-disabled):not(.prevMonthDay):not(.nextMonthDay)');
  const nDays = await pickable.count(); await pickable.nth(nDays - 1).click();
  const pickedDate = await val(page, 'pickup_date'); assert(/^\d{4}-\d\d-\d\d$/.test(pickedDate), 'date stored as ISO: ' + pickedDate);
  const shown = await page.inputValue('input.sb-date-alt >> nth=0'); assert(shown.length > 6 && shown !== pickedDate, 'friendly date shown in the field: ' + shown);
  await page.evaluate(() => document.querySelector('[name=pickup_time]')._flatpickr.setDate('09:30', true));
  assert.strictEqual(await val(page, 'pickup_time'), '09:30');
  const retMin = await page.evaluate(() => document.querySelector('[name=return_date]')._flatpickr.config.minDate);
  assert(retMin && new Date(retMin).toISOString().slice(0, 10) <= pickedDate && new Date(retMin).getTime() >= new Date(pickedDate).getTime() - 86400000, 'return date cannot be before pickup');

  // ── Return journey doubles the fare ──
  await page.check('[data-sb-return]');
  await page.waitForFunction(t => document.querySelector('.sb-total').textContent !== t, total1);
  const n = s => parseFloat(s.replace('£', ''));
  const totalRet = await page.textContent('.sb-total'); assert(Math.abs(n(totalRet) - 2 * n(total1)) < 0.011, `return ≈ double: ${total1} -> ${totalRet}`);
  await setDate(page, 'return_date', pickedDate); await page.evaluate(() => document.querySelector('[name=return_time]')._flatpickr.setDate('23:55', true));

  // ── Vulnerable solo traveller ──
  assert(await page.locator('[data-sb-vulnerable-field]').isHidden(), 'type hidden until the box is ticked');
  await page.check('[data-sb-vulnerable]');
  assert.strictEqual(await page.locator('select[name=vulnerable_type] option').count(), 6, 'type select: prompt + 5 types');
  await page.click('[data-sb-next]');
  assert(/vulnerable solo traveller/i.test(await page.textContent('[data-sb-errors]')), 'must choose a type once ticked');
  await page.selectOption('[name=vulnerable_type]', 'lone_female');

  // ── Pickup that is too soon is refused ──
  const min = await page.evaluate(() => window.SB_CONFIG.minPickup);
  if (!/T00:0\d/.test(min)) {
    await setDate(page, 'pickup_date', min.split('T')[0]); await page.evaluate(() => document.querySelector('[name=pickup_time]')._flatpickr.setDate('00:00', true));
    await page.click('[data-sb-next]'); assert(/at least 1 hour/.test(await page.textContent('[data-sb-errors]')), 'too-soon pickup error');
  }
  await setDate(page, 'pickup_date', pickedDate); await page.evaluate(() => document.querySelector('[name=pickup_time]')._flatpickr.setDate('09:30', true));

  // ── Step 2: car pictures, capacity and luggage rules ──
  await page.click('[data-sb-next]'); await page.waitForSelector('[data-panel="2"]:not([hidden])');
  assert.strictEqual(await page.locator('.sb-vehicle').count(), 5);
  assert.strictEqual(await page.locator('.sb-vehicle-pic svg.sb-car').count(), 4, 'four cars use the built-in illustration');
  assert.strictEqual(await page.locator('.sb-vehicle-pic img').count(), 1, 'a car with a chosen photo shows the photo');
  await page.screenshot({ path: OUT + '/04-step2.png', fullPage: true });
  await page.fill('[name=passengers]', '5'); await page.dispatchEvent('[name=passengers]', 'change');
  assert.strictEqual(await page.locator('.sb-vehicle.is-disabled').count(), 2, 'saloon and estate too small for 5');
  assert.strictEqual(await page.inputValue('input[name=vehicle]:checked'), 'mpv');
  await page.fill('[name=luggage]', '5'); await page.dispatchEvent('[name=luggage]', 'change');
  assert.strictEqual(await page.inputValue('input[name=vehicle]:checked'), 'minibus8', 'switches to a minibus for 5 suitcases');
  await page.waitForFunction(() => /Extra suitcases/.test(document.querySelector('.sb-lines').textContent));

  // ── Step 3: account choices, no address fields, flight number only for airport ──
  await page.click('[data-sb-next]'); await page.waitForSelector('[data-panel="3"]:not([hidden])');
  assert.strictEqual(await page.locator('[name=pickup_detail], [name=dropoff_detail]').count(), 0, 'full address fields are gone');
  const radios = await page.locator('input[name=account_mode]').evaluateAll(els => els.map(e => [e.value, e.nextElementSibling.textContent.trim(), e.checked]));
  assert.deepStrictEqual(radios, [['guest', 'Book as Guest', true], ['register', 'Register to manage your bookings on the go!', false], ['login', 'Sign in to book with your saved details', false]], 'three radio choices');
  assert(await page.locator('[data-sb-only=airport]').last().isVisible(), 'flight number shown for Airport Transfer');
  assert(await page.locator('[data-sb-password]').isHidden(), 'no password for guests');
  await page.check('input[name=account_mode][value=register]'); assert(await page.locator('[data-sb-password]').isVisible(), 'register asks for a password');
  await page.check('input[name=account_mode][value=login]'); assert(/Account email/.test(await page.textContent('[data-sb-email-label]')), 'login labels the email as the account email');
  assert(await page.locator('[data-sb-saved-hint]').first().isVisible(), 'login says name and phone can be left blank');
  await page.check('[name=terms]');
  await page.click('[data-sb-submit]');
  const loginErrors = await page.textContent('[data-sb-errors]'); assert(/Enter your password/.test(loginErrors), 'login needs a password: ' + loginErrors);
  await page.check('input[name=account_mode][value=register]');
  await page.click('[data-sb-submit]'); assert(/at least 8 characters/.test(await page.textContent('[data-sb-errors]')), 'register needs an 8+ character password');
  await page.screenshot({ path: OUT + '/05-step3.png', fullPage: true });
  await page.fill('[name=name]', 'Test Person'); await page.selectOption('[name=title]', 'Dr');
  await page.fill('[name=email]', 'test@example.com'); await page.fill('[name=phone]', '07700 900123');
  await page.fill('[name=flight_no]', 'ba1234'); await page.fill('[name=password]', 'correct horse battery');
  await page.waitForTimeout(3100);
  await page.click('[data-sb-submit]'); await page.waitForSelector('.sb-done:not([hidden])');
  assert(/SB-TEST42/.test(await page.textContent('.sb-done')) && /account is ready/.test(await page.textContent('.sb-done')), 'confirmation with account message');
  const b = log.posted[0];
  assert.strictEqual(b.stops.length, 4); assert.strictEqual(b.is_return, true); assert.strictEqual(b.luggage, 5);
  assert.strictEqual(b.account_mode, 'register'); assert.strictEqual(b.password, 'correct horse battery');
  assert.strictEqual(b.airport_direction, 'arrival'); assert.strictEqual(b.vulnerable, true); assert.strictEqual(b.vulnerable_type, 'lone_female');
  assert(!('pickup_detail' in b) && !('dropoff_detail' in b), 'no address detail fields sent');
  assert(/^\d{4}-\d\d-\d\dT09:30$/.test(b.pickup_at) && /T23:55$/.test(b.return_at), 'times: ' + b.pickup_at + ' / ' + b.return_at);
  assert(b.elapsed_ms >= 3000 && b.website === '' && b.terms === true);
  assert(!log.headers.some(h => h.nonce), 'guests send no nonce header');

  // ── Airport transfer: Departure fills drop-off, Arrival fills pickup ──
  const p2 = await newPage(browser); const pg = p2.page;
  await pg.goto(BASE + '/index.html'); await pg.waitForSelector('.sb-stop');
  await pg.selectOption('[name=airport_direction]', 'departure');
  await pg.waitForSelector('.sb-stop--dropoff.is-resolved');
  assert(/Inverness Airport/.test(await pg.inputValue('.sb-stop--dropoff input')), 'Departure puts the airport in the drop-off');
  assert.strictEqual(await pg.inputValue('.sb-stop--pickup input'), '', 'pickup left for the customer');
  await pg.selectOption('[name=airport_direction]', 'arrival');
  await pg.waitForSelector('.sb-stop--pickup.is-resolved');
  assert(/Inverness Airport/.test(await pg.inputValue('.sb-stop--pickup input')), 'Arrival puts the airport in the pickup');
  assert.strictEqual(await pg.inputValue('.sb-stop--dropoff input'), '', 'the airport is cleared from the drop-off');
  await pg.selectOption('[name=service]', 'corporate');
  assert(await pg.locator('[data-sb-only=airport]').first().isHidden(), 'direction select hidden for other services');
  await p2.ctx.close();

  // ── Quote-only service ──
  const p3 = await newPage(browser); const q = p3.page;
  await q.goto(BASE + '/index.html'); await q.waitForSelector('.sb-stop');
  await q.selectOption('[name=service]', 'wedding');
  await suggest(q, '.sb-stop--pickup', 'castle'); await pickFirst(q, '.sb-stop--pickup');
  await suggest(q, '.sb-stop--dropoff', 'aberdeen'); await pickFirst(q, '.sb-stop--dropoff');
  await q.waitForSelector('.sb-total--text');
  await q.click('[data-sb-next]'); await q.waitForSelector('[data-panel="2"]:not([hidden])');
  await q.click('[data-sb-next]'); await q.waitForSelector('[data-panel="3"]:not([hidden])');
  assert.strictEqual((await q.textContent('[data-sb-submit]')).trim(), 'Send quote request');
  assert(await q.locator('[data-sb-only=airport]').last().isHidden(), 'flight number hidden for Wedding Cars');
  await p3.ctx.close();

  // ── Signed-in customer ──
  const p4 = await newPage(browser); const u = p4.page;
  await u.goto(BASE + '/index-user.html'); await u.waitForSelector('.sb-stop');
  await u.selectOption('[name=service]', 'corporate');
  await suggest(u, '.sb-stop--pickup', 'castle'); await pickFirst(u, '.sb-stop--pickup');
  await suggest(u, '.sb-stop--dropoff', 'aberdeen'); await pickFirst(u, '.sb-stop--dropoff');
  await u.waitForSelector('.sb-total');
  await u.click('[data-sb-next]'); await u.waitForSelector('[data-panel="2"]:not([hidden])');
  await u.click('[data-sb-next]'); await u.waitForSelector('[data-panel="3"]:not([hidden])');
  assert(/Booking as\s+Sam Customer/.test(await u.textContent('.sb-account-note')), 'shows who is booking');
  assert.strictEqual(await u.locator('input[name=account_mode][type=radio]').count(), 0, 'no guest/register/sign-in radios when signed in');
  assert(await u.locator('[data-sb-email]').isHidden(), 'account email not asked again');
  assert.strictEqual(await u.inputValue('[name=phone]'), '07700 900555', 'saved phone prefilled');
  await u.check('[name=terms]'); await u.waitForTimeout(3100); await u.click('[data-sb-submit]'); await u.waitForSelector('.sb-done:not([hidden])');
  assert.strictEqual(p4.log.posted[0].account_mode, 'account');
  assert(p4.log.headers.every(h => h.nonce === 'abc123'), 'signed-in requests carry the REST nonce');
  await p4.ctx.close();

  // ── Mobile layout ──
  const m = await newPage(browser, { viewport: { width: 390, height: 844 }, dpr: 2 }); const mp = m.page;
  await mp.goto(BASE + '/index.html'); await mp.waitForSelector('.sb-stop');
  const overflow = await mp.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  assert(overflow <= 0, 'no horizontal scroll on mobile, overflow=' + overflow);
  await mp.screenshot({ path: OUT + '/06-mobile.png', fullPage: true }); await m.ctx.close();

  assert.deepStrictEqual(log.errors, [], 'no JS errors: ' + log.errors.join(' | '));
  console.log('E2E PASSED');
  await browser.close();
})().catch(e => { console.error('E2E FAILED:', e.message); process.exit(1); });
