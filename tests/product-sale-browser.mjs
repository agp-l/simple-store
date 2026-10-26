import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { chromium } from 'playwright';

test('sale editor: responsive layout, drafts, fees, selected partner and safe submission', async () => {
  const files = ['assisted', 'own', 'error'].map(mode => `tests/fixtures/sale-${mode}.html`);
  for (const [i, mode] of ['assisted', 'own', 'error'].entries()) {
    writeFileSync(files[i], execFileSync('php', ['tests/fixtures/product-variants.php', '--admin', '--sale', '--' + mode]));
  }
  const server = spawn('php', ['-S', '127.0.0.1:8767', '-t', process.cwd()], { stdio: 'ignore' });
  let browser;
  const url = file => 'http://127.0.0.1:8767/' + file;
  try {
    for (let i = 0; i < 50; i++) {
      try { await (await fetch(url(files[0]))).arrayBuffer(); break; }
      catch { await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    browser = await chromium.launch({ headless: true });
    mkdirSync('/tmp/sale-screenshots', { recursive: true });
    for (const width of [1440, 768, 390]) {
      const page = await browser.newPage({ viewport: { width, height: 1000 } });
      const errors = []; page.on('pageerror', error => errors.push(error.message));
      await page.route('https://fonts.**/*', route => route.abort());
      await page.goto(url(files[0]));
      const root = page.locator('#product-sale');
      const form = root.locator('form');
      const cost = form.locator('[name="supplier_price_czk"]');
      assert.equal(await form.locator('[name="delivery_note"]').count(), 0);
      assert.equal(await form.locator('[data-sale-readiness]').isVisible(), false);
      assert.match(await form.locator('[data-sale-fee]').textContent(), /122/);
      assert.equal(await form.getAttribute('data-dirty'), 'false');
      assert.equal(await root.evaluate(el => getComputedStyle(el.querySelector('.sale-heading')).backgroundColor), 'rgba(0, 0, 0, 0)');
      if (width === 1440) assert.ok((await root.boundingBox()).height < 470, 'Desktop section is unnecessarily tall.');
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      await root.screenshot({ path: `/tmp/sale-screenshots/assisted-${width}.png` });
      await cost.fill('1700');
      assert.match(await form.locator('[data-sale-fee]').textContent(), /100/);
      assert.equal(await form.getAttribute('data-dirty'), 'true');
      await cost.fill('1900');
      assert.match(await form.locator('[data-sale-readiness]').textContent(), /nesmí převýšit/);
      assert.equal(await cost.getAttribute('aria-invalid'), 'true');
      await cost.fill('1700');
      await form.locator('[name="partner_id"]').selectOption('2');
      assert.match(await form.locator('[data-sale-partner-edit]').getAttribute('href'), /id=2/);
      assert.match(await form.locator('[data-sale-partner-status]').textContent(), /Doplň/);
      await form.locator('[name="mode"][value="own"]').check();
      assert.equal(await form.locator('[data-sale-assistance]').isVisible(), false);
      assert.equal(await form.evaluate(el => new FormData(el).get('supplier_price_czk')), '1700');
      assert.equal(await form.evaluate(el => new FormData(el).get('partner_id')), '2');
      await root.screenshot({ path: `/tmp/sale-screenshots/own-${width}.png` });
      await form.locator('[name="mode"][value="assisted"]').check();
      await form.locator('[data-sale-new] summary').click();
      await form.locator('[name="partner_name"]').fill('Nový rozepsaný partner');
      assert.equal(await form.locator('[name="partner_id"]').inputValue(), '0');
      assert.equal(await form.locator('[data-sale-partner-edit]').isVisible(), false);
      await root.screenshot({ path: `/tmp/sale-screenshots/new-${width}.png` });
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      await form.getByRole('button', { name: 'Zrušit nového', exact: true }).click();
      assert.equal(await form.locator('[name="partner_id"]').inputValue(), '2');
      assert.equal(await form.locator('[name="partner_name"]').inputValue(), '');
      await form.locator('[name="partner_id"]').selectOption('1');
      await form.locator('.sale-variant-prices summary').click();
      const variant = form.locator('[data-sale-variant]').first();
      await variant.locator('input').fill('1750');
      assert.match(await variant.locator('output').textContent(), /50/);
      await variant.locator('input').fill('1900');
      assert.equal(await variant.locator('input').getAttribute('aria-invalid'), 'true');
      await variant.locator('input').fill('1750');
      await form.getByRole('button', { name: 'Použít společnou nákupní cenu u všech variant' }).click();
      assert.equal(await variant.locator('input').inputValue(), '');
      assert.match(await variant.locator('output').textContent(), /100/);
      assert.equal(await cost.inputValue(), '1700');
      let submitted;
      await page.route('**/admin/', async route => {
        submitted = new URLSearchParams(route.request().postData());
        await route.fulfill({ body: '<p>Uloženo ve fixture</p>', contentType: 'text/html; charset=utf-8' });
      });
      await form.getByRole('button', { name: 'Uložit způsob prodeje', exact: true }).click();
      await page.getByText('Uloženo ve fixture').waitFor();
      assert.equal(submitted.get('action'), 'product-sale');
      assert.equal(submitted.get('supplier_price_czk'), '1700');
      assert.equal(submitted.get('partner_id'), '1');
      assert.equal(submitted.get('mode'), 'assisted');
      assert.ok(Array.from(submitted).filter(([key]) => key.startsWith('variant_price[')).every(([, value]) => value === ''));
      assert.ok(submitted.has('csrf') && submitted.has('version'));
      assert.deepEqual(errors, []);
      await page.close();
    }
    const page = await browser.newPage();
    await page.route('https://**/*', route => route.abort());
    await page.goto(url(files[2]));
    assert.equal(await page.locator('[name="mode"][value="own"]').isChecked(), true);
    assert.equal(await page.locator('[data-sale-assistance]').isVisible(), true, 'Failed fields must remain accessible even for own mode.');
    assert.equal(await page.locator('[name="partner_name"]').inputValue(), 'Nový rozpracovaný partner');
    let draft;
    await page.route('**/admin/', async route => {
      draft = new URLSearchParams(route.request().postData());
      await route.fulfill({ body: '<p>Nový uložen</p>', contentType: 'text/html; charset=utf-8' });
    });
    await page.locator('[name="supplier_price_czk"]').fill('');
    await page.getByRole('button', { name: 'Uložit nového prodávajícího a nastavení', exact: true }).click();
    await page.getByText('Nový uložen').waitFor();
    assert.equal(draft.get('create_partner'), '1');
    assert.equal(draft.get('partner_name'), 'Nový rozpracovaný partner');
    assert.equal(draft.get('supplier_price_czk'), '');
    await page.close();

    const editing = await browser.newPage();
    await editing.route('https://**/*', route => route.abort());
    await editing.goto(url(files[0]));
    await editing.locator('[name="supplier_price_czk"]').fill('1700');
    const savedPrices = [];
    await editing.route('**/admin/', async route => {
      const post = new URLSearchParams(route.request().postData());
      assert.equal(post.get('action'), 'inline-product');
      assert.equal(post.get('field'), 'price_czk');
      savedPrices.push(post.get('value'));
      await route.fulfill({ json: { revision: 1 + savedPrices.length, url: url(files[0]) } });
    });
    const retail = editing.locator('.detail-prices [data-edit-field="price_czk"]');
    await retail.focus();
    await retail.press('ControlOrMeta+A');
    await editing.keyboard.insertText('2100');
    await editing.locator('#product-sale-title').click();
    await editing.waitForFunction(() => document.querySelector('[data-sale-fee]').textContent.includes('400'));
    await editing.waitForTimeout(650);
    assert.deepEqual(savedPrices, ['2100']);
    await retail.focus();
    assert.equal(await retail.textContent(), '2100', 'A deferred reload restored a stale product price on focus.');
    await retail.press('ControlOrMeta+A');
    await editing.keyboard.insertText('1800');
    await editing.locator('#product-sale-title').click();
    await editing.waitForFunction(() => document.querySelector('[data-sale-fee]').textContent.includes('100'));
    assert.deepEqual(savedPrices, ['2100', '1800']);
    assert.equal(await editing.locator('[name="supplier_price_czk"]').inputValue(), '1700', 'Inline autosave reloaded and erased the sale draft.');
    assert.equal(await editing.locator('[data-product-sale-form]').getAttribute('data-dirty'), 'true');
    await editing.close();

    const noJs = await browser.newPage({ javaScriptEnabled: false });
    await noJs.route('https://**/*', route => route.abort());
    await noJs.goto(url(files[1]));
    assert.equal(await noJs.locator('[name="supplier_price_czk"]').isVisible(), true, 'Without JS all fields must remain usable.');
    await noJs.locator('[name="mode"][value="assisted"]').check();
    assert.equal(await noJs.locator('[data-product-sale-form]').evaluate(el => new FormData(el).get('mode')), 'assisted');
    await noJs.close();
  } finally {
    await browser?.close(); server.kill(); for (const file of files) unlinkSync(file);
  }
});
