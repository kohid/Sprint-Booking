/* Drives the real chat script and styles in Chromium with the REST routes mocked. Made-up test data only. */
const { chromium } = require('playwright');
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const ROOT = path.resolve(__dirname, '../..');
const OUT = process.env.SB_E2E_OUT || __dirname + '/out';
fs.mkdirSync(OUT, { recursive: true });

const CONFIG = {
  rest: 'http://chat.test/wp-json/sprint-booking/v1/', nonce: 'n0nce', symbol: '£', site: 'Inverness Taxis', greeting: 'Thank you for calling Inverness Taxis. How can I help you today?',
  services: { airport: { label: 'Airport Transfer', minibus_only: false }, minibus: { label: 'Minibus Service', minibus_only: true } },
  vehicles: { saloon: { label: 'Saloon', seats: 4, bags: 2, minibus: false }, mpv: { label: 'MPV', seats: 6, bags: 4, minibus: false }, minibus8: { label: 'Minibus (8 seats)', seats: 8, bags: 8, minibus: true } },
  earliest: '2030-01-01T10:00',
};

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || undefined, args: ['--no-sandbox'] });
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  const errors = []; const seen = { geo: [], quote: null, book: null, nonce: [] };
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
  await page.route('http://chat.test/**', async route => {
    const req = route.request(), url = new URL(req.url());
    const json = (b, s = 200) => route.fulfill({ status: s, contentType: 'application/json', body: JSON.stringify(b) });
    if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: `<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/dashboard.css"><body style="margin:0;padding:20px;background:#f5f8fa"><div class="sb-ui"><div class="sb-chat" data-sb-chat></div></div><script>window.SB_CHAT=${JSON.stringify(CONFIG)}</script><script src="/chat.js"></script>` });
    if (url.pathname === '/dashboard.css') return route.fulfill({ contentType: 'text/css', body: fs.readFileSync(ROOT + '/assets/css/dashboard.css') });
    if (url.pathname === '/chat.js') return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(ROOT + '/assets/js/chat.js') });
    seen.nonce.push(req.headers()['x-wp-nonce']);
    if (url.pathname.endsWith('/geocode')) {
      const q = url.searchParams.get('q'); seen.geo.push(q);
      if (q === 'nowhere') return json({ results: [] });
      return json({ results: [{ label: q + ' Road, Inverness', lat: 57.48, lng: -4.22 }, { label: q + ' Street, Nairn', lat: 57.58, lng: -3.87 }] });
    }
    if (url.pathname.endsWith('/quote')) { seen.quote = req.postDataJSON(); return json({ quote_only: false, total_pence: 4500, lines: [] }); }
    if (url.pathname.endsWith('/admin/chat/book')) {
      seen.book = req.postDataJSON();
      if (!seen.firstFailed) { seen.firstFailed = true; return json({ code: 'sb_too_soon', message: 'We need at least 60 minutes notice.' }, 400); }
      return json({ reference: 'SB-TEST22', status: 'new', total_pence: 4500, message: 'Booking SB-TEST22 received. The fare is £45.00, paid to the driver.' });
    }
    return route.fulfill({ status: 404, body: 'nope' });
  });
  await page.goto('http://chat.test/');

  const chip = t => page.locator('.sb-chat__chip', { hasText: t }).first();
  const type = async (t) => { await page.fill('.sb-chat__input', t); await page.keyboard.press('Enter'); };

  await page.waitForSelector('.sb-chat__chip >> text=Airport Transfer');
  assert((await page.textContent('.sb-chat__log')).includes('Thank you for calling Inverness Taxis'), 'greeting comes from settings');
  await chip('Airport Transfer').click();
  await chip('To the airport').click();

  await page.waitForSelector('.sb-chat__input');
  await type('nowhere');
  await page.waitForSelector('text=I could not find "nowhere"');
  await page.waitForSelector('.sb-chat__input');
  await type('Castle');
  await chip('Castle Road, Inverness').click();
  await chip('Add a stop').click();
  await page.waitForSelector('.sb-chat__input'); await type('Station');
  await chip('Station Street, Nairn').click();
  await chip('No, straight there').click();
  await page.waitForSelector('.sb-chat__input'); await type('Airport');
  await chip('Airport Road, Inverness').click();

  await page.waitForSelector('input[type=datetime-local]');
  assert.strictEqual(await page.inputValue('input[type=datetime-local]'), '2030-01-01T10:00', 'time starts at the earliest pickup');
  await page.keyboard.press('Enter');
  await chip('5').waitFor(); await chip('5').click();
  await chip('3').waitFor(); await chip('3').click();
  await chip('MPV').waitFor();
  const cars = await page.locator('.sb-chat__chip').allTextContents();
  assert(cars.some(c => c.startsWith('MPV')) && !cars.some(c => c.startsWith('Saloon')), 'only cars that fit 5 people and 3 suitcases: ' + cars);
  await chip('MPV').click();
  await chip('Yes').click();
  await page.waitForSelector('.sb-chat__input'); await type('Test Person');
  await page.waitForSelector('.sb-chat__input'); await type('abc');
  await page.waitForSelector('text=does not look like a phone number');
  await page.waitForSelector('.sb-chat__input'); await type('07700 900123');
  await page.waitForSelector('.sb-chat__input'); await type('test@example.com');

  await chip('Book it').waitFor();
  const sheet = await page.textContent('.sb-chat__sheet');
  for (const bit of ['Castle Road, Inverness', 'Station Street, Nairn', 'Airport Road, Inverness', 'MPV', 'Travelling with a pet', '£45.00', 'Test Person']) assert(sheet.includes(bit), 'sheet shows ' + bit);
  assert.deepStrictEqual(seen.quote.stops.length, 3, 'quote includes the via stop');
  await page.screenshot({ path: OUT + '/c1-chat-summary.png' });

  await chip('Book it').click();
  await page.waitForSelector('text=We need at least 60 minutes notice');
  await chip('Change the pickup time').click();
  await page.waitForSelector('input[type=datetime-local]'); await page.keyboard.press('Enter');
  await chip('Book it').waitFor(); await chip('Book it').click();
  await page.waitForSelector('text=Booking SB-TEST22 received');
  assert.strictEqual(seen.book.pets, true); assert.strictEqual(seen.book.pickup.label, 'Castle Road, Inverness'); assert.strictEqual(seen.book.vias.length, 1); assert.strictEqual(seen.book.passengers, 5);
  assert(seen.nonce.every(n => n === 'n0nce'), 'every request carries the REST nonce');
  await page.screenshot({ path: OUT + '/c2-chat-booked.png' });
  await chip('Make another booking').click();
  await page.waitForSelector('.sb-chat__chip >> text=Airport Transfer');
  assert((await page.textContent('.sb-chat__sheet')).includes('Answers appear here'), 'sheet resets');

  // Narrow screen: no sideways scroll.
  await page.setViewportSize({ width: 390, height: 800 });
  assert((await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)) <= 0, 'no horizontal scroll on mobile');

  assert.deepStrictEqual(errors, [], 'no JS errors: ' + errors.join(' | '));
  console.log('CHAT E2E PASSED');
  await browser.close();
})().catch(e => { console.error('CHAT E2E FAILED:', e.message); process.exit(1); });
