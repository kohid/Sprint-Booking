// Renders the customer emails (written by tests/email-template-test.php with SB_EMAIL_OUT) in a desktop and a phone browser.
// Run: SB_EMAIL_OUT=/tmp/sb-email php tests/email-template-test.php && node tests/e2e/email-e2e.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
const dir = process.env.SB_EMAIL_OUT || '/tmp/sb-email';
function assert(c, m) { if (!c) { console.error('FAIL: ' + m); process.exitCode = 1; } else console.log('ok   - ' + m); }
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });
  // A stand-in logo so the header image has something to load.
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
  for (const [name, vp] of [['desktop', { width: 900, height: 1000 }], ['phone', { width: 375, height: 800 }]]) {
    const ctx = await b.newContext({ viewport: vp }); const p = await ctx.newPage();
    await p.route('**/logo-*.png', r => r.fulfill({ body: png, contentType: 'image/png' }));
    await p.setContent(fs.readFileSync(path.join(dir, 'return.html'), 'utf8'));
    const sw = await p.evaluate(() => document.documentElement.scrollWidth);
    assert(sw <= vp.width, name + ': no horizontal scroll (' + sw + ' <= ' + vp.width + ')');
    assert(await p.locator('img[alt]').count() === 1 && await p.locator('.sbm-ref').count() === 2, name + ': logo and two reference boxes');
    const boxes = await p.locator('.sbm-col').evaluateAll(els => els.map(e => e.getBoundingClientRect()));
    if (name === 'desktop') assert(Math.abs(boxes[0].top - boxes[1].top) < 2 && boxes[1].left > boxes[0].left, 'desktop: references side by side');
    else assert(boxes[1].top > boxes[0].top + 20 && Math.abs(boxes[0].left - boxes[1].left) < 2, 'phone: references stacked');
    const refs = await p.locator('.sbm-ref').evaluateAll(els => els.map(e => { const r = e.getBoundingClientRect(), c = e.closest('td').getBoundingClientRect(); return r.left >= c.left && r.right <= c.right; }));
    assert(refs.every(Boolean), name + ': references fit inside their boxes');
    await p.screenshot({ path: path.join(dir, 'return-' + name + '.png'), fullPage: true });
    await p.setContent(fs.readFileSync(path.join(dir, 'oneway.html'), 'utf8'));
    assert(await p.locator('.sbm-ref').count() === 1, name + ': one-way has one reference');
    await ctx.close();
  }
  await b.close();
})();
