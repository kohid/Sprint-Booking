/* Renders the real Settings → WhatsApp panels (static, from tests/e2e/admin-build.php) in Chromium. Keys here are made up. */
const { chromium } = require('playwright');
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const ROOT = path.resolve(__dirname, '../..');
const OUT = process.env.SB_E2E_OUT || __dirname + '/out';
fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || undefined, args: ['--no-sandbox'] });
  const errors = [];
  for (const provider of ['twilio', 'meta']) {
    const html = execFileSync('php', [ROOT + '/tests/e2e/admin-build.php', 'wa', provider], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
    for (const secret of ['tw_secret_token_9f3a', 'EAAGsecrettoken7c2d', 'appsecret5e1b']) assert(!html.includes(secret), 'a saved secret is never written into the page: ' + secret);
    assert(html.includes('•••• 9f3a') && html.includes('•••• 7c2d') && html.includes('•••• 5e1b'), 'only the last four characters are shown');
    for (const size of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
      const page = await browser.newPage({ viewport: size });
      page.on('pageerror', e => errors.push(e.message));
      await page.route('http://wa.test/**', route => {
        const u = new URL(route.request().url());
        if (u.pathname === '/') return route.fulfill({ contentType: 'text/html', body: html });
        if (u.pathname === '/dashboard.css') return route.fulfill({ contentType: 'text/css', body: fs.readFileSync(ROOT + '/assets/css/dashboard.css') });
        return route.fulfill({ status: 404, body: '' });
      });
      await page.goto('http://wa.test/');
      assert.strictEqual(await page.locator('.sb-ui-step').count(), 7, 'seven set-up steps');
      assert((await page.locator('code').allTextContents()).some(t => t === 'https://inverness.example/wp-json/sprint-booking/v1/whatsapp/webhook'), 'webhook address is shown');
      assert.strictEqual(await page.locator('text=Verify token').count() > 0, provider === 'meta', 'verify token only for Meta');
      assert(/Recipient is not a valid WhatsApp user/.test(await page.textContent('body')), 'last error shown');
      assert(/2 people have replied STOP/.test(await page.textContent('body')), 'opt-out count shown without numbers');
      assert.strictEqual(await page.locator('.notice-success').count(), 1, 'the last check result is shown');
      const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      assert(over <= 0, `no horizontal scroll at ${size.width}px: ${over}`);
      if (size.width === 1280) await page.screenshot({ path: `${OUT}/wa-admin-${provider}.png`, fullPage: true });
      await page.close();
    }
  }
  assert.deepStrictEqual(errors, [], 'no JS errors');
  console.log('WHATSAPP ADMIN E2E PASSED');
  await browser.close();
})().catch(e => { console.error('WHATSAPP ADMIN E2E FAILED:', e.message); process.exit(1); });
