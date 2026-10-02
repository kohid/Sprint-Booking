/* Drives the real dashboard script and styles in Chromium with the staff REST routes mocked.
 * Customers, addresses and prices are made-up test data. */
const { chromium } = require('playwright');
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const ROOT = path.resolve(__dirname, '../..');
const OUT = process.env.SB_E2E_OUT || __dirname + '/out';
fs.mkdirSync(OUT, { recursive: true });

const STATUSES = {
  new: { label: 'New', tone: 'warning' }, quote_requested: { label: 'Quote requested', tone: 'info' }, confirmed: { label: 'Confirmed', tone: 'primary' },
  assigned: { label: 'Driver assigned', tone: 'teal' }, completed: { label: 'Completed', tone: 'success' }, cancelled: { label: 'Cancelled', tone: 'muted' },
};
const CONFIG = { rest: 'http://dash.test/wp-json/sprint-booking/v1/', nonce: 'n0nce', symbol: '£', site: 'Inverness Taxis', user: { name: 'Dee Dispatch', initials: 'DD' }, logoutUrl: '/logout', statuses: STATUSES, services: { airport: 'Airport Transfer', corporate: 'Corporate Service', golf: 'Golf Transfer', wedding: 'Wedding Cars', minibus: 'Minibus Service', tours: 'Inverness Tours' }, needsAction: ['new', 'quote_requested'] };

// ── Fake bookings (relative to now) ──
const p2 = n => String(n).padStart(2, '0');
const wall = d => `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}T${p2(d.getHours())}:${p2(d.getMinutes())}`;
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function when(d) {
  const t0 = new Date(); t0.setHours(0, 0, 0, 0); const d0 = new Date(d); d0.setHours(0, 0, 0, 0);
  const diff = Math.round((d0 - t0) / 86400000);
  const day = diff === 0 ? 'Today' : diff === 1 ? 'Tomorrow' : diff === -1 ? 'Yesterday' : `${DAYS[d.getDay()]} ${d.getDate()} ${MON[d.getMonth()]}`;
  return { iso: wall(d), day, date: `${DAYS[d.getDay()]} ${d.getDate()} ${MON[d.getMonth()]} ${d.getFullYear()}`, time: `${p2(d.getHours())}:${p2(d.getMinutes())}` };
}
const NAMES = [['Ava', 'Mackenzie'], ['Callum', 'Fraser'], ['Isla', 'Grant'], ['Rory', 'Campbell'], ['Fiona', 'Munro'], ['Euan', 'Ross'], ['Kirsty', 'Sutherland'], ['Alasdair', 'Gunn'], ['Morag', 'MacLeod'], ['Hamish', 'Stewart']];
const PLACES = ['Test Airport, Dalcross', 'Test Station, Inverness', 'Test Castle, Inverness', 'Test Hotel, Nairn', 'Test Golf Club, Nairn', 'Test Distillery, Tain', 'Test Quay, Ullapool'];
const STAT_CYCLE = ['new', 'confirmed', 'assigned', 'completed', 'quote_requested', 'confirmed', 'cancelled', 'new', 'completed', 'assigned'];
let rows = [];
for (let i = 0; i < 34; i++) {
  const [fn, ln] = NAMES[i % NAMES.length];
  const pick = new Date(Date.now() + (i - 6) * 5.5 * 3600000); pick.setMinutes(Math.round(pick.getMinutes() / 5) * 5, 0, 0);
  const made = new Date(Date.now() - (i * 7 + 2) * 3600000);
  const status = STAT_CYCLE[i % STAT_CYCLE.length];
  const quote = status === 'quote_requested';
  const price = quote ? null : 1800 + (i % 9) * 1350;
  rows.push({
    id: 100 + i, reference: 'SB-T' + String(1000 + i), status, status_label: STATUSES[status].label,
    service: i % 6 === 4 ? 'wedding' : i % 3 === 0 ? 'airport' : 'corporate', service_label: i % 6 === 4 ? 'Wedding Cars' : i % 3 === 0 ? 'Airport Transfer' : 'Corporate Service',
    direction: i % 3 === 0 ? (i % 2 ? 'arrival' : 'departure') : '', vehicle: 'mpv', vehicle_label: i % 4 === 0 ? 'Minibus (8 seats)' : 'Saloon',
    passengers: 1 + (i % 5), luggage: i % 4, carry_on: i % 2, pickup: when(pick), return: i % 7 === 0 ? when(new Date(pick.getTime() + 28 * 3600000)) : null,
    stops: [{ label: PLACES[i % 7], lat: 57.5, lng: -4.2 }].concat(i % 5 === 0 ? [{ label: PLACES[(i + 3) % 7], lat: 57.4, lng: -4.1 }] : []).concat([{ label: PLACES[(i + 2) % 7], lat: 57.3, lng: -4.0 }]),
    from: PLACES[i % 7], to: PLACES[(i + 2) % 7], vias: i % 5 === 0 ? 1 : 0, distance_mi: 8 + i, duration_min: 20 + i, estimated: false,
    price_pence: price, price_text: price === null ? null : '£' + (price / 100).toFixed(2),
    lines: price === null ? [] : [{ key: 'base', pence: 350 }, { key: 'distance', pence: price - 350 }],
    customer: { title: i % 3 === 0 ? 'Dr' : '', name: fn + ' ' + ln, phone: '07700 90' + String(1000 + i).slice(1), email: fn.toLowerCase() + '@example.com', account: i % 4 === 0 },
    flight_no: i % 3 === 0 ? 'BA' + (1200 + i) : '', company: '', notes: i === 3 ? '=HYPERLINK("http://example.com","Click")' : (i % 6 === 0 ? 'Two child seats please' : ''),
    vulnerable: i === 2 ? { key: 'senior', label: 'Senior citizen' } : null, created: when(made),
  });
}
rows.find(r => r.id === 103).customer.name = 'Euan, "Wee" Ross'; // a name that needs CSV escaping

