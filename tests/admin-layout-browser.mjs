import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { chromium } from 'playwright';

test('admin workspace: equal widths, accessible mobile navigation and customer isolation', async () => {
  const fixtures = {
    users: ['admin-users.php', 'detail'],
    accounting: ['evidence-workspace.php', 'overview', 'shell'],
    settings: ['product-sales-mass.php', 'start'],
    overview: ['product-sales-mass.php', 'overview'],
    orders: ['admin-orders.php'],
    orderStates: ['admin-orders.php', 'states'],
    customer: ['customer-layout.php'],
  };
  const files = [];
  let server, browser;
  const url = name => `http://127.0.0.1:8788/tests/fixtures/layout-${name}.html`;
  try {
    for (const [name, [fixture, ...args]] of Object.entries(fixtures)) {
      const file = `tests/fixtures/layout-${name}.html`;
      files.push(file);
      writeFileSync(file, execFileSync('php', [`tests/fixtures/${fixture}`, ...args]));
    }
    server = spawn('php', ['-S', '127.0.0.1:8788', '-t', process.cwd()], { stdio: 'ignore' });
    for (let i = 0; i < 50; i++) {
      try { await fetch(url('users')); break; }
      catch { await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    browser = await chromium.launch({ headless: true });
    mkdirSync('/tmp/admin-layout-screenshots', { recursive: true });
    const errors = [];
    for (const width of [320, 390, 768, 1080, 1100, 1440, 1920]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      page.on('pageerror', error => errors.push(error.message));
      await page.route('https://**/*', route => route.abort());
      let expectedMain;
      for (const name of ['users', 'accounting', 'settings', 'overview', 'orders']) {
        await page.goto(url(name));
        const geometry = await page.evaluate(() => ({
          width: document.querySelector('.panel-main').getBoundingClientRect().width,
          left: document.querySelector('.panel-main').getBoundingClientRect().left,
          sidebarLeft: document.querySelector('.panel-sidebar').getBoundingClientRect().left,
          overflow: document.documentElement.scrollWidth > innerWidth + 1,
        }));
        assert.equal(geometry.overflow, false, `${name}: page overflow at ${width}px`);
        if (width === 1440) {
          assert.equal(await page.locator('.admin-navigation').evaluate(el => el.scrollHeight > el.clientHeight + 1), false, 'Menu fits a standard desktop without scrolling.');
        }
        expectedMain ??= [geometry.width, geometry.left];
        assert.deepEqual([geometry.width, geometry.left], expectedMain, `Workspace differs for ${name} at ${width}px`);
        if (width > 1080) {
          assert.equal(geometry.sidebarLeft, 0, 'Desktop menu must touch the left edge.');
          assert.ok(geometry.width > width * .75, 'Desktop content must use available space.');
        }
        if ([390, 1440].includes(width)) {
          await page.screenshot({ animations: 'disabled', path: `/tmp/admin-layout-screenshots/${name}-${width}.png` });
        }
      }
      await page.goto(url('users'));
      const toggle = page.getByRole('button', { name: 'Otevřít menu administrace' });
      const sidebar = page.locator('#admin-sidebar');
      if (width <= 1080) {
        assert.equal(await sidebar.evaluate(el => el.inert), true);
        await toggle.click();
        assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
        assert.equal(await sidebar.getAttribute('aria-modal'), 'true');
        assert.equal(await page.locator('.panel-main').evaluate(el => el.inert), true);
        const close = page.getByRole('button', { name: 'Zavřít menu' });
        assert.equal(await close.evaluate(el => el === document.activeElement), true);
        await page.keyboard.press('Shift+Tab');
        assert.equal(await sidebar.getByRole('button', { name: 'Odhlásit se' }).evaluate(el => el === document.activeElement), true);
        await page.keyboard.press('Tab');
        assert.equal(await close.evaluate(el => el === document.activeElement), true);
        if (width === 390) await page.screenshot({ animations: 'disabled', path: '/tmp/admin-layout-screenshots/menu-390.png' });
        await page.keyboard.press('Escape');
        assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
        assert.equal(await toggle.evaluate(el => el === document.activeElement), true);
        assert.equal(await page.locator('.panel-main').evaluate(el => el.inert), false);
        await toggle.click();
        await page.locator('[data-admin-backdrop]').click({ position: { x: width - 10, y: 100 } });
        assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
        await toggle.click();
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.waitForFunction(() => !document.querySelector('.panel-main').inert);
        assert.equal(await sidebar.evaluate(el => el.inert), false);
        assert.equal(await page.locator('.panel-main').evaluate(el => el.inert), false);
        await page.setViewportSize({ width, height: 900 });
        await page.waitForFunction(() => document.querySelector('#admin-sidebar').inert);
        assert.equal(await sidebar.evaluate(el => el.inert), true);
      } else {
        assert.equal(await toggle.isVisible(), false);
        assert.equal(await sidebar.isVisible(), true);
      }
      await page.getByText('Rychlé akce', { exact: true }).locator('..').click();
      assert.equal(await page.locator('.admin-shortcuts').evaluate(el => el.open), true);
      assert.equal(await page.locator('.site-admin-bar').isVisible(), true);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
      await page.keyboard.press('Escape');
      assert.equal(await page.locator('.admin-shortcuts').evaluate(el => el.open), false);

      await page.locator('.admin-store-menu > summary').click();
      assert.equal(await page.getByRole('button', { name: 'Náhled jako návštěvník' }).isVisible(), true);
      assert.equal(await sidebar.locator('form').count(), 1, 'Sidebar has only a compact logout; preview is in the header.');
      await page.keyboard.press('Escape');
      if (width === 390) {
        let preview;
        await page.route('**/admin/', async route => {
          preview = new URLSearchParams(route.request().postData());
          await route.fulfill({ body: '<p>Náhled otevřen</p>', contentType: 'text/html; charset=utf-8' });
        });
        await page.locator('.admin-store-menu > summary').click();
        await page.getByRole('button', { name: 'Náhled jako návštěvník' }).click();
        await page.getByText('Náhled otevřen').waitFor();
        assert.equal(preview.get('action'), 'visitor-preview');
        assert.equal(preview.get('csrf'), 'test-token');
        assert.equal(preview.get('enabled'), '1');
        assert.equal(preview.get('return_to'), '/cs');
      }

      await page.goto(url('customer'));
      assert.equal(await page.locator('body.admin-page, #admin-sidebar, .admin-header, [href*="admin-layout.css"], [src*="admin-layout.js"]').count(), 0);
      assert.equal(await page.locator('.nav-band').count(), 1);
      assert.equal(await page.locator('footer.foot').count(), 1);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
      if (width === 390 || width === 1440) await page.screenshot({ animations: 'disabled', path: `/tmp/admin-layout-screenshots/customer-${width}.png` });
      await page.close();
    }
    // The compact queue must keep the existing guarded actions usable without disclosures.
    for (const width of [390, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.route('https://**/*', route => route.abort());
      await page.goto(url('orderStates'));
      assert.equal(await page.locator('.panel-order-table details').count(), 0);
      assert.equal(await page.locator('.panel-order-table thead th').count(), 4);
      const row = number => page.locator('.panel-order-table tbody tr').filter({ has: page.getByRole('link', { name: number, exact: true }) });
      assert.equal(await row('PAID-AFTER-CANCEL').locator('form').count(), 0);
      assert.match(await row('PAID-AFTER-CANCEL').textContent(), /Zaplaceno po stornu/);
      assert.equal(await row('GATEWAY-BLOCKED').locator('form').count(), 0);
      assert.match(await row('GATEWAY-BLOCKED').textContent(), /Platba k ověření/);
      assert.deepEqual(await row('PACKETA-BLOCKED').locator('select[name="order_status"] option').evaluateAll(options => options.map(o => o.value)), ['processing']);
      assert.deepEqual(await row('PACKETA-MANUAL').locator('select[name="order_status"] option').evaluateAll(options => options.map(o => o.value)), ['processing', 'ready_to_ship', 'shipped']);
      assert.deepEqual(await row('PACKETA-UNCERTAIN').locator('select[name="order_status"] option').evaluateAll(options => options.map(o => o.value)), ['processing']);
      assert.equal(await row('HISTORICAL-TEST').count(), 1, 'Historical test orders stay visible.');
      assert.equal(await page.getByRole('navigation', { name: 'Filtrovat objednávky' }).getByRole('link', { name: 'Testovací', exact: true }).count(), 0);
      const fioAction = new URL(await page.locator('form').filter({ has: page.locator('input[name="action"][value="fio-sync"]') }).getAttribute('action'), url('orderStates'));
      assert.equal(fioAction.searchParams.get('status'), 'all');
      assert.equal(fioAction.searchParams.get('payment'), 'bank_transfer');
      assert.equal(fioAction.searchParams.get('q'), 'Eva & syn');
      assert.equal(fioAction.searchParams.get('offset'), '25');
      const posts = [];
      await page.route('**/admin/orders/**', async route => {
        posts.push(new URLSearchParams(route.request().postData()));
        await route.fulfill({ body: '<p>Akce zachycena v testu</p>', contentType: 'text/html; charset=utf-8' });
      });
      for (const [number, action, checkbox, expected] of [
        ['OVERDUE', 'mark-order-paid', 'bank_checked', { id: '4', bank_checked: '1', return_list: '1' }],
        ['OVERDUE', 'cancel-overdue-bank-order', 'bank_checked', { id: '4', bank_checked: '1' }],
        ['DB-20261024-002', 'set-order-status', '', { id: '2', order_status: 'cancelled', return_list: '1' }],
      ]) {
        const form = row(number).locator('form').filter({ has: page.locator(`input[name="action"][value="${action}"]`) });
        assert.equal(await form.getByRole('button').isVisible(), true);
        assert.equal(await form.evaluate(el => el.checkValidity()), false);
        const count = posts.length;
        await form.getByRole('button').click();
        assert.equal(posts.length, count, 'Unchecked confirmation must prevent submission.');
        await form.locator(checkbox ? `input[name="${checkbox}"]` : 'input[type="checkbox"]').check();
        await form.getByRole('button').click();
        await page.getByText('Akce zachycena v testu').waitFor();
        const post = posts.at(-1);
        assert.equal(post.get('action'), action);
        if (action === 'set-order-status') assert.match(post.get('expected_fulfillment'), /^[a-f0-9]{64}$/);
        assert.equal(post.get('csrf'), 'test-token');
        for (const [key, value] of Object.entries(expected)) assert.equal(post.get(key), value);
        await page.goto(url('orderStates'));
      }
      const fulfillment = row('DB-20261024-001').locator('form');
      await fulfillment.locator('select').selectOption('shipped');
      await fulfillment.getByRole('button', { name: /Uložit stav/ }).click();
      await page.getByText('Akce zachycena v testu').waitFor();
      assert.equal(posts.at(-1).get('order_status'), 'shipped');
      assert.match(posts.at(-1).get('expected_fulfillment'), /^[a-f0-9]{64}$/);
      assert.equal(posts.at(-1).get('id'), '1');
      assert.equal(posts.at(-1).get('csrf'), 'test-token');
      await page.close();
    }
    const short = await browser.newPage({ viewport: { width: 1280, height: 650 } });
    await short.route('https://**/*', route => route.abort());
    await short.goto(url('orders'));
    const logout = short.getByRole('button', { name: 'Odhlásit se' });
    const rect = await logout.boundingBox();
    assert.ok(rect.y >= 0 && rect.y + rect.height <= 650, 'Logout remains visible on short screens.');
    await short.locator('#admin-sidebar').getByRole('link', { name: 'Databáze', exact: true }).scrollIntoViewIfNeeded();
    assert.equal(await short.evaluate(() => scrollY), 0, 'Sidebar scroll must not move the content.');
    await short.screenshot({ animations: 'disabled', path: '/tmp/admin-layout-screenshots/short-desktop.png' });
    await short.close();
    const noJs = await browser.newPage({ viewport: { width: 390, height: 900 }, javaScriptEnabled: false });
    await noJs.route('https://**/*', route => route.abort());
    await noJs.goto(url('users'));
    assert.equal(await noJs.getByRole('button', { name: 'Otevřít menu administrace' }).isVisible(), false);
    assert.equal(await noJs.locator('#admin-sidebar').getByRole('link', { name: 'Doklady a platby' }).isVisible(), true);
    await noJs.close();
    assert.deepEqual(errors, []);
  } finally {
    if (browser) await browser.close();
    server?.kill();
    files.forEach(file => { try { unlinkSync(file); } catch {} });
  }
});
