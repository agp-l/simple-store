import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { chromium } from 'playwright';

// Real PHP templates and browser form submission, with no live credentials or database.
test('variant controls on desktop, mobile, keyboard and PHP-only fallback', async () => {
  const publicPath = 'tests/fixtures/variant-public.html';
  const adminPath = 'tests/fixtures/variant-admin.html';
  writeFileSync(publicPath, execFileSync('php', ['tests/fixtures/product-variants.php']));
  writeFileSync(adminPath, execFileSync('php', ['tests/fixtures/product-variants.php', '--admin']));
  const server = spawn('php', ['-S', '127.0.0.1:8765', '-t', process.cwd()], { stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 50; i++) {
      try { await fetch('http://127.0.0.1:8765/' + publicPath); break; }
      catch { await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    browser = await chromium.launch({ headless: true });
    mkdirSync('/tmp/variant-screenshots', { recursive: true });
    for (const width of [1440, 390]) {
      const page = await browser.newPage({ viewport: { width, height: 1050 } });
      const errors = []; page.on('pageerror', error => errors.push(error.message));
      await page.route('https://**/*', route => route.abort());
      await page.goto('http://127.0.0.1:8765/' + publicPath);
      await page.locator('#variant-choice-0').waitFor();
      await page.locator('#variant-choice-0').selectOption('Modrá');
      await page.locator('.product-purchase').screenshot({ path: `/tmp/variant-screenshots/selection-${width}.png` });
      assert.equal(await page.locator('#variant-choice-1 option[value="S"]').evaluate(option => option.disabled), true,
        await page.locator('.variant-pickers').evaluate(root => JSON.stringify(Array.from(root.querySelectorAll('select')).map(select => ({value: select.value, options: Array.from(select.options).map(option => ({value: option.value, disabled: option.disabled}))})))));
      await page.locator('#variant-choice-1').selectOption('M');
      const selected = await page.locator('#product-variant').inputValue();
      assert.match(selected, /^[a-f0-9]{32}$/);
      assert.equal(await page.locator('.product-purchase').evaluate(form => new FormData(form).get('variant_key')), selected);
      assert.match(await page.locator('[data-variant-price]').textContent(), /2\s*490/);
      await page.locator('#variant-choice-1').selectOption('L');
      assert.match(await page.locator('[data-variant-price]').textContent(), /2\s*490/);
      await page.locator('#variant-choice-0').selectOption('Červená');
      assert.match(await page.locator('[data-variant-price]').textContent(), /2\s*890/);
      assert.ok(await page.locator('.product-purchase').evaluate(form => form.checkValidity()));
      await page.locator('#variant-choice-0').selectOption('Modrá');
      await page.locator('#variant-choice-1').focus(); await page.keyboard.press('Home'); await page.keyboard.press('ArrowDown');
      assert.equal(await page.locator('#variant-choice-1').inputValue(), 'M');
      await page.locator('.product-purchase').screenshot({ path: `/tmp/variant-screenshots/customer-${width}.png` });
      await page.goto('http://127.0.0.1:8765/' + adminPath);
      assert.equal(await page.locator('#detail-image').getAttribute('src'), '/images/product-placeholder.png');
      assert.ok(await page.locator('#detail-image').evaluate(img => img.complete && img.naturalWidth > 0));
      const basePrice = await page.locator('.detail-prices [data-edit-field="price_czk"]').textContent();
      const photoVariant = await page.locator('#product-variant option[data-image="/images/batoh.webp"]').getAttribute('value');
      await page.locator('#product-variant').selectOption(photoVariant);
      assert.equal(await page.locator('#detail-image').getAttribute('src'), '/images/batoh.webp');
      assert.equal(await page.locator('.detail-prices [data-edit-field="price_czk"]').textContent(), basePrice);
      await page.locator('#detail-image-open').click();
      assert.match(await page.locator('#detail-lightbox-image').getAttribute('src'), /images\/batoh.webp$/);
      await page.locator('#detail-lightbox-close').click();
      await page.locator('#product-variant').selectOption('');
      assert.equal(await page.locator('#detail-image').getAttribute('src'), '/images/product-placeholder.png');
      const rows = page.locator('[data-variant-row]');
      await rows.nth(0).locator('input[name="quantity"]').fill('3');
      await rows.nth(1).locator('input[name="quantity"]').fill('5');
      const prices = rows.locator('input[name="price_czk"]');
      const common = page.locator('[data-variant-base-price]');
      const inherit = page.getByRole('button', { name: 'Použít společnou cenu u všech variant', exact: true });
      assert.equal(await prices.nth(1).inputValue(), '2890');
      await inherit.click();
      assert.ok((await prices.evaluateAll(inputs => inputs.map(input => input.value))).every(value => value === ''));
      assert.equal(await rows.nth(1).locator('[name="quantity"]').inputValue(), '5');
      assert.equal(await page.locator('[data-variant-manager]').getAttribute('data-dirty'), 'true');
      await page.getByRole('button', { name: 'Vrátit původní ceny' }).click();
      assert.equal(await prices.nth(1).inputValue(), '2890');
      await inherit.click();
      await prices.nth(1).fill('2790');
      assert.match(await page.locator('[data-variant-price-count]').innerText(), /1 z 6/);
      const events = [];
      await page.route('**/admin.php', async route => {
        const form = new URLSearchParams(route.request().postData());
        if (form.get('action') === 'inline-product') {
          assert.equal(form.get('field'), 'price_czk');
          const value = form.get('value');
          if (value === '3200') await new Promise(resolve => setTimeout(resolve, 250));
          events.push('price-' + value);
          await route.fulfill({ json: { revision: value === '3000' ? 2 : 3, url: 'http://127.0.0.1:8765/' + adminPath } });
          return;
        }
        assert.equal(form.get('operation'), 'batch');
        const data = JSON.parse(form.get('rows_json'));
        assert.equal(data[0].quantity, '3'); assert.equal(data[1].quantity, '5');
        assert.equal(data[1].price_czk, '2790');
        assert.ok(data.filter((_, i) => i !== 1).every(row => row.price_czk === ''));
        assert.deepEqual(events, ['price-3000', 'price-3200'], 'Rows submitted before the shared price was saved.');
        events.push('batch');
        await route.fulfill({ body: '<p>Saved fixture</p>', contentType: 'text/html; charset=utf-8' });
      });
      await common.focus(); await common.press('ControlOrMeta+A'); await page.keyboard.insertText('3000');
      await page.locator('#variant-heading').click();
      await page.waitForFunction(() => document.querySelector('[data-variant-row] [name="price_czk"]').placeholder === '3000');
      await page.waitForTimeout(500);
      assert.equal(await rows.nth(1).locator('[name="quantity"]').inputValue(), '5', 'Price autosave erased row drafts.');
      assert.equal(await prices.nth(1).inputValue(), '2790');
      assert.match(await page.locator('.detail-prices [data-edit-field="price_czk"]').innerText(), /3\s*000/);
      if (width > 960) assert.ok(await page.locator('#product-variants').evaluate(panel => panel.getBoundingClientRect().width > 1000), 'Variant table must span the editing panel');
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      assert.ok(overflow <= 1, `Entire page overflows by ${overflow}px at ${width}px`);
      await page.locator('#product-variants').screenshot({ path: `/tmp/variant-screenshots/admin-${width}.png` });
      await common.focus(); await common.press('ControlOrMeta+A'); await page.keyboard.insertText('3200');
      await page.getByRole('button', { name: 'Uložit změny variant', exact: true }).click();
      await page.getByText('Saved fixture').waitFor();
      assert.deepEqual(errors, []);
      await page.close();
    }
    const noJs = await browser.newPage({ javaScriptEnabled: false });
    await noJs.goto('http://127.0.0.1:8765/' + publicPath);
    assert.ok(await noJs.locator('#product-variant').isVisible());
    const option = noJs.locator('#product-variant option:enabled').nth(1);
    await noJs.locator('#product-variant').selectOption(await option.getAttribute('value'));
    assert.ok(await noJs.locator('.product-purchase').evaluate(form => form.checkValidity()));
    await noJs.goto('http://127.0.0.1:8765/' + adminPath);
    await noJs.route('**/admin.php', async route => {
      const form = new URLSearchParams(route.request().postData());
      assert.equal(form.get('operation'), 'inherit-prices');
      assert.ok(form.get('csrf') && form.get('expected') && form.get('key'));
      await route.fulfill({ body: '<p>Inherited without JS</p>', contentType: 'text/html' });
    });
    await noJs.getByRole('button', { name: 'Použít společnou cenu u všech variant', exact: true }).click();
    await noJs.getByText('Inherited without JS').waitFor();
    await noJs.close();
  } finally {
    await browser?.close(); server.kill(); unlinkSync(publicPath); unlinkSync(adminPath);
  }
});