const matches = (r, q) => !q || [r.reference, r.customer.name, r.customer.email, r.customer.phone, r.from, r.to].join(' ').toLowerCase().includes(q.toLowerCase());
function list(url) {
  const u = new URL(url), g = k => u.searchParams.get(k) || '';
  let out = rows.slice();
  const st = g('status');
  if (st === 'needs_action') out = out.filter(r => ['new', 'quote_requested'].includes(r.status)); else if (st) out = out.filter(r => st.split(',').includes(r.status));
  out = out.filter(r => matches(r, g('q')));
  if (g('from')) out = out.filter(r => r.pickup.iso.slice(0, 10) >= g('from'));
  if (g('to')) out = out.filter(r => r.pickup.iso.slice(0, 10) <= g('to'));
  const sort = g('sort') || 'newest';
  if (sort === 'pickup_asc') out.sort((a, b) => a.pickup.iso.localeCompare(b.pickup.iso)); else if (sort === 'pickup_desc') out.sort((a, b) => b.pickup.iso.localeCompare(a.pickup.iso)); else out.sort((a, b) => a.created.iso < b.created.iso ? 1 : -1);
  const per = Math.min(g('export') ? 2000 : 100, parseInt(g('per_page'), 10) || 25), page = Math.max(1, parseInt(g('page'), 10) || 1);
  return { rows: out.slice((page - 1) * per, page * per), total: out.length, page, per_page: per, pages: Math.max(1, Math.ceil(out.length / per)) };
}
function stats() {
  const by = {}; rows.forEach(r => by[r.status] = (by[r.status] || 0) + 1);
  const series = []; for (let i = 13; i >= 0; i--) { const d = new Date(Date.now() - i * 86400000); series.push({ date: wall(d).slice(0, 10), label: `${DAYS[d.getDay()]} ${d.getDate()}`, count: [2, 0, 3, 5, 1, 4, 2, 6, 3, 0, 4, 7, 5, 3][13 - i] }); }
  const upcoming = rows.filter(r => ['new', 'confirmed', 'assigned'].includes(r.status) && r.pickup.iso >= wall(new Date())).sort((a, b) => a.pickup.iso.localeCompare(b.pickup.iso)).slice(0, 8);
  return { today: rows.filter(r => r.pickup.day === 'Today' && r.status !== 'cancelled').length, tomorrow: rows.filter(r => r.pickup.day === 'Tomorrow').length, needs_action: (by.new || 0) + (by.quote_requested || 0), quotes: by.quote_requested || 0,
    month_bookings: 41, month_change: 28, month_revenue: 184250, revenue_change: -6, by_status: by, series, next: upcoming, recent: rows.slice().sort((a, b) => a.created.iso < b.created.iso ? 1 : -1).slice(0, 6) };
}

