/* Drives the real chat script and styles in Chromium with the REST routes mocked. Made-up test data only. */
const { chromium } = require('playwright');
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const ROOT = path.resolve(__dirname, '../..');
const OUT = process.env.SB_E2E_OUT || __dirname + '/out';
fs.mkdirSync(OUT, { recursive: true });

const BASE = {
  rest: 'http://chat.test/wp-json/sprint-booking/v1/', symbol: '£', site: 'Inverness Taxis', greeting: 'Thank you for calling Inverness Taxis. How can I help you today?',
  operator: '01463 000000', formUrl: 'http://chat.test/book/', minLead: 60,
  services: { airport: { label: 'Airport Transfer', minibus_only: false }, minibus: { label: 'Minibus Service', minibus_only: true } },
  vehicles: { saloon: { label: 'Saloon', seats: 4, bags: 2, minibus: false }, mpv: { label: 'MPV', seats: 6, bags: 4, minibus: false }, minibus8: { label: 'Minibus (8 seats)', seats: 8, bags: 8, minibus: true } },
  earliest: '2030-01-01T10:00',
};
const PAY = { driver: true, stripe: true, paypal: true };
const PUBLIC = { ...BASE, mode: 'public', nonce: '', paths: { book: 'chat/book', manage: 'chat/manage', event: 'chat/event', report: '' } };
const STAFF = { ...BASE, mode: 'staff', nonce: 'n0nce', paths: { book: 'admin/chat/book', manage: 'admin/chat/manage', event: 'admin/chat/event', report: 'admin/calls?days=7' } };

