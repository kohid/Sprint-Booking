/* Renders the real user menu and My Profile (tests/e2e/menu-build.php) in Chromium with the REST routes mocked. All names are made up. */
const { chromium } = require('playwright');
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const ROOT = path.resolve(__dirname, '../..');
const OUT = process.env.SB_E2E_OUT || __dirname + '/out';
fs.mkdirSync(OUT, { recursive: true });
const built = {};
const build = (...args) => built[args.join(' ')] ??= execFileSync('php', [ROOT + '/tests/e2e/menu-build.php', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });

async function open(browser, args, { viewport = { width: 1100, height: 800 }, rest = () => null } = {}) {
  const ctx = await browser.newContext({ viewport });
  const page = await ctx.newPage();
  const log = { errors: [], posts: [] };
  page.on('pageerror', e => log.errors.push(e.message));
  await page.route('http://menu.test/**', async route => {
    const u = new URL(route.request().url());
    const files = { '/dashboard.css': 'assets/css/dashboard.css', '/user-menu.css': 'assets/css/user-menu.css', '/profile.css': 'assets/css/profile.css', '/user-menu.js': 'assets/js/user-menu.js', '/profile.js': 'assets/js/profile.js' };
    if (u.pathname === '/') return route.fulfill({ contentType: 'text/html', body: build(...args) });
    if (files[u.pathname]) return route.fulfill({ contentType: u.pathname.endsWith('css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(ROOT + '/' + files[u.pathname]) });
    if (u.pathname.includes('/wp-json/')) {
      const ep = u.pathname.split('/v1/')[1], body = route.request().postDataJSON();
      log.posts.push({ ep, body, nonce: route.request().headers()['x-wp-nonce'] });
      const r = rest(ep, body);
      if (r) return route.fulfill({ status: r.status || 200, contentType: 'application/json', body: JSON.stringify(r.body) });
      return route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"message":"Saved."}' });
    }
    return route.fulfill({ status: 404, body: '' });
  });
  await page.goto('http://menu.test/');
  return { page, ctx, log };
}
const labels = page => page.locator('.sb-um__panel .sb-um__item').allTextContents();
const hOver = page => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || undefined, args: ['--no-sandbox'] });
  const errors = [];

  const expect = {
    guest: { items: ['Sign In', 'Sign Up'], name: 'Guest', sub: 'Not signed in' },
    user: { items: ['My Bookings', 'My Profile', 'Log Out'], name: 'Ava Mackenzie', sub: 'ava@example.com' },
    staff: { items: ['Dashboard', 'My Bookings', 'My Profile', 'Log Out'], name: 'Dee Dispatch', sub: 'dee@example.com' },
    admin: { items: ['Dashboard', 'My Bookings', 'My Profile', 'Log Out'], name: 'kohid', sub: 'kohid.jay@gmail.com' },
  };
  for (const mode of Object.keys(expect)) {
    const { page, ctx, log } = await open(browser, [mode]);
    const e = expect[mode];
    assert(await page.locator('.sb-um__panel').isHidden(), mode + ': closed to start with');
    assert.strictEqual(await page.getAttribute('.sb-um__btn', 'aria-expanded'), 'false');
    await page.click('.sb-um__btn');
    await page.waitForSelector('.sb-um__panel', { state: 'visible' });
    assert.strictEqual(await page.getAttribute('.sb-um__btn', 'aria-expanded'), 'true');
    assert.deepStrictEqual(await labels(page), e.items, mode + ' items');
    assert.strictEqual((await page.textContent('.sb-um__who strong')).trim(), e.name);
    assert.strictEqual((await page.textContent('.sb-um__who span')).trim(), e.sub);
    assert(!/dark/i.test(await page.textContent('.sb-um__panel')), mode + ': no dark mode switch');
    const hrefs = await page.locator('.sb-um__item').evaluateAll(a => Object.fromEntries(a.map(x => [x.textContent.trim(), x.getAttribute('href')])));
    if (mode === 'guest') assert(hrefs['Sign In'].includes('/my-profile/') && hrefs['Sign Up'].endsWith('/my-profile/#signup'), 'sign in and sign up open the profile page: ' + JSON.stringify(hrefs));
    else {
      assert.strictEqual(hrefs['My Profile'], 'https://site.test/my-profile/');
      assert(hrefs['Log Out'].includes('action=logout'));
      if (e.items.includes('Dashboard')) assert.strictEqual(hrefs['Dashboard'], 'https://site.test/dispatch/');
      assert.strictEqual(hrefs['Settings'], undefined, mode + ': no Settings link in the dropdown');
    }
    const avatar = (await page.textContent('.sb-um__btn .sb-um__avatar')).trim();
    assert.strictEqual(avatar, mode === 'guest' ? '' : { user: 'AM', staff: 'DD', admin: 'K' }[mode], mode + ' avatar: ' + avatar);
    await page.waitForTimeout(250);
    if (['guest', 'user', 'admin'].includes(mode)) await page.screenshot({ path: `${OUT}/menu-${mode}.png` });
    const box = await page.locator('.sb-um__panel').boundingBox();
    assert(box.x >= 0 && box.x + box.width <= 1100 + 0.5, mode + ': the panel stays on screen');
    await page.mouse.click(300, 500);
    assert(await page.locator('.sb-um__panel').isHidden(), mode + ': a click elsewhere closes it');
    await page.focus('.sb-um__btn'); await page.keyboard.press('ArrowDown');
    assert(await page.locator('.sb-um__panel').isVisible(), mode + ': ArrowDown opens it');
    assert.strictEqual(await page.evaluate(() => document.activeElement.textContent.trim()), e.items[0], mode + ': focus lands on the first item');
    await page.keyboard.press('ArrowDown');
    assert.strictEqual(await page.evaluate(() => document.activeElement.textContent.trim()), e.items[1] || e.items[0], mode + ': ArrowDown moves on');
    await page.keyboard.press('End');
    assert.strictEqual(await page.evaluate(() => document.activeElement.textContent.trim()), e.items[e.items.length - 1], mode + ': End goes to the last item');
    await page.keyboard.press('Escape');
    assert(await page.locator('.sb-um__panel').isHidden(), mode + ': Escape closes it');
    assert(await page.evaluate(() => document.activeElement.classList.contains('sb-um__btn')), mode + ': focus returns to the avatar');
    await page.keyboard.press('Enter');
    await page.waitForSelector('.sb-um__panel', { state: 'visible' });
    await page.keyboard.press('Tab');
    assert(await page.locator('.sb-um__panel').isHidden(), mode + ': tabbing away closes it');
    errors.push(...log.errors);
    await ctx.close();
  }

  // Phone width: the panel fits, and the page does not scroll sideways.
  for (const mode of ['guest', 'admin']) {
    const { page, ctx } = await open(browser, [mode], { viewport: { width: 360, height: 700 } });
    await page.click('.sb-um__btn'); await page.waitForTimeout(250);
    const box = await page.locator('.sb-um__panel').boundingBox();
    assert(box.x >= 0 && box.x + box.width <= 360 + 0.5, `${mode}: panel inside a 360px screen: ${JSON.stringify(box)}`);
    assert((await hOver(page)) <= 0, mode + ': no sideways scroll on a phone');
    if (mode === 'admin') await page.screenshot({ path: `${OUT}/menu-admin-mobile.png` });
    await ctx.close();
  }

  // ── My Profile: signed out ──
  {
    const rest = (ep, b) => ep === 'account/login' ? (b.password === 'wrong' ? { status: 401, body: { code: 'sb_login', message: 'The email or password is not right. Try again, or book as a guest.', data: { status: 401 } } } : { body: { ok: true } })
      : ep === 'account/signup' ? (b.terms ? { body: { ok: true } } : { status: 400, body: { code: 'sb_invalid', message: 'Enter your name.', data: { status: 400, fields: { name: 'Enter your name.', email: 'Enter a valid email address.', password: 'Choose a password of at least 8 characters.', terms: 'Tick the box to agree to us using your details.' } } } }) : null;
    const { page, ctx, log } = await open(browser, ['guest', 'profile'], { rest });
    assert.strictEqual(await page.locator('.sb-pf-tab').count(), 2, 'sign in and create account tabs');
    assert(await page.locator('[data-sb-panel=signin]').isVisible() && await page.locator('[data-sb-panel=signup]').isHidden());
    assert((await page.getAttribute('.sb-pf a[href*="wp-login.php?redirect_to"]', 'href')).includes('wp-login.php'), 'a staff sign-in link is offered');
    await page.fill('[data-sb-pf=login] [name=email]', 'ava@example.com'); await page.fill('[data-sb-pf=login] [name=password]', 'wrong');
    await page.click('[data-sb-pf=login] button[type=submit]');
    await page.waitForFunction(() => /not right/.test(document.querySelector('[data-sb-pf=login] .sb-pf-msg').textContent));
    assert(!(await page.isDisabled('[data-sb-pf=login] button[type=submit]')), 'the button works again after a failure');
    assert.strictEqual(log.posts.at(-1).ep, 'account/login'); assert.strictEqual(log.posts.at(-1).nonce, undefined, 'a visitor sends no nonce');
    await page.click('[data-sb-tab=signup]');
    assert(await page.locator('[data-sb-panel=signup]').isVisible() && await page.locator('[data-sb-panel=signin]').isHidden(), 'the Create account tab opens');
    assert.strictEqual(await page.locator('.sb-pf-hp').count(), 1, 'a hidden honeypot field is present');
    await page.click('[data-sb-pf=signup] button[type=submit]');
    await page.waitForSelector('[data-sb-err=name]:not([hidden])');
    assert(/Enter your name/.test(await page.textContent('[data-sb-err=name]')) && /agree/.test(await page.textContent('[data-sb-err=terms]')), 'each problem is shown by its field');
    assert.strictEqual(await page.getAttribute('[data-sb-pf=signup] [name=email]', 'aria-invalid'), 'true');
    await page.screenshot({ path: OUT + '/profile-signup.png', fullPage: true });
    assert((await hOver(page)) <= 0);
    errors.push(...log.errors); await ctx.close();

    // A link with #signup opens the second tab straight away.
    const s = await open(browser, ['guest', 'profile']);
    await s.page.goto('http://menu.test/#signup'); await s.page.reload();
    assert(await s.page.locator('[data-sb-panel=signup]').isVisible(), '#signup opens Create account');
    await s.ctx.close();

    const off = await open(browser, ['guest', 'profile', 'signups-off']);
    assert.strictEqual(await off.page.locator('.sb-pf-tab').count(), 0, 'no tabs when accounts are switched off');
    assert.strictEqual(await off.page.locator('[data-sb-panel=signup]').count(), 0);
    await off.ctx.close();
  }

  // ── My Profile: signed in ──
  for (const mode of ['user', 'admin']) {
    const rest = (ep, b) => {
      if (b.action === 'details') return b.email === 'taken@example.com' ? { status: 409, body: { code: 'sb_email_exists', message: 'Another account already uses this email address.', data: { status: 409, fields: { email: 'Another account already uses this email address.' } } } } : { body: { ok: true, message: 'Your details are saved.', name: (b.first_name + ' ' + b.last_name).trim(), email: b.email } };
      if (b.action === 'password') return b.current === 'bad' ? { status: 400, body: { code: 'sb_invalid', message: 'That is not your current password.', data: { status: 400, fields: { current: 'That is not your current password.' } } } } : { body: { ok: true, message: 'Your password is changed.' } };
      return null;
    };
    const { page, ctx, log } = await open(browser, [mode, 'profile'], { rest });
    assert.strictEqual(await page.inputValue('[data-sb-pf=details] [name=first_name]'), 'Ava', mode + ': details are filled in');
    assert.strictEqual(await page.inputValue('[data-sb-pf=details] [name=phone]'), '07700 900123');
    assert(await page.locator('.sb-pf-head .sb-d-badge').textContent().then(t => t.trim() === (mode === 'user' ? 'Customer' : 'Staff')), mode + ': role shown');
    await page.fill('[data-sb-pf=details] [name=first_name]', 'Avery'); await page.fill('[data-sb-pf=details] [name=last_name]', 'Stone');
    await page.click('[data-sb-pf=details] button[type=submit]');
    await page.waitForFunction(() => /saved/.test(document.querySelector('[data-sb-pf=details] .sb-pf-msg').textContent));
    const sent = log.posts.at(-1);
    assert.strictEqual(sent.ep, 'account/profile'); assert.strictEqual(sent.body.action, 'details'); assert.strictEqual(sent.body.first_name, 'Avery'); assert.strictEqual(sent.nonce, 'n0nce', mode + ': the REST nonce is sent');
    assert.strictEqual((await page.textContent('.sb-pf-head h2')).trim(), 'Avery Stone', mode + ': the heading follows the change');
    await page.fill('[data-sb-pf=details] [name=email]', 'taken@example.com');
    await page.click('[data-sb-pf=details] button[type=submit]');
    await page.waitForSelector('[data-sb-err=email]:not([hidden])');
    assert(/already uses/.test(await page.textContent('[data-sb-pf=details] [data-sb-err=email]')) && (await page.getAttribute('[data-sb-pf=details] [name=email]', 'aria-invalid')) === 'true', mode + ': a taken email is shown by its field');
    // Password
    await page.fill('[data-sb-pf=password] [name=current]', 'bad'); await page.fill('[data-sb-pf=password] [name=password]', 'newpassword1'); await page.fill('[data-sb-pf=password] [name=confirm]', 'newpassword1');
    await page.click('[data-sb-pf=password] button[type=submit]');
    await page.waitForSelector('[data-sb-pf=password] [data-sb-err=current]:not([hidden])');
    await page.fill('[data-sb-pf=password] [name=current]', 'oldpassword1');
    await page.click('[data-sb-pf=password] button[type=submit]');
    await page.waitForFunction(() => /changed/.test(document.querySelector('[data-sb-pf=password] .sb-pf-msg').textContent));
    assert.strictEqual(await page.inputValue('[data-sb-pf=password] [name=password]'), '', mode + ': password boxes are emptied after a change');
    assert((await page.getAttribute('.sb-pf-links a:has-text("Log out")', 'href')).includes('action=logout'));
    assert((await hOver(page)) <= 0, mode + ': no sideways scroll');
    if (mode === 'user') await page.screenshot({ path: OUT + '/profile-user.png', fullPage: true });
    errors.push(...log.errors); await ctx.close();
  }
  const m = await open(browser, ['user', 'profile'], { viewport: { width: 360, height: 800 } });
  assert((await hOver(m.page)) <= 0, 'profile fits a phone');
  assert((await m.page.locator('.sb-pf-grid').first().evaluate(g => getComputedStyle(g).gridTemplateColumns.split(' ').length)) === 1, 'fields stack on a phone');
  await m.page.screenshot({ path: OUT + '/profile-mobile.png', fullPage: true });
  await m.ctx.close();

  assert.deepStrictEqual(errors, [], 'no JS errors: ' + errors.join(' | '));
  console.log('MENU E2E PASSED');
  await browser.close();
})().catch(e => { console.error('MENU E2E FAILED:', e.stack.split('\n').slice(0, 4).join('\n')); process.exit(1); });
