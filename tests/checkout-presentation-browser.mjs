import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { chromium } from 'playwright';

test('checkout: readable secondary BTC, compact assisted disclosure and historical terms', async () => {
  const modes = ['cart', 'shipping', 'review', 'complete', 'account', 'no-btc', 'legacy'];
  const files = modes.map(mode => `tests/fixtures/presentation-${mode}.html`);
  for (const [i, mode] of modes.entries()) {
    writeFileSync(files[i], execFileSync('php', ['tests/fixtures/checkout-presentation.php',
      mode === 'no-btc' ? 'review' : mode === 'legacy' ? 'complete' : mode, '--' + mode]));
  }
  const server = spawn('php', ['-S', '127.0.0.1:8768', '-t', process.cwd()], { stdio: 'ignore' });
  const url = mode => 'http://127.0.0.1:8768/' + files[modes.indexOf(mode)];
  let browser;
  try {
    for (let i = 0; i < 50; i++) {
      try { await fetch(url('review')); break; }
      catch { await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    browser = await chromium.launch({ headless: true });
    mkdirSync('/tmp/checkout-presentation', { recursive: true });
    for (const width of [1440, 768, 390, 360]) {
      const page = await browser.newPage({ viewport: { width, height: 1000 } });
      await page.route('https://**/*', route => route.abort());
      for (const mode of ['cart', 'shipping', 'review', 'complete', 'account']) {
        await page.goto(url(mode));
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1 ?
          Array.from(document.querySelectorAll('body *')).filter(el => el.getBoundingClientRect().right > innerWidth + 1).map(el => el.tagName + '.' + el.className).slice(-12) : []);
        if (overflow.length) await page.screenshot({ path: `/tmp/checkout-presentation/overflow-${mode}-${width}.png`, fullPage: true });
        assert.deepEqual(overflow, [], `${mode} overflows at ${width}`);
        const money = page.locator('.money').first();
        assert.ok(await money.count());
        assert.equal(await money.locator('.price-secondary').count(), 1);
        const layout = await money.evaluate(el => {
          const primary = el.querySelector('.money-main'); const secondary = el.querySelector('.price-secondary');
          return { below: secondary.getBoundingClientRect().top >= primary.getBoundingClientRect().bottom,
            size: parseFloat(getComputedStyle(secondary).fontSize), primarySize: parseFloat(getComputedStyle(primary).fontSize),
            weight: getComputedStyle(secondary).fontWeight };
        });
        assert.ok(layout.below && layout.size < layout.primarySize && layout.weight === '400', `${mode} BTC is not secondary: ${JSON.stringify(layout)}`);
        if (['cart', 'review', 'complete'].includes(mode)) {
          const disclosure = page.locator('.assisted-item .assisted-details').first();
          assert.equal(await disclosure.getAttribute('open'), null);
          assert.equal(await disclosure.locator('.assisted-details-body').isVisible(), false);
          assert.match(await page.locator('.assisted-breakdown').first().innerText(), /1 678 Kč.*122 Kč/);
          assert.ok(await page.locator('.assisted-item-kind').first().isVisible());
          assert.equal(await page.locator('.assisted-delivery').count(), 1);
          await disclosure.locator('summary').focus();
          await page.keyboard.press('Enter');
          assert.ok(await disclosure.locator('.assisted-details-body').isVisible());
          assert.match(await disclosure.innerText(), /IČO 05769884/);
          await disclosure.locator('summary').click();
        }
        if (['cart','review'].includes(mode)) {
          assert.ok(await page.getByRole('heading',{name:'Kdo vám zboží dodá'}).isVisible());
          assert.equal(await page.locator('.checkout-partner-group').count(),1);
          assert.equal(await page.locator('.checkout-partner-group li').count(),2);
          assert.doesNotMatch(await page.locator('.checkout-partners').innerText(),/Ponožky na výlety/);
        }
        if (mode === 'review') {
          assert.ok(await page.getByRole('button', { name: 'Objednat s povinností platby' }).isVisible());
          assert.equal(await page.locator('.checkout-summary-total .money-main').textContent(), '3 884 Kč');
        }
        await page.screenshot({ path: `/tmp/checkout-presentation/${mode}-${width}.png`, fullPage: true });
      }
      await page.close();
    }
    const noJs = await browser.newPage({ javaScriptEnabled: false, viewport: { width: 390, height: 1000 } });
    await noJs.route('https://**/*', route => route.abort());
    await noJs.goto(url('review'));
    await noJs.locator('.assisted-details summary').first().click();
    assert.ok(await noJs.locator('.assisted-details-body').first().isVisible());
    await noJs.locator('.assisted-delivery summary').click();
    assert.match(await noJs.locator('.assisted-delivery').innerText(), /30 dnů od uzavření kupní smlouvy/);
    await noJs.screenshot({ path: '/tmp/checkout-presentation/review-expanded-mobile.png', fullPage: true });
    await noJs.goto(url('no-btc'));
    assert.equal(await noJs.locator('.price-secondary').count(), 0);
    await noJs.goto(url('legacy'));
    await noJs.locator('.assisted-delivery summary').click();
    assert.match(await noJs.locator('.assisted-delivery').innerText(), /Původně sjednané dodání do 5 pracovních dnů/);
    assert.doesNotMatch(await noJs.locator('.assisted-delivery').innerText(), /30 dnů/);
    await noJs.close();
  } finally {
    await browser?.close(); server.kill(); for (const file of files) unlinkSync(file);
  }
});
