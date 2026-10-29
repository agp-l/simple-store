import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn, execFileSync} from 'node:child_process';
import {mkdirSync, writeFileSync, unlinkSync} from 'node:fs';
import {chromium} from 'playwright';

test('homepage order buttons on desktop, mobile and without JavaScript', async () => {
  const fixture = 'tests/fixtures/homepage-editor.html';
  writeFileSync(fixture, execFileSync('php', ['tests/fixtures/homepage-editor.php']));
  const server = spawn('php', ['-S', '127.0.0.1:8797', '-t', process.cwd()], {stdio: 'ignore'});
  const url = 'http://127.0.0.1:8797/' + fixture;
  const original = ['1'.repeat(32), '2'.repeat(32), '3'.repeat(32)];
  let browser;
  try {
    for (let i = 0; i < 50; i++) {
      try { await fetch(url); break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    browser = await chromium.launch({headless: true});
    mkdirSync('/tmp/homepage-editor-screenshots', {recursive: true});
    for (const width of [1440, 390]) {
      const page = await browser.newPage({viewport: {width, height: 1000}});
      const errors = []; page.on('pageerror', error => errors.push(error.message));
      await page.route('https://**/*', route => route.abort());
      let saved = [...original], reject = false, requests = 0;
      await page.route('**/admin/', async route => {
        requests++;
        const data = new URLSearchParams(route.request().postData());
        assert.equal(data.get('csrf'), 'fixture-token');
        assert.equal(data.get('action'), 'product-selection-order');
        assert.equal(data.get('scope'), 'home'); assert.equal(data.get('language'), 'cs');
        assert.deepEqual(JSON.parse(data.get('expected')), saved);
        if (reject) { await route.fulfill({status: 422, json: {error: 'Výběr se mezitím změnil. Obnov stránku.'}}); return; }
        saved = JSON.parse(data.get('order'));
        await route.fulfill({json: {saved: true}});
      });
      await page.goto(url);
      const list = page.locator('[data-product-selection]');
      const second = list.locator('[data-key="' + original[1] + '"]');
      const order = () => list.locator('li').evaluateAll(rows => rows.map(row => row.dataset.key));
      const complete = () => page.getByText('Pořadí uloženo.', {exact: true}).waitFor();
      await second.getByRole('button', {name: 'Posunout nahoru:', exact: false}).click(); await complete();
      assert.deepEqual(await order(), [original[1], original[0], original[2]]);
      assert.deepEqual(await page.locator('#catalog [data-product-key]').evaluateAll(cards => cards.map(card => card.dataset.productKey)), saved);
      assert.deepEqual(await list.locator('[data-selection-position]').allTextContents(), ['1', '2', '3']);
      assert.equal(await second.getByRole('button', {name: 'Posunout nahoru:', exact: false}).isDisabled(), true);
      await second.getByRole('button', {name: 'Posunout dolů:', exact: false}).click(); await complete();
      assert.deepEqual(await order(), original);
      reject = true;
      await second.getByRole('button', {name: 'Posunout nahoru:', exact: false}).click();
      await page.getByText('Výběr se mezitím změnil. Obnov stránku.', {exact: true}).waitFor();
      assert.deepEqual(await order(), original); assert.deepEqual(saved, original);
      reject = false;
      await second.locator('.selection-drag').focus(); await page.keyboard.press('Alt+ArrowUp'); await complete();
      assert.equal(requests, 4);
      assert.deepEqual(await order(), [original[1], original[0], original[2]]);
      await page.locator('#homepage-editor').screenshot({path: `/tmp/homepage-editor-screenshots/editor-${width}.png`});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
      assert.deepEqual(errors, []); await page.close();
    }
    const fallback = await browser.newPage({javaScriptEnabled: false});
    let submitted;
    await fallback.route('**/admin/', async route => {
      submitted = new URLSearchParams(route.request().postData());
      await route.fulfill({contentType: 'text/html; charset=utf-8', body: '<p>Pořadí přijato</p>'});
    });
    await fallback.goto(url);
    await fallback.getByRole('button', {name: 'Posunout nahoru: Lehký spacák do hor', exact: true}).click();
    await fallback.getByText('Pořadí přijato').waitFor();
    assert.equal(submitted.get('action'), 'homepage-product');
    assert.equal(submitted.get('operation'), 'up');
    assert.equal(submitted.get('key'), original[1]);
    assert.equal(submitted.get('csrf'), 'fixture-token');
  } finally {
    await browser?.close(); server.kill(); unlinkSync(fixture);
  }
});