async function setup(browser, { mode = 'shell', attrs = '', fail = false, viewport = { width: 1280, height: 900 } } = {}) {
  const ctx = await browser.newContext({ viewport, acceptDownloads: true });
  const page = await ctx.newPage();
  const log = { errors: [], posts: [], urls: [], nonces: [] };
  page.on('pageerror', e => log.errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) log.errors.push('console: ' + m.text()); });
  const state = { fail };
  await page.route('http://dash.test/**', async route => {
    const req = route.request(), url = new URL(req.url());
    const json = (b, s = 200) => route.fulfill({ status: s, contentType: 'application/json', body: JSON.stringify(b) });
    if (url.pathname === '/') {
      const data = mode === 'shell' ? 'data-shell="aside" data-view="overview"' : mode === 'overview' ? 'data-shell="none" data-view="overview" data-bookings-url="http://dash.test/bookings/"' : 'data-shell="none" data-view="bookings" ' + attrs;
      return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard harness</title><link rel="stylesheet" href="/dashboard.css"><style>body{margin:0;padding:24px 16px;font-family:system-ui,sans-serif;background:#fff}</style></head><body><div class="sb-dash" data-sb-dash ${data}></div><script>window.SB_DASH=${JSON.stringify(CONFIG)}</script><script src="/dashboard.js"></script></body></html>` });
    }
    if (url.pathname === '/dashboard.css') return route.fulfill({ contentType: 'text/css', body: fs.readFileSync(ROOT + '/assets/css/dashboard.css') });
    if (url.pathname === '/dashboard.js') return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(ROOT + '/assets/js/dashboard.js') });
    if (url.pathname.includes('/wp-json/')) {
      log.urls.push(url.pathname.split('/v1/')[1] + url.search); log.nonces.push(req.headers()['x-wp-nonce']);
      if (state.fail) { state.fail = false; return json({ code: 'rest_forbidden', message: 'Sorry, you are not allowed to do that.' }, 403); }
      const ep = url.pathname.split('/v1/')[1];
      if (ep === 'admin/stats') return json(stats());
      if (ep === 'admin/bookings') return json(list(req.url()));
      const m = /^admin\/bookings\/(\d+)\/status$/.exec(ep);
      if (m && req.method() === 'POST') {
        const body = req.postDataJSON(); log.posts.push(body);
        const r = rows.find(x => x.id === +m[1]); r.status = body.status; r.status_label = STATUSES[body.status].label; return json(r);
      }
    }
    return route.fulfill({ status: 404, body: 'nope' });
  });
  await page.goto('http://dash.test/');
  return { page, ctx, log, state };
}

