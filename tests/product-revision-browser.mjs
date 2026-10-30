import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { writeFileSync, unlinkSync } from 'node:fs';
import { chromium } from 'playwright';

test('product history opens a read-only snapshot with working gallery and return link', async () => {
  const preview = execFileSync('php', ['tests/fixtures/product-revision.php']).toString();
  const editor = execFileSync('php', ['tests/fixtures/product-revision.php', '--editor']).toString();
  const file = 'tests/fixtures/product-history-test.html';
  writeFileSync(file, editor);
  const server = spawn('php', ['-S', '127.0.0.1:8796', '-t', process.cwd()], { stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 50; i++) {
      try { await fetch(`http://127.0.0.1:8796/${file}`); break; }
      catch { await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    browser = await chromium.launch({ headless: true });
    for (const width of [1440, 390]) {
      const context = await browser.newContext({ viewport: { width, height: 1000 } });
      const errors = []; const posts = [];
      context.on('page', page => page.on('pageerror', error => errors.push(error.message)));
      context.on('request', request => { if (request.method() === 'POST') posts.push(request.url()); });
      await context.route('https://**/*', route => route.abort());
      await context.route('**/cs/produkt/current?*', route => route.fulfill({
        contentType: 'text/html', body: route.request().url().includes('preview_revision=1') ? preview : editor,
      }));
      const page = await context.newPage();
      await page.goto(`http://127.0.0.1:8796/${file}`);
      const popupPromise = context.waitForEvent('page');
      await page.locator('#product-history a').click();
      const popup = await popupPromise; await popup.waitForLoadState();
      assert.equal(await popup.locator('.detail-info h1').textContent(), 'Historický batoh');
      assert.equal(await page.locator('.detail-info h1').textContent(), 'Aktuální batoh');
      assert.equal(await popup.locator('#detail-add, #qty, [contenteditable], #inline-editor-config, [data-editor-action]').count(), 0);
      assert.equal(await popup.locator('form.product-purchase').count(), 0);
      assert.match(await popup.locator('.detail-prices').textContent(), /1\s*234/);
      await popup.locator('[data-gallery-image]').nth(1).click();
      assert.match(await popup.locator('#detail-image').getAttribute('src'), /batoh.webp$/);
      await popup.locator('#detail-image-open').click();
      assert.equal(await popup.locator('#detail-lightbox').evaluate(el => el.open), true);
      await popup.keyboard.press('ArrowLeft');
      assert.match(await popup.locator('#detail-lightbox-image').getAttribute('src'), /product-placeholder.png$/);
      await popup.locator('#detail-lightbox-close').click();
      await popup.getByRole('link', { name: 'Zpět k historii a obnovení verze' }).click();
      assert.equal(await popup.locator('#product-history').getAttribute('open'), '');
      assert.equal(await popup.locator('[data-editor-action="restore"]').count(), 1);
      assert.deepEqual(posts, [], 'Viewing history must not send mutations');
      assert.deepEqual(errors, []);
      await context.close();
    }
  } finally { await browser?.close(); server.kill(); unlinkSync(file); }
});
