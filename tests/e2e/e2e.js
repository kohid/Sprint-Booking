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
  const log = { payLinks: false, tiles: [], quotes: [], errors: [], posted: [], geocodeCalls: [], headers: [], bookingError: null };
  page.on('pageerror', e => log.errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error' && !/tiles\.test|ERR_/.test(m.text())) log.errors.push('console: ' + m.text()); });
  await page.route('http://pay.test/**', r => r.fulfill({ contentType: 'text/html', body: '<h1>Provider page</h1>' }));
  await page.route('http://tiles.test/**', r => { log.tiles.push(r.request().url()); r.abort(); });
  await page.route('**/wp-json/sprint-booking/v1/**', async route => {
    const req = route.request(); const url = new URL(req.url()); const ep = url.pathname.split('/').pop();
    const json = (b, s = 200) => route.fulfill({ status: s, contentType: 'application/json', body: JSON.stringify(b) });
    log.headers.push({ ep, nonce: req.headers()['x-wp-nonce'] || '' });
    if (ep === 'geocode') {
      const q = (url.searchParams.get('q') || '').toLowerCase(); log.geocodeCalls.push(q);
      const hit = PLACES.find(([k]) => q.includes(k)); return json({ results: hit ? hit[1] : [] });
    }
    if (ep === 'clock') {
      const d = new Date(Date.now() + 60 * 60000); d.setMinutes(Math.ceil(d.getMinutes() / 5) * 5, 0, 0);
      const p2 = n => String(n).padStart(2, '0');
      return json({ min_pickup: `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}T${p2(d.getHours())}:${p2(d.getMinutes())}`, lead_minutes: 60 });
    }
    const b = req.postDataJSON();
    if (ep === 'quote') { log.quotes.push(b);
      let legs = [], g = [];
      for (let i = 0; i < b.stops.length - 1; i++) legs.push(Math.round(hav(b.stops[i], b.stops[i + 1]) * 1.3));
      b.stops.forEach(s => g.push([s.lng, s.lat]));
      const dist = legs.reduce((a, c) => a + c, 0), vias = b.stops.length - 2;
      const quoteOnly = ['wedding', 'tours'].includes(b.service);
      const back = b.is_return && b.return_same === false && Array.isArray(b.return_stops) && b.return_stops.length > 1 ? b.return_stops : null;
      let rlegs = [], rg = []; if (back) { for (let i = 0; i < back.length - 1; i++) rlegs.push(Math.round(hav(back[i], back[i + 1]) * 1.3)); back.forEach(s => rg.push([s.lng, s.lat])); }
      const rdist = rlegs.reduce((a, c) => a + c, 0), rvias = back ? back.length - 2 : 0;
      const jf = (veh, d, v) => { const [m] = VEH[veh]; const sub = 350 + Math.round(d / MI * 240); const f = Math.round(sub * m); return Math.max(f, 600) + v * 150; };
      const price = (veh) => { const j = jf(veh, dist, vias); return j + (b.is_return ? (back ? jf(veh, rdist, rvias) : j) : 0) + Math.max(0, b.luggage - 2) * 150; };
      const vk = b.service === 'minibus' ? ['minibus8', 'minibus16'] : Object.keys(VEH);
      const vehicles = {}; if (!quoteOnly) vk.forEach(k => vehicles[k] = price(k));
      const veh = b.vehicle || vk[0];
      const lines = quoteOnly ? [] : [{ key: 'base', pence: 350 }, { key: 'distance', pence: Math.round(dist / MI * 240) }, ...(vias ? [{ key: 'vias', pence: vias * 150 }] : []), ...(b.luggage > 2 ? [{ key: 'luggage', pence: (b.luggage - 2) * 150 }] : []), ...(b.is_return ? [{ key: 'return', pence: 0 }] : [])];
      return json({ distance_m: dist, duration_s: Math.round(dist / 11), legs, geometry: g, estimated: false, return_distance_m: back ? rdist : null, return_duration_s: back ? Math.round(rdist / 11) : null, return_legs: back ? rlegs : null, return_geometry: back ? rg : null, quote_only: quoteOnly, reason: quoteOnly ? 'service' : null, lines, total_pence: quoteOnly ? null : price(veh), vehicles });
    }
    if (ep === 'bookings' && log.bookingError) { const err = log.bookingError; log.bookingError = null; log.posted.push(b); return json(err, 400); }
    if (ep === 'bookings') {
      log.posted.push(b);
      const links = log.payLinks && !['wedding', 'tours'].includes(b.service) ? { stripe: 'http://pay.test/stripe-link', paypal: 'http://pay.test/paypal-link' } : {};
      const redirect = log.payLinks && ['stripe', 'paypal'].includes(b.payment) ? 'http://pay.test/' + b.payment + '?ref=SB-TEST42' : '';
      return json({ reference: 'SB-TEST42', status: 'new', quote_only: ['wedding', 'tours'].includes(b.service), total_pence: 4200, registered: b.account_mode === 'register', signed_in: ['register', 'login'].includes(b.account_mode), payment: b.payment || 'driver', pay_links: links, redirect });
    }
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
  const [yp, ya, yd] = [await y('[data-sb-stops] .sb-stop--pickup'), await y('[data-sb-stops] .sb-addrow'), await y('[data-sb-stops] .sb-stop--dropoff')];
  assert(yp < ya && ya < yd, `Add via stop is between pickup (${yp}) and drop-off (${yd}): ${ya}`);
  assert(await page.locator('[data-sb-only=airport]').first().isVisible(), 'airport transfer direction select shown for Airport Transfer');
  assert.strictEqual(log.geocodeCalls.length, 0, 'no address lookups on page load');
  await page.screenshot({ path: OUT + '/01-initial.png', fullPage: true });

  // ── Suggestions while typing ──
  assert.strictEqual(await page.inputValue('[name=airport_direction]'), '', 'departure/arrival must be chosen');
  await page.fill('[data-sb-stops] .sb-stop--dropoff input', 'in'); await page.waitForTimeout(500);
  assert.strictEqual(log.geocodeCalls.length, 0, 'nothing is searched under 3 characters');
  await page.selectOption('[name=airport_direction]', 'arrival'); // fills the pickup with the airport
  await page.waitForSelector('[data-sb-stops] .sb-stop--pickup.is-resolved');
  assert(/Inverness Airport/.test(await page.inputValue('[data-sb-stops] .sb-stop--pickup input')), 'Arrival puts the airport in the pickup');
  await suggest(page, '[data-sb-stops] .sb-stop--dropoff', 'castle', 2);
  assert.strictEqual(await page.getAttribute('[data-sb-stops] .sb-stop--dropoff input', 'aria-expanded'), 'true', 'combobox expanded');
  await page.keyboard.press('Escape');
  assert.strictEqual(await page.locator('[data-sb-stops] .sb-stop--dropoff .sb-results').isHidden(), true, 'Escape closes suggestions');
  await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowDown');
  const activeId = await page.getAttribute('[data-sb-stops] .sb-stop--dropoff input', 'aria-activedescendant');
  assert(/Castle B/.test(await page.textContent('#' + activeId)), 'arrow keys move through suggestions');
  await page.keyboard.press('ArrowUp'); await page.keyboard.press('Enter');
  await page.waitForSelector('[data-sb-stops] .sb-stop--dropoff.is-resolved');
  assert(/Castle A/.test(await page.inputValue('[data-sb-stops] .sb-stop--dropoff input')), 'Enter picks the highlighted suggestion');

  // Typing quickly only searches for the final text.
  const before = log.geocodeCalls.length;
  await page.click('[data-sb-stops] .sb-add-via'); await page.waitForSelector('[data-sb-stops] .sb-stop--via');
  await page.locator('[data-sb-stops] .sb-stop--via input').pressSequentially('nairn', { delay: 40 });
  await page.waitForSelector('[data-sb-stops] .sb-stop--via .sb-results li');
  assert.strictEqual(log.geocodeCalls.length, before + 1, 'debounced to one lookup: ' + log.geocodeCalls.slice(before));
  await pickFirst(page, '[data-sb-stops] .sb-stop--via');
  await page.click('[data-sb-stops] .sb-add-via'); await page.waitForSelector('[data-sb-stops] .sb-stop--via >> nth=1');
  await suggest(page, '[data-sb-stops] .sb-stop--via >> nth=1', 'culloden'); await pickFirst(page, '[data-sb-stops] .sb-stop--via >> nth=1');
  const yv = await y('[data-sb-stops] .sb-stop--via >> nth=1'), ya2 = await y('[data-sb-stops] .sb-addrow'), yd2 = await y('[data-sb-stops] .sb-stop--dropoff');
  assert(yv < ya2 && ya2 < yd2, 'Add via stop stays just above drop-off after adding vias');
  await page.waitForSelector('.sb-total');
  const legs = await page.locator('[data-sb-stops] .sb-legrow').allTextContents();
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

  // ── Return journey tab: same route in reverse, or its own route ──
  assert(await page.locator('[data-sb-tabs]').isVisible(), 'tabs appear once a return is wanted');
  assert.deepStrictEqual(await page.locator('[data-sb-tab]').allTextContents(), ['Journey', 'Return journey'], 'tab names');
  assert.strictEqual(await page.getAttribute('[data-sb-tab=out]', 'aria-selected'), 'true');
  await page.click('[data-sb-tab=ret]');
  assert(await page.locator('[data-sb-tabpanel=ret]').isVisible() && await page.locator('[data-sb-tabpanel=out]').isHidden(), 'the return tab replaces the outbound panel');
  assert(await page.isChecked('[data-sb-return-same]'), 'same route in reverse is ticked by default');
  assert(/same route in reverse, with the same via stops/.test(await page.textContent('[data-sb-tabpanel=ret] .sb-check')), 'checkbox wording');
  const outVals = await page.locator('[data-sb-stops] .sb-search input').evaluateAll(n => n.map(i => i.value));
  const retVals = await page.locator('[data-sb-stops-ret] .sb-search input').evaluateAll(n => n.map(i => i.value));
  assert.deepStrictEqual(retVals, outVals.slice().reverse(), 'the return fills the way out, reversed, via stops included');
  assert(await page.locator('[data-sb-stops-ret] .sb-search input').evaluateAll(n => n.every(i => i.readOnly)), 'mirrored stops are read-only');
  assert.strictEqual(await page.locator('[data-sb-stops-ret] .sb-add-via').count(), 0, 'no add-via while mirrored');
  assert.strictEqual(await page.locator('[data-sb-stops-ret] .sb-legrow').count(), outVals.length - 1);
  const legsRet = await page.locator('[data-sb-stops-ret] .sb-legrow').allTextContents();
  assert(legsRet.every(t => /miles/.test(t)), 'return leg distances shown: ' + legsRet);
  assert.strictEqual(log.quotes.at(-1).return_same, true); assert.deepStrictEqual(log.quotes.at(-1).return_stops, []);

  // Unticked: the return starts empty and takes its own route.
  await page.uncheck('[data-sb-return-same]');
  assert.deepStrictEqual(await page.locator('[data-sb-stops-ret] .sb-search input').evaluateAll(n => n.map(i => i.value)), ['', ''], 'unticked means an empty return route');
  assert(await page.locator('[data-sb-stops-ret] .sb-search input').evaluateAll(n => n.every(i => !i.readOnly)), 'and it can be edited');
  assert.strictEqual(await page.locator('[data-sb-stops-ret] .sb-add-via').count(), 1, 'return via stops can be added');
  assert(/Return pickup/.test(await page.textContent('[data-sb-stops-ret] .sb-stop--pickup label')) && /Return drop-off/.test(await page.textContent('[data-sb-stops-ret] .sb-stop--dropoff label')), 'return stops are labelled');
  await suggest(page, '[data-sb-stops-ret] .sb-stop--pickup', 'nairn'); await pickFirst(page, '[data-sb-stops-ret] .sb-stop--pickup');
  await suggest(page, '[data-sb-stops-ret] .sb-stop--dropoff', 'aberdeen'); await pickFirst(page, '[data-sb-stops-ret] .sb-stop--dropoff');
  await page.waitForFunction(() => /return \d/.test(document.querySelector('.sb-trip') ? document.querySelector('.sb-trip').textContent : ''));
  const q2 = log.quotes.at(-1); assert.strictEqual(q2.return_same, false); assert.strictEqual(q2.return_stops.length, 2, 'own return route is sent');
  assert(/Way out [\d.]+ miles · return [\d.]+ miles/.test(await page.textContent('.sb-trip')), 'summary shows both distances: ' + await page.textContent('.sb-trip'));
  await page.screenshot({ path: OUT + '/03b-return-tab.png', fullPage: true });

  // An own route that is not filled in cannot be booked.
  await page.click('[data-sb-tab=ret] >> nth=0');
  await page.fill('[data-sb-stops-ret] .sb-stop--dropoff input', 'xx');
  await page.click('[data-sb-next]');
  assert(/Return drop-off: pick an address/.test(await page.textContent('[data-sb-errors]')), 'unfinished return route is reported');
  await suggest(page, '[data-sb-stops-ret] .sb-stop--dropoff', 'aberdeen'); await pickFirst(page, '[data-sb-stops-ret] .sb-stop--dropoff');

  // Ticking again mirrors the way out once more.
  await page.check('[data-sb-return-same]');
  assert.deepStrictEqual(await page.locator('[data-sb-stops-ret] .sb-search input').evaluateAll(n => n.map(i => i.value)), outVals.slice().reverse(), 'ticking again mirrors again');
  await page.click('[data-sb-tab=out]');
  assert(await page.locator('[data-sb-tabpanel=out]').isVisible(), 'back on the journey tab');
  await page.waitForFunction(t => ((document.querySelector('.sb-total') || {}).textContent === t), totalRet);

  // ── The map zooms with the mouse wheel ──
  const zOf = u => +(/\/(\d+)\/\d+\/\d+\.png/.exec(u) || [])[1];
  await page.waitForTimeout(600); const seenTiles = log.tiles.length; const zNow = zOf(log.tiles[seenTiles - 1] || '');
  await page.locator('[data-sb-map]').scrollIntoViewIfNeeded();
  const mb = await page.locator('[data-sb-map]').boundingBox();
  await page.mouse.move(mb.x + mb.width / 2, mb.y + mb.height / 2);
  await page.mouse.wheel(0, -600); await page.waitForTimeout(1200);
  const fresh = log.tiles.slice(seenTiles).map(zOf).filter(Number.isFinite);
  assert(fresh.length && Math.max(...fresh) > zNow, `scrolling over the map zooms in (tile zoom ${zNow} -> ${Math.max(...fresh)})`);

  // ── The form fills the column it is placed in ──
  const widths = await page.evaluate(() => { const a = document.querySelector('.sb-app'); return [a.getBoundingClientRect().width, a.parentElement.clientWidth - parseFloat(getComputedStyle(a.parentElement).paddingLeft) - parseFloat(getComputedStyle(a.parentElement).paddingRight)]; });
  assert(Math.abs(widths[0] - widths[1]) <= 1, 'the form is as wide as its container: ' + widths);

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
  assert(await page.locator('[data-sb-details]').first().isVisible(), 'guests type their name and mobile');
  await page.check('input[name=account_mode][value=register]'); assert(await page.locator('[data-sb-password]').isVisible(), 'register asks for a password');
  assert(await page.locator('[data-sb-details]').first().isVisible(), 'new accounts type their name and mobile');
  await page.check('input[name=account_mode][value=login]'); assert(/Account email/.test(await page.textContent('[data-sb-email-label]')), 'login labels the email as the account email');
  assert(await page.locator('[data-sb-details]').first().isHidden(), 'sign in uses the saved name and mobile, so those fields are hidden');
  await page.check('[name=terms]');
  await page.click('[data-sb-submit]');
  const loginErrors = await page.textContent('[data-sb-errors]'); assert(/Enter your password/.test(loginErrors), 'login needs a password: ' + loginErrors);
  await page.check('input[name=account_mode][value=register]');
  await page.click('[data-sb-submit]'); assert(/at least 8 characters/.test(await page.textContent('[data-sb-errors]')), 'register needs an 8+ character password');
  await page.screenshot({ path: OUT + '/05-step3.png', fullPage: true });
  await page.fill('[name=first_name]', 'Test'); await page.fill('[name=last_name]', 'Person'); await page.selectOption('[name=title]', 'Dr');
  await page.fill('[name=email]', 'test@example.com'); await page.fill('[name=phone]', '07700 900123');
  await page.fill('[name=flight_no]', 'ba1234'); await page.fill('[name=password]', 'correct horse battery');
  await page.waitForTimeout(3100);
  await page.click('[data-sb-submit]'); await page.waitForSelector('.sb-done:not([hidden])');
  assert(/SB-TEST42/.test(await page.textContent('.sb-done')) && /account is ready/.test(await page.textContent('.sb-done')), 'confirmation with account message');
  const b = log.posted[0];
  assert.strictEqual(b.first_name, 'Test'); assert.strictEqual(b.last_name, 'Person'); assert.strictEqual(b.name, 'Test Person', 'first and last name are joined for the server');
  assert.strictEqual(b.return_same, true); assert.deepStrictEqual(b.return_stops, []);
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
  await pg.waitForSelector('[data-sb-stops] .sb-stop--dropoff.is-resolved');
  assert(/Inverness Airport/.test(await pg.inputValue('[data-sb-stops] .sb-stop--dropoff input')), 'Departure puts the airport in the drop-off');
  assert.strictEqual(await pg.inputValue('[data-sb-stops] .sb-stop--pickup input'), '', 'pickup left for the customer');
  await pg.selectOption('[name=airport_direction]', 'arrival');
  await pg.waitForSelector('[data-sb-stops] .sb-stop--pickup.is-resolved');
  assert(/Inverness Airport/.test(await pg.inputValue('[data-sb-stops] .sb-stop--pickup input')), 'Arrival puts the airport in the pickup');
  assert.strictEqual(await pg.inputValue('[data-sb-stops] .sb-stop--dropoff input'), '', 'the airport is cleared from the drop-off');
  await pg.selectOption('[name=service]', 'corporate');
  assert(await pg.locator('[data-sb-only=airport]').first().isHidden(), 'direction select hidden for other services');
  await p2.ctx.close();

  // ── Quote-only service ──
  const p3 = await newPage(browser); const q = p3.page;
  await q.goto(BASE + '/index.html'); await q.waitForSelector('.sb-stop');
  await q.selectOption('[name=service]', 'wedding');
  await suggest(q, '[data-sb-stops] .sb-stop--pickup', 'castle'); await pickFirst(q, '[data-sb-stops] .sb-stop--pickup');
  await suggest(q, '[data-sb-stops] .sb-stop--dropoff', 'aberdeen'); await pickFirst(q, '[data-sb-stops] .sb-stop--dropoff');
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
  await suggest(u, '[data-sb-stops] .sb-stop--pickup', 'castle'); await pickFirst(u, '[data-sb-stops] .sb-stop--pickup');
  await suggest(u, '[data-sb-stops] .sb-stop--dropoff', 'aberdeen'); await pickFirst(u, '[data-sb-stops] .sb-stop--dropoff');
  await u.waitForSelector('.sb-total');
  await u.click('[data-sb-next]'); await u.waitForSelector('[data-panel="2"]:not([hidden])');
  await u.click('[data-sb-next]'); await u.waitForSelector('[data-panel="3"]:not([hidden])');
  assert(/Booking as\s+Sam Customer/.test(await u.textContent('.sb-account-note')), 'shows who is booking');
  assert.strictEqual(await u.locator('input[name=account_mode][type=radio]').count(), 0, 'no guest/register/sign-in radios when signed in');
  assert(await u.locator('[data-sb-email]').isHidden(), 'account email not asked again');
  assert.strictEqual(await u.inputValue('[name=phone]'), '07700 900555', 'saved phone prefilled');
  assert(await u.locator('[data-sb-details]').first().isHidden(), 'signed-in customers are not asked for name and mobile again');
  await u.check('[name=terms]'); await u.waitForTimeout(3100);
  p4.log.bookingError = { code: 'sb_need_details', message: 'We need your name and mobile number to finish this booking. Add them below.', data: { status: 400 } };
  await u.click('[data-sb-submit]'); await u.waitForSelector('[data-sb-details] >> visible=true');
  assert(/mobile number/.test(await u.textContent('[data-sb-errors]')), 'asks for the missing details');
  await u.click('[data-sb-submit]'); await u.waitForSelector('.sb-done:not([hidden])');
  assert.strictEqual(p4.log.posted[p4.log.posted.length - 1].account_mode, 'account');
  assert(p4.log.headers.every(h => h.nonce === 'abc123'), 'signed-in requests carry the REST nonce');
  await p4.ctx.close();

  // ── Calendar and time picker stay right under their field, even on a page with a header and wide margins ──
  const wide = await newPage(browser, { viewport: { width: 1600, height: 900 } }); const w = wide.page;
  await w.goto(BASE + '/index.html'); await w.waitForSelector('.sb-stop');
  await w.addStyleTag({ content: 'body{padding:300px 220px 40px 260px !important}' });
  for (const sel of ['input.sb-date-alt', 'input.sb-time-alt']) {
    await w.locator(sel).first().scrollIntoViewIfNeeded(); await w.locator(sel).first().click();
    await w.waitForSelector('.flatpickr-calendar.open'); await w.waitForTimeout(450);
    const g = await w.evaluate(s => { const i = document.querySelector(s).getBoundingClientRect(); const c = document.querySelector('.flatpickr-calendar.open').getBoundingClientRect(); return { dx: Math.round(c.left - i.left), dy: Math.round(c.top - i.bottom) }; }, sel);
    assert(Math.abs(g.dx) <= 4 && g.dy >= 0 && g.dy <= 8, `${sel}: popup sits under its field (dx=${g.dx}, dy=${g.dy})`);
    await w.keyboard.press('Escape'); await w.mouse.click(5, 5);
  }
  await wide.ctx.close();

  // ── The prefilled pickup never starts out expired, and the live clock catches one that has gone stale ──
  const c1 = await newPage(browser); const cl = c1.page;
  await cl.clock.install({ time: new Date() });
  await cl.goto(BASE + '/index.html'); await cl.waitForSelector('.sb-stop');
  const startMin = await cl.evaluate(() => window.SB_CONFIG.minPickup);
  const defWall = (await val(cl, 'pickup_date')) + 'T' + (await val(cl, 'pickup_time'));
  assert(defWall > startMin, `default pickup (${defWall}) is later than the earliest allowed (${startMin})`);
  await cl.clock.fastForward('02:00:00'); // two hours pass while the form is open
  await cl.click('[data-sb-next]');
  assert(/earliest available now/.test(await cl.textContent('[data-sb-errors]')), 'a stale pickup is caught with the earliest time: ' + await cl.textContent('[data-sb-errors]'));
  await c1.ctx.close();

  // ── The server refuses a too-soon pickup: the form moves it to the earliest time and goes back to step 1 ──
  const c2 = await newPage(browser); const sv = c2.page;
  await sv.goto(BASE + '/index.html'); await sv.waitForSelector('.sb-stop');
  await sv.selectOption('[name=service]', 'corporate');
  await suggest(sv, '[data-sb-stops] .sb-stop--pickup', 'castle'); await pickFirst(sv, '[data-sb-stops] .sb-stop--pickup');
  await suggest(sv, '[data-sb-stops] .sb-stop--dropoff', 'aberdeen'); await pickFirst(sv, '[data-sb-stops] .sb-stop--dropoff');
  await sv.waitForSelector('.sb-total');
  await sv.click('[data-sb-next]'); await sv.waitForSelector('[data-panel="2"]:not([hidden])');
  await sv.click('[data-sb-next]'); await sv.waitForSelector('[data-panel="3"]:not([hidden])');
  await sv.fill('[name=first_name]', 'Test'); await sv.fill('[name=last_name]', 'Person'); await sv.fill('[name=email]', 'test@example.com'); await sv.fill('[name=phone]', '07700 900123'); await sv.check('[name=terms]');
  await sv.waitForTimeout(3100);
  const earliest = await sv.evaluate(() => { const d = new Date(Date.now() + 90 * 60000); d.setMinutes(Math.ceil(d.getMinutes() / 5) * 5, 0, 0); const p = n => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; });
  c2.log.bookingError = { code: 'sb_too_soon', message: 'We need at least 60 minutes notice.', data: { status: 400, earliest } };
  await sv.click('[data-sb-submit]');
  await sv.waitForSelector('[data-panel="1"]:not([hidden])');
  assert(/moved it to the earliest time available/.test(await sv.textContent('[data-sb-errors]')), 'explains the pickup was moved');
  assert.strictEqual((await val(sv, 'pickup_date')) + 'T' + (await val(sv, 'pickup_time')), earliest, 'pickup set to the earliest time the server allows');
  await c2.ctx.close();

  // ── Online payment: Stripe and PayPal cards on the last step ──
  const p5 = await newPage(browser); const py = p5.page; p5.log.payLinks = true;
  const toStep3 = async (pg, service, passengers) => {
    await pg.selectOption('[name=service]', service);
    await suggest(pg, '[data-sb-stops] .sb-stop--pickup', 'castle'); await pickFirst(pg, '[data-sb-stops] .sb-stop--pickup');
    await suggest(pg, '[data-sb-stops] .sb-stop--dropoff', 'aberdeen'); await pickFirst(pg, '[data-sb-stops] .sb-stop--dropoff');
    await pg.waitForSelector('.sb-total');
    await pg.click('[data-sb-next]'); await pg.waitForSelector('[data-panel="2"]:not([hidden])');
    await pg.click('[data-sb-next]'); await pg.waitForSelector('[data-panel="3"]:not([hidden])');
  };
  await py.goto(BASE + '/index-pay.html'); await py.waitForSelector('.sb-stop');
  await toStep3(py, 'corporate');
  assert(await py.locator('[data-sb-pay]').isVisible(), 'the payment choice appears when a gateway is on');
  assert.deepStrictEqual(await py.locator('[data-sb-pay-opt]:visible strong').allTextContents(), ['Pay the driver', 'Pay now by card', 'Pay now with PayPal']);
  assert.strictEqual(await py.inputValue('input[name=payment]:checked'), 'driver', 'paying the driver is the default');
  assert.strictEqual((await py.textContent('[data-sb-submit]')).trim(), 'Confirm booking');
  assert(/pay the driver/i.test(await py.textContent('[data-sb-pay-note]')));
  await py.locator('[data-sb-pay-opt=stripe]').click();
  assert(/^Confirm and pay £\d+\.\d\d$/.test((await py.textContent('[data-sb-submit]')).trim()), 'the button says what will be charged: ' + await py.textContent('[data-sb-submit]'));
  assert(/secure page to pay £\d+\.\d\d/.test(await py.textContent('[data-sb-pay-note]')), 'the note explains what happens');
  assert(await py.locator('[data-sb-pay-opt=stripe]').evaluate(n => n.classList.contains('is-selected')), 'the chosen card is highlighted');
  await py.screenshot({ path: OUT + '/07-payment-choice.png', fullPage: true });
  await py.fill('[name=first_name]', 'Test'); await py.fill('[name=last_name]', 'Person'); await py.fill('[name=email]', 'test@example.com'); await py.fill('[name=phone]', '07700 900123'); await py.check('[name=terms]');
  await py.waitForTimeout(3100);
  await Promise.all([py.waitForURL('http://pay.test/stripe**'), py.click('[data-sb-submit]')]);
  const sent = p5.log.posted.at(-1);
  assert.strictEqual(sent.payment, 'stripe'); assert(/\/index-pay\.html$/.test(sent.return_to) && !sent.return_to.includes('?'), 'the form tells the server which page to come back to: ' + sent.return_to);
  await p5.ctx.close();

  // PayPal, and pay the driver while links are still offered
  const p6 = await newPage(browser); const py2 = p6.page; p6.log.payLinks = true;
  await py2.goto(BASE + '/index-pay.html'); await py2.waitForSelector('.sb-stop'); await toStep3(py2, 'golf');
  await py2.locator('[data-sb-pay-opt=paypal]').click();
  await py2.fill('[name=first_name]', 'Test'); await py2.fill('[name=last_name]', 'Person'); await py2.fill('[name=email]', 'test@example.com'); await py2.fill('[name=phone]', '07700 900123'); await py2.check('[name=terms]');
  await py2.waitForTimeout(3100);
  await Promise.all([py2.waitForURL('http://pay.test/paypal**'), py2.click('[data-sb-submit]')]);
  assert.strictEqual(p6.log.posted.at(-1).payment, 'paypal');
  await p6.ctx.close();

  const p7 = await newPage(browser); const py3 = p7.page; p7.log.payLinks = true;
  await py3.goto(BASE + '/index-pay.html'); await py3.waitForSelector('.sb-stop'); await toStep3(py3, 'golf');
  await py3.fill('[name=first_name]', 'Test'); await py3.fill('[name=last_name]', 'Person'); await py3.fill('[name=email]', 'test@example.com'); await py3.fill('[name=phone]', '07700 900123'); await py3.check('[name=terms]');
  await py3.waitForTimeout(3100);
  await py3.click('[data-sb-submit]'); await py3.waitForSelector('.sb-done:not([hidden])');
  assert.strictEqual(p7.log.posted.at(-1).payment, 'driver');
  assert(/Prefer to pay now/.test(await py3.textContent('.sb-done')) && await py3.locator('.sb-done a[href="http://pay.test/stripe-link"]').count() === 1 && await py3.locator('.sb-done a[href="http://pay.test/paypal-link"]').count() === 1, 'after booking, pay-now links are offered to someone who chose the driver');
  await p7.ctx.close();

  // A quote has no fare, so there is nothing to pay
  const p8 = await newPage(browser); const py4 = p8.page;
  await py4.goto(BASE + '/index-pay.html'); await py4.waitForSelector('.sb-stop'); await toStep3(py4, 'wedding');
  assert(await py4.locator('[data-sb-pay]').isHidden(), 'no payment choice for a quote request');
  assert.strictEqual((await py4.textContent('[data-sb-submit]')).trim(), 'Send quote request');
  await p8.ctx.close();

  // No gateway on: the form is exactly as before
  const p9 = await newPage(browser); const py5 = p9.page;
  await py5.goto(BASE + '/index.html'); await py5.waitForSelector('.sb-stop'); await toStep3(py5, 'golf');
  assert(await py5.locator('[data-sb-pay]').isHidden(), 'no payment choice when no gateway is on');
  assert.strictEqual((await py5.textContent('[data-sb-submit]')).trim(), 'Confirm booking');
  await p9.ctx.close();

  // Coming back from the provider
  const p10 = await newPage(browser); const pb = p10.page;
  await pb.goto(BASE + '/index-paid.html'); await pb.waitForSelector('.sb-paybanner--ok');
  assert(/Payment received.*SB-TEST42.*paid/.test(await pb.textContent('.sb-paybanner')), 'paid banner: ' + await pb.textContent('.sb-paybanner'));
  await pb.screenshot({ path: OUT + '/08-paid-banner.png' });
  await pb.goto(BASE + '/index-cancel.html'); await pb.waitForSelector('.sb-paybanner--warn');
  assert(/Payment cancelled.*SB-TEST42.*saved/.test(await pb.textContent('.sb-paybanner')), 'cancelled banner');
  await p10.ctx.close();

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