// Search for one booking and wait until exactly that booking is the only row shown.
async function findRef(page, ref) {
  await page.fill('input[type=search]', ref);
  await page.waitForFunction(r => { const l = document.querySelectorAll('.sb-d-table tbody tr .sb-d-link'); return l.length === 1 && l[0].textContent.trim() === r; }, ref);
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || undefined, args: ['--no-sandbox'] });

  // ── Shell: overview ──
  let { page, ctx, log } = await setup(browser);
  await page.waitForSelector('.sb-d-kpi');
  assert.strictEqual(await page.locator('.sb-d-aside .sb-d-nav__item').count(), 2, 'aside has Overview and Bookings');
  assert.strictEqual(await page.locator('.sb-d-kpi').count(), 4, 'four stat tiles');
  assert.strictEqual(await page.locator('.sb-d-chart__bar').count(), 14, '14-day chart');
  assert((await page.locator('.sb-d-chart__bar.is-today').count()) === 1, "today's bar is highlighted");
  assert(await page.locator('.sb-d-run__row').count() >= 3, 'run sheet lists the next pickups');
  assert((await page.locator('.sb-d-nav__count').textContent()).trim() === String(stats().needs_action), 'menu badge shows how many need action');
  assert(log.nonces.every(n => n === 'n0nce'), 'every request carries the REST nonce');
  assert((await page.locator('.sb-d-title').textContent()).trim() === 'Overview');
  const aside = await page.locator('.sb-d-aside').evaluate(n => getComputedStyle(n).backgroundColor);
  assert.strictEqual(aside, 'rgb(30, 30, 45)', 'aside uses the Metronic demo1 colour #1E1E2D');
  await page.screenshot({ path: OUT + '/d1-overview.png', fullPage: true });

  // ── Needs action tile jumps to the filtered list ──
  await page.click('.sb-d-kpi--link');
  await page.waitForSelector('.sb-d-table');
  assert.strictEqual(await page.inputValue('select[aria-label=Status]'), 'needs_action', 'status filter preset');
  const badges = await page.locator('.sb-d-table .sb-d-badge').allTextContents();
  assert(badges.length > 0 && badges.every(b => ['New', 'Quote requested'].includes(b)), 'only rows that need action: ' + [...new Set(badges)]);
  assert((await page.evaluate(() => location.hash)) === '#bookings', 'view is kept in the address bar');

  // ── Search, sort, pagination ──
  await page.selectOption('select[aria-label=Status]', '');
  await page.waitForFunction(() => document.querySelectorAll('.sb-d-table tbody tr').length === 25);
  assert(/Showing 1–25 of 34/.test(await page.textContent('.sb-d-pager')), 'paging summary');
  await page.click('.sb-d-page >> text=2'); await page.waitForFunction(() => /Showing 26–34 of 34/.test(document.querySelector('.sb-d-pager').textContent));
  await page.fill('input[type=search]', 'fraser'); await page.waitForFunction(() => /of 4\b|of 3\b|of 5\b/.test(document.querySelector('.sb-d-pager')?.textContent || ''));
  const names = await page.locator('.sb-d-table tbody tr td:nth-child(3) .sb-d-strong').allTextContents();
  assert(names.length > 0 && names.every(n => /Fraser/.test(n)), 'search finds by customer name: ' + names);
  await page.fill('input[type=search]', 'zzzz-nothing'); await page.waitForSelector('.sb-d-empty');
  assert(/No bookings match these filters/.test(await page.textContent('.sb-d-empty')), 'empty state explains');
  await page.click('.sb-d-empty button'); await page.waitForSelector('.sb-d-table');
  await page.selectOption('select[aria-label=Sort]', 'pickup_asc'); await page.waitForTimeout(300);
  const firsts = await page.locator('.sb-d-table tbody tr td:nth-child(2) .sb-d-muted').allTextContents();
  assert(firsts.length > 3, 'sorted list loads');
  await page.screenshot({ path: OUT + '/d2-bookings.png', fullPage: true });

  // ── Drawer ──
  await findRef(page, 'SB-T1002');
  await page.click('.sb-d-table .sb-d-link');
  await page.waitForSelector('.sb-d-drawer');
  assert((await page.getAttribute('.sb-d-drawer', 'aria-modal')) === 'true', 'drawer is a modal dialog');
  assert(/Vulnerable solo traveller/.test(await page.textContent('.sb-d-alert')) && /Senior citizen/.test(await page.textContent('.sb-d-alert')), 'vulnerable traveller is flagged');
  assert(await page.locator('.sb-d-stop').count() >= 2, 'route stops listed');
  assert(await page.locator('a[href^="tel:"]').count() > 0 && await page.locator('a[href^="mailto:"]').count() > 0, 'call and email links');
  assert(/Total/.test(await page.textContent('.sb-d-lines')), 'fare breakdown');
  await page.waitForTimeout(600);
  await page.screenshot({ path: OUT + '/d3-drawer.png' });
  await page.keyboard.press('Escape'); await page.waitForSelector('.sb-d-drawer', { state: 'detached' });
  assert(await page.evaluate(() => document.activeElement.classList.contains('sb-d-link')), 'focus returns to the booking link after closing');

  // ── Status change: next-step button, then a manual change ──
  await findRef(page, 'SB-T1000');
  await page.click('.sb-d-table .sb-d-link'); await page.waitForSelector('.sb-d-drawer');
  const before = (await page.textContent('.sb-d-drawer__status .sb-d-badge')).trim(); assert.strictEqual(before, 'New');
  await page.click('.sb-d-drawer__status .sb-d-btn--primary');
  await page.waitForFunction(() => /Confirmed/.test(document.querySelector('.sb-d-drawer__status .sb-d-badge').textContent));
  assert.deepStrictEqual(log.posts[0], { status: 'confirmed' }, 'sent the new status');
  assert(/is now Confirmed/.test(await page.textContent('.sb-d-toast')), 'toast confirms');
  let dialogs = 0; page.on('dialog', d => { dialogs++; d.accept(); });
  await page.click('.sb-d-seg__btn >> text=Cancelled'); await page.waitForFunction(() => /Cancelled/.test(document.querySelector('.sb-d-drawer__status .sb-d-badge').textContent));
  assert.strictEqual(dialogs, 1, 'cancelling asks for confirmation first');
  await page.keyboard.press('Escape');
  await page.waitForFunction(() => /Cancelled/.test(document.querySelector('.sb-d-table tbody').textContent), null, { timeout: 5000 });

  // ── CSV export ──
  await page.fill('input[type=search]', ''); await page.waitForFunction(() => document.querySelectorAll('.sb-d-table tbody tr').length === 25);
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('text=Export CSV')]);
  const csv = fs.readFileSync(await dl.path(), 'utf8');
  const lines = csv.replace(/^﻿/, '').split('\r\n');
  assert(lines[0].startsWith('"Reference","Status","Service"'), 'CSV header');
  assert.strictEqual(lines.length, 35, 'every booking is exported, not just the page');
  assert(csv.includes('Euan, ""Wee"" Ross"'), 'commas and quotes are escaped');
  assert(csv.includes(`"'=HYPERLINK(`), 'a note starting with = cannot run as a spreadsheet formula');
  assert(/bookings-\d{4}-\d\d-\d\d\.csv/.test(dl.suggestedFilename()), 'file name has the date');

  // ── Error and retry ──
  await ctx.close();
  const e = await setup(browser, { fail: true });
  await e.page.waitForSelector('.sb-d-error');
  assert(/session has ended/.test(await e.page.textContent('.sb-d-error')), 'a 403 explains what to do');
  await e.page.click('.sb-d-error button'); await e.page.waitForSelector('.sb-d-kpi');
  await e.ctx.close();

  // ── Overview on its own page: links go to the bookings page ──
  const o = await setup(browser, { mode: 'overview' });
  await o.page.waitForSelector('.sb-d-kpi');
  assert.strictEqual(await o.page.locator('.sb-d-aside').count(), 0, 'no aside when embedded');
  assert(await o.page.locator('.sb-d-card__head .sb-d-btn >> text=View all').count() === 2, 'View all links when a bookings page is set');
  await o.page.screenshot({ path: OUT + '/d4-overview-embedded.png', fullPage: true });
  await o.ctx.close();

  // ── Bookings on its own page with a preset status ──
  const b = await setup(browser, { mode: 'bookings', attrs: 'data-status="needs_action" data-per-page="10"' });
  await b.page.waitForSelector('.sb-d-table');
  assert.strictEqual(await b.page.inputValue('select[aria-label=Status]'), 'needs_action');
  assert(await b.page.locator('.sb-d-table tbody tr').count() <= 10, 'respects per_page');
  await b.ctx.close();

  // ── Mobile ──
  const m = await setup(browser, { viewport: { width: 390, height: 844 } });
  await m.page.waitForSelector('.sb-d-kpi');
  const over = await m.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  assert(over <= 0, 'no horizontal page scroll on mobile: ' + over);
  await m.page.screenshot({ path: OUT + '/d5-mobile.png', fullPage: true });
  await m.page.click('.sb-d-nav__item >> text=Bookings'); await m.page.waitForSelector('.sb-d-table');
  assert((await m.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)) <= 0, 'the table scrolls inside its card, not the page');
  await m.ctx.close();

  assert.deepStrictEqual(log.errors, [], 'no JS errors: ' + log.errors.join(' | '));
  console.log('DASHBOARD E2E PASSED');
  await browser.close();
})().catch(e => { console.error('DASHBOARD E2E FAILED:', e.message); process.exit(1); });