async function open(browser, CONFIG, errors) {
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  const seen = { geo: [], quote: null, book: null, events: [], manage: [], headers: [], failFirstBook: true };
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
  await page.route('http://chat.test/**', async route => {
    const req = route.request(), url = new URL(req.url());
    const json = (b, s = 200) => route.fulfill({ status: s, contentType: 'application/json', body: JSON.stringify(b) });
    if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/dashboard.css"><body style="margin:0;padding:20px;background:#f5f8fa"><div class="sb-dash sb-chatpage"><div class="sb-chat" data-sb-chat></div></div><script>window.SB_CHAT=${JSON.stringify(CONFIG)}</script><script src="/chat.js"></script>` });
    if (url.pathname === '/dashboard.css') return route.fulfill({ contentType: 'text/css', body: fs.readFileSync(ROOT + '/assets/css/dashboard.css') });
    if (url.pathname === '/chat.js') return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(ROOT + '/assets/js/chat.js') });
    seen.headers.push(req.headers()['x-wp-nonce'] || '');
    const ep = url.pathname.split('/v1/')[1] || '';
    if (ep === 'geocode') {
      const q = url.searchParams.get('q'); seen.geo.push(q);
      if (q === 'nowhere') return json({ results: [] });
      return json({ results: [{ label: q + ' Road, Inverness', lat: 57.48, lng: -4.22 }, { label: q + ' Street, Nairn', lat: 57.58, lng: -3.87 }] });
    }
    if (ep === 'quote') { seen.quote = req.postDataJSON(); return json({ quote_only: false, total_pence: 4500, lines: [] }); }
    if (ep.endsWith('chat/book')) {
      seen.book = req.postDataJSON();
      if (seen.failFirstBook) { seen.failFirstBook = false; return json({ code: 'sb_too_soon', message: 'We need at least 60 minutes notice.' }, 400); }
      const online = ['stripe', 'paypal'].includes(seen.book.payment);
      const links = CONFIG.payments && CONFIG.payments.stripe ? { stripe: 'http://pay.test/stripe-link', paypal: 'http://pay.test/paypal-link' } : {};
      return json({ reference: 'SB-TEST22', status: 'new', total_pence: 4500, payment: seen.book.payment || 'driver', pay_links: links, redirect: online ? links[seen.book.payment] : '', message: online ? 'Booking SB-TEST22 received. The fare is £45.00. Use the secure link to pay now.' : 'Booking SB-TEST22 received. The fare is £45.00, paid to the driver.' });
    }
    if (ep.endsWith('chat/manage')) {
      const b = req.postDataJSON(); seen.manage.push(b);
      if (b.reference === 'SB-WRONG1') return json({ code: 'sb_not_found', message: 'We could not find a booking with that reference and email.' }, 404);
      return json({ reference: b.reference, status: b.action === 'cancel' ? 'cancelled' : 'confirmed', message: b.action === 'cancel' ? 'Booking ' + b.reference + ' is cancelled. A confirmation is on its way by email.' : 'Booking ' + b.reference + ' now picks up on Tue 1 Jan 2030, 10:00. We have emailed you the change.' });
    }
    if (ep.endsWith('chat/event')) { seen.events.push(req.postDataJSON().outcome); return json({ ok: true }); }
    if (ep.startsWith('admin/calls')) return json({ today: { received: 7, booked: 4, cancelled: 1, edited: 0, transferred: 2, bypass: 1, blocked: 0 }, total: { received: 30, booked: 18, cancelled: 3, edited: 2, transferred: 6, bypass: 2, blocked: 1 }, days: [] });
    return route.fulfill({ status: 404, body: 'nope' });
  });
  await page.goto('http://chat.test/');
  const chip = t => page.locator('.sb-chat__chip, .sb-chat__opt', { hasText: t }).first();
  const type = async (t) => { await page.waitForSelector('.sb-chat__input'); await page.fill('.sb-chat__input', t); await page.keyboard.press('Enter'); };
  return { page, seen, chip, type };
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || undefined, args: ['--no-sandbox'] });
  const errors = [];

  // ── Public chat ──
  let { page, seen, chip, type } = await open(browser, PUBLIC, errors);
  await chip('Book a taxi for later').waitFor();
  assert((await page.textContent('.sb-chat__log')).includes('Thank you for calling Inverness Taxis'), 'greeting comes from settings');
  const labels = await page.locator('.sb-chat__opt strong').allTextContents();
  assert.deepStrictEqual(labels, ['Taxi as soon as possible', 'Book a taxi for later', 'Cancel a booking', 'Change a booking', 'Talk to a person', 'Book without the assistant'], 'public menu: ' + labels);
  assert(seen.events.includes('received'), 'opening the chat is counted');
  await page.screenshot({ path: OUT + '/c0-menu.png' });

  // Talk to a person
  await chip('Talk to a person').click();
  await page.waitForSelector('a[href="tel:01463000000"]');
  assert(seen.events.includes('transferred'), 'transfer is counted');
  await chip('Back to the menu').click();

  // Bypass
  await chip('Book without the assistant').click();
  await page.waitForSelector('a[href="http://chat.test/book/"]');
  assert(seen.events.includes('bypass'), 'bypass is counted');
  await chip('Back to the menu').click();

  // Cancel, first with a wrong reference
  await chip('Cancel a booking').click();
  await type('sb-wrong1'); await type('a@example.com');
  await chip('Yes, cancel it').click();
  await page.waitForSelector('text=We could not find a booking');
  assert.strictEqual(seen.manage[0].reference, 'SB-WRONG1', 'reference is upper-cased'); assert.strictEqual(seen.manage[0].action, 'cancel');
  await chip('Try again').click();
  await type('SB-GOOD22'); await type('a@example.com');
  await chip('Yes, cancel it').click();
  await page.waitForSelector('text=Booking SB-GOOD22 is cancelled');

  // Change a booking
  await chip('Change a booking').click();
  await type('SB-GOOD22'); await type('a@example.com');
  await page.waitForSelector('input[type=datetime-local]'); await page.keyboard.press('Enter');
  await page.waitForSelector('text=now picks up on');
  assert.strictEqual(seen.manage.at(-1).action, 'edit'); assert.strictEqual(seen.manage.at(-1).pickup_at, '2030-01-01T10:00');

  // ASAP booking: no time question
  await chip('Taxi as soon as possible').click();
  await chip('Airport Transfer').click();
  await chip('To the airport').click();
  await type('nowhere');
  await page.waitForSelector('text=I could not find "nowhere"');
  await type('Castle'); await chip('Castle Road, Inverness').click();
  await chip('Add a stop').click(); await type('Station'); await chip('Station Street, Nairn').click();
  await chip('No, straight there').click();
  await type('Airport'); await chip('Airport Road, Inverness').click();
  await page.waitForSelector('text=The earliest car we can send is');
  assert((await page.textContent('.sb-chat__log')).includes('call 01463 000000'), 'ASAP points to the phone for right now');
  assert.strictEqual(await page.locator('input[type=datetime-local]').count(), 0, 'ASAP does not ask for a time');
  await chip('5').click(); await chip('3').waitFor(); await chip('3').click();
  await chip('MPV').waitFor();
  const cars = await page.locator('.sb-chat__chip').allTextContents();
  assert(cars.some(c => c.startsWith('MPV')) && !cars.some(c => c.startsWith('Saloon')), 'only cars that fit: ' + cars);
  await chip('MPV').click(); await chip('Yes').click();
  await type('Test Person');
  await type('abc'); await page.waitForSelector('text=does not look like a phone number');
  await type('07700 900123'); await type('test@example.com');
  await chip('Book it').waitFor();
  const sheet = await page.textContent('.sb-chat__sheet');
  for (const bit of ['Castle Road, Inverness', 'Station Street, Nairn', 'Airport Road, Inverness', 'MPV', 'Travelling with a pet', '£45.00', 'Test Person', 'Tue, 1 Jan, 10:00']) assert(sheet.includes(bit), 'sheet shows ' + bit);
  assert.strictEqual(seen.quote.stops.length, 3, 'quote includes the via stop');
  await page.screenshot({ path: OUT + '/c1-chat-summary.png' });
  await chip('Book it').click();
  await page.waitForSelector('text=We need at least 60 minutes notice');
  await chip('Change the pickup time').click();
  await page.waitForSelector('input[type=datetime-local]'); await page.keyboard.press('Enter');
  await chip('Book it').waitFor(); await chip('Book it').click();
  await page.waitForSelector('text=Booking SB-TEST22 received');
  assert.strictEqual(seen.book.pets, true); assert.strictEqual(seen.book.vias.length, 1); assert.strictEqual(seen.book.passengers, 5); assert.strictEqual(seen.book.website, '');
  assert(seen.headers.every(h => h === ''), 'visitors send no REST nonce');
  await page.waitForSelector('.sb-chat__opt');
  assert((await page.textContent('.sb-chat__sheet')).includes('Answers appear here'), 'sheet resets after booking');
  await page.screenshot({ path: OUT + '/c2-chat-booked.png' });
  await page.setViewportSize({ width: 390, height: 800 });
  assert((await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)) <= 0, 'no horizontal scroll on mobile');
  await page.screenshot({ path: OUT + '/c3-chat-mobile.png', fullPage: true });
  await page.context().close();

  // ── Paying in the chat ──
  ({ page, seen, chip, type } = await open(browser, { ...PUBLIC, payments: PAY }, errors));
  await chip('Book a taxi for later').waitFor();
  seen.failFirstBook = false;
  await chip('Book a taxi for later').click();
  await chip('Airport Transfer').click(); await chip('To the airport').click();
  await type('Castle'); await chip('Castle Road, Inverness').click();
  await chip('No, straight there').click();
  await type('Airport'); await chip('Airport Road, Inverness').click();
  await page.waitForSelector('input[type=datetime-local]'); await page.keyboard.press('Enter');
  await chip('2').click(); await chip('1').waitFor(); await chip('1').click();
  await chip('Saloon').waitFor(); await chip('Saloon').click(); await chip('No').click();
  await type('Test Person'); await type('07700 900123'); await type('test@example.com');
  await chip('Book it').waitFor(); await chip('Book it').click();
  await page.waitForSelector('text=How would you like to pay £45.00?');
  assert.deepStrictEqual(await page.locator('.sb-chat__chip').allTextContents(), ['Pay the driver', 'Pay now by card', 'Pay now with PayPal'], 'the payment choices');
  await chip('Pay now by card').click();
  assert(/Card, paid online/.test(await page.textContent('.sb-chat__sheet')), 'the sheet shows how it will be paid');
  await page.waitForSelector('a:has-text("Pay by card")');
  assert.strictEqual(seen.book.payment, 'stripe', 'the chat sends the chosen method'); assert(/^http:\/\/chat\.test\/$/.test(seen.book.return_to), 'and where to come back to: ' + seen.book.return_to);
  assert.strictEqual(await page.getAttribute('a:has-text("Pay by card")', 'href'), 'http://pay.test/stripe-link');
  assert.strictEqual(await page.getAttribute('a:has-text("Pay by card")', 'target'), '_blank', 'the payment page opens in a new tab so the chat stays');
  assert((await page.textContent('.sb-chat__log')).includes('Tap to pay now'));
  await page.screenshot({ path: OUT + '/c4-chat-pay.png' });
  await chip('Back to the menu').click(); await page.waitForSelector('.sb-chat__opt');
  await page.context().close();

  // Choosing the driver still offers the link afterwards; no gateway on means no question at all.
  ({ page, seen, chip, type } = await open(browser, { ...PUBLIC, payments: PAY }, errors));
  seen.failFirstBook = false;
  await chip('Book a taxi for later').click();
  await chip('Airport Transfer').click(); await chip('To the airport').click();
  await type('Castle'); await chip('Castle Road, Inverness').click(); await chip('No, straight there').click();
  await type('Airport'); await chip('Airport Road, Inverness').click();
  await page.waitForSelector('input[type=datetime-local]'); await page.keyboard.press('Enter');
  await chip('2').click(); await chip('1').waitFor(); await chip('1').click();
  await chip('Saloon').waitFor(); await chip('Saloon').click(); await chip('No').click();
  await type('Test Person'); await type('07700 900123'); await type('test@example.com');
  await chip('Book it').waitFor(); await chip('Book it').click();
  await chip('Pay the driver').waitFor(); await chip('Pay the driver').click();
  await page.waitForSelector('text=If you would rather pay now');
  assert.strictEqual(seen.book.payment, 'driver');
  assert(await page.locator('a:has-text("Pay with PayPal")').count() === 1, 'pay-now links are still offered');
  await page.context().close();

  // ── Staff test chat ──
  ({ page, seen, chip, type } = await open(browser, STAFF, errors));
  await chip("Today's report").waitFor();
  await chip("Today's report").click();
  await page.waitForSelector('text=Today: 7 taken, 4 booked, 1 cancelled, 0 changed, 2 passed to an operator, 1 skipped the assistant, 0 blocked.');
  assert(seen.headers.every(h => h === 'n0nce'), 'staff requests carry the REST nonce');
  await page.context().close();

  assert.deepStrictEqual(errors, [], 'no JS errors: ' + errors.join(' | '));
  console.log('CHAT E2E PASSED');
  await browser.close();
})().catch(e => { console.error('CHAT E2E FAILED:', e.message); process.exit(1); });
