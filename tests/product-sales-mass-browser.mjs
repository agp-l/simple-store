import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { chromium } from 'playwright';

test('bulk sale modes: responsive admin preview and explicit no-JS confirmation', async () => {
  const cases = ['start', 'assisted', 'own', 'same', 'empty', 'uninstalled'];
  const files = cases.map(mode => `tests/fixtures/sales-mass-${mode}.html`);
  cases.forEach((mode, i) => writeFileSync(files[i], execFileSync('php', ['tests/fixtures/product-sales-mass.php', mode])));
  const server = spawn('php', ['-S', '127.0.0.1:8781', '-t', process.cwd()], { stdio: 'ignore' });
  const url = mode => `http://127.0.0.1:8781/tests/fixtures/sales-mass-${mode}.html`;
  let browser;
  try {
    for (let i = 0; i < 50; i++) {
      try { await fetch(url('start')); break; }
      catch { await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    browser = await chromium.launch({ headless: true });
    mkdirSync('/tmp/sales-mass-screenshots', { recursive: true });
    for (const width of [1440, 390]) {
      const page = await browser.newPage({ viewport: { width, height: 1000 }, javaScriptEnabled: false });
      await page.route('https://**/*', route => route.abort());
      let submitted;
      await page.route('**/admin/**', async route => {
        submitted = new URLSearchParams(route.request().postData());
        await route.fulfill({ body: '<p>Odesláno ve fixture</p>', contentType: 'text/html; charset=utf-8' });
      });
      await page.goto(url('start'));
      let root = page.locator('#bulk-product-sales');
      assert.equal(await root.locator('form').count(), 1, 'Nothing may apply without preview.');
      await root.getByLabel('Způsob prodeje všech produktů').selectOption('assisted');
      await root.getByRole('button', { name: 'Zobrazit náhled přepnutí' }).click();
      await page.getByText('Odesláno ve fixture').waitFor();
      assert.equal(submitted.get('action'), 'products-sale-preview');
      assert.equal(submitted.get('sale_mode'), 'assisted');
      assert.equal(submitted.get('csrf'), 'test-token');
      for (const mode of ['assisted', 'own']) {
        await page.goto(url(mode));
        root = page.locator('#bulk-product-sales');
        if (mode === 'assisted') {
          assert.equal(await root.locator('details').getAttribute('open'), null);
          await root.locator('summary').click();
          assert.equal(await root.getByRole('link', { name: 'Lehká bunda <test> ↗' }).getAttribute('href'), '/cs/produkt/lehka-bunda?edit=1#product-sale');
          assert.match(await root.textContent(), /zákazník je přes asistovaný nákup neobjedná/);
        } else assert.equal(await root.locator('details').count(), 0);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Mobile page overflows.');
        await root.screenshot({ path: `/tmp/sales-mass-screenshots/${mode}-${width}.png` });
        await root.getByRole('button', { name: /^Přepnout všechny produkty:/ }).click();
        await page.getByText('Odesláno ve fixture').waitFor();
        assert.equal(submitted.get('action'), 'products-sale-apply');
        assert.equal(submitted.get('preview_token'), 'preview-token');
        assert.equal(submitted.get('csrf'), 'test-token');
        assert.equal(submitted.get('tab'), 'products');
        assert.equal(submitted.has('sale_mode'), false, 'Apply must use the server-side preview mode.');
      }
      await page.goto(url('same'));
      assert.equal(await page.locator('#bulk-product-sales').getByRole('button', { name: /^Přepnout všechny produkty:/ }).count(), 0);
      await page.goto(url('empty'));
      assert.equal(await page.locator('#bulk-product-sales button').isDisabled(), true);
      await page.goto(url('uninstalled'));
      assert.equal(await page.locator('#bulk-product-sales form').count(), 0);
      await page.close();
    }
  } finally {
    if (browser) await browser.close();
    server.kill(); files.forEach(file => unlinkSync(file));
  }
});
