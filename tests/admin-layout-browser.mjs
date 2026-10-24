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
      for (const name of ['users', 'accounting', 'settings']) {
        await page.goto(url(name));
        const geometry = await page.evaluate(() => ({
          width: document.querySelector('.panel-main').getBoundingClientRect().width,
          left: document.querySelector('.panel-main').getBoundingClientRect().left,
          sidebarLeft: document.querySelector('.panel-sidebar').getBoundingClientRect().left,
          overflow: document.documentElement.scrollWidth > innerWidth + 1,
        }));
        assert.equal(geometry.overflow, false, `${name}: page overflow at ${width}px`);
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

      await page.goto(url('customer'));
      assert.equal(await page.locator('body.admin-page, #admin-sidebar, .admin-header, [href*="admin-layout.css"], [src*="admin-layout.js"]').count(), 0);
      assert.equal(await page.locator('.nav-band').count(), 1);
      assert.equal(await page.locator('footer.foot').count(), 1);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
      if (width === 390 || width === 1440) await page.screenshot({ animations: 'disabled', path: `/tmp/admin-layout-screenshots/customer-${width}.png` });
      await page.close();
    }
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
