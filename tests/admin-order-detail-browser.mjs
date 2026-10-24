import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { chromium } from 'playwright';

test('order preparation: local dates, exact variants and individual clipboard values', async () => {
  const modes = ['detail-home', 'detail-pickup', 'returns'];
  const files = modes.map(mode => `tests/fixtures/order-review-${mode}.html`);
  let server, browser;
  const url = mode => `http://127.0.0.1:8797/tests/fixtures/order-review-${mode}.html`;
  try {
    modes.forEach((mode, i) => writeFileSync(files[i], execFileSync('php', ['tests/fixtures/admin-orders.php', mode])));
    server = spawn('php', ['-S', '127.0.0.1:8797', '-t', process.cwd()], { stdio: 'ignore' });
    for (let i = 0; i < 50; i++) {
      try { await fetch(url('detail-home')); break; }
      catch { await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    browser = await chromium.launch({ headless: true });
    mkdirSync('/tmp/admin-order-detail-screenshots', { recursive: true });
    const errors = [];
    for (const width of [320, 390, 768, 1100, 1440]) {
      const context = await browser.newContext({ viewport: { width, height: 1000 }, permissions: ['clipboard-read', 'clipboard-write'] });
      const page = await context.newPage();
      page.on('pageerror', error => errors.push(error.message));
      await page.route('https://**/*', route => route.abort());
      for (const mode of modes) {
        await page.goto(url(mode));
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `${mode} overflows at ${width}px`);
        if (mode === 'returns') {
          await page.getByRole('searchbox').fill('DB-2026-123');
          let search;
          await page.route('**/admin/**', async route => {
            search = new URL(route.request().url()).searchParams.get('q');
            await route.fulfill({ body: '<p>Vyhledáno v testu</p>', contentType: 'text/html; charset=utf-8' });
          });
          await page.getByRole('button', { name: 'Hledat', exact: true }).click();
          await page.getByText('Vyhledáno v testu').waitFor();
          assert.equal(search, 'DB-2026-123');
          await page.unroute('**/admin/**');
        } else {
          assert.match(await page.locator('.panel-main').innerText(), /Přijato 14\. 10\. 2026 01:09/);
          assert.match(await page.locator('.panel-main').innerText(), /13\. 10\. 2026 20:40/);
          assert.doesNotMatch(await page.locator('.panel-main').innerText(), /\bUTC\b/);
          assert.deepEqual(await page.locator('.panel-order-variants dd').allTextContents(), ['M', 'Modrá <světlá>', 'Prodloužený']);
          assert.equal(await page.locator('.panel-order-line').last().locator('.panel-order-variant-box').count(), 0);
          assert.equal(await page.locator('.panel-order-variant-box světla, .panel-order-variant-box světlá').count(), 0);
          const fields = mode === 'detail-home' ? {
            'Příjemce': 'Eva Nová & syn', 'E-mail': 'petr@example.test', 'Telefon': '+420 777 123 456',
            'Ulice a číslo domu': 'U Lesa 12/3', 'Město': 'Praha', 'PSČ': '001 02', 'Země': 'CZ',
          } : { 'Výdejní místo': 'Praha & okolí', 'Adresa výdejního místa': 'U Radnice 15, Praha, 110 00', 'Kód výdejního místa': '001234', 'PSČ Balíkovny': '110 00' };
          for (const [label, value] of Object.entries(fields)) {
            const button = page.getByRole('button', { name: `Kopírovat: ${label}`, exact: true });
            await button.click();
            await page.waitForFunction(async expected => await navigator.clipboard.readText() === expected, value);
            assert.equal(await button.locator('.order-copy-done').isVisible(), true);
          }
          if (mode === 'detail-pickup') assert.equal(await page.getByRole('button', { name: 'Kopírovat: Telefon', exact: true }).count(), 0);
          await page.getByRole('button', { name: 'Kopírovat pro dopravce', exact: true }).click();
          await page.waitForFunction(async () => (await navigator.clipboard.readText()).startsWith('Objednávka '));
          assert.match(await page.evaluate(() => navigator.clipboard.readText()), /E-mail: petr@example.test/);
        }
        await page.goto(url(mode));
        await page.screenshot({ animations: 'disabled', path: `/tmp/admin-order-detail-screenshots/${mode}-${width}.png`, fullPage: true });
      }
      await context.close();
    }
    const fallback = await browser.newPage();
    await fallback.route('https://**/*', route => route.abort());
    await fallback.goto(url('detail-home'));
    await fallback.evaluate(() => {
      Object.defineProperty(navigator, 'clipboard', { value: { writeText: async () => { throw new Error('Denied'); } }, configurable: true });
      document.execCommand = command => {
        window.fallbackCopy = document.querySelector('body > textarea')?.value;
        return command === 'copy';
      };
    });
    const copyCity = fallback.getByRole('button', { name: 'Kopírovat: Město', exact: true });
    await copyCity.click();
    await fallback.waitForFunction(() => window.fallbackCopy === 'Praha');
    assert.equal(await copyCity.evaluate(el => el === document.activeElement), true);
    assert.equal(await fallback.locator('body > textarea').count(), 0);
    await fallback.evaluate(() => { document.execCommand = () => false; });
    await copyCity.click();
    await fallback.getByText(/Kopírování selhalo/).waitFor();
    assert.equal(await copyCity.getAttribute('data-copied'), null);
    assert.equal(await copyCity.getAttribute('aria-busy'), null);
    assert.equal(await fallback.locator('body > textarea').count(), 0);
    await fallback.close();
    assert.deepEqual(errors, []);
  } finally {
    await browser?.close();
    server?.kill();
    files.forEach(file => { try { unlinkSync(file); } catch {} });
  }
});
