import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { chromium } from 'playwright';

test('accounts: responsive forms, password generation and explicit deletions', async () => {
  const modes = ['detail', 'create', 'uninstalled'];
  const files = modes.map(mode => `tests/fixtures/admin-users-${mode}.html`);
  modes.forEach((mode, i) => writeFileSync(files[i], execFileSync('php', ['tests/fixtures/admin-users.php', mode])));
  const server = spawn('php', ['-S', '127.0.0.1:8787', '-t', process.cwd()], { stdio: 'ignore' });
  const url = mode => `http://127.0.0.1:8787/tests/fixtures/admin-users-${mode}.html`;
  let browser;
  try {
    for (let i=0; i<50; i++) {
      try { await fetch(url('detail')); break; } catch { await new Promise(resolve=>setTimeout(resolve,100)); }
    }
    browser=await chromium.launch({ headless: true });
    mkdirSync('/tmp/admin-users-screenshots', { recursive: true });
    for (const width of [1440,390]) {
      const page=await browser.newPage({ viewport: {width, height:1000} });
      const errors=[]; page.on('pageerror',error=>errors.push(error.message));
      await page.route('https://**/*', route=>route.abort());
      let submitted;
      await page.route('**/admin/**', async route=>{
        submitted=new URLSearchParams(route.request().postData());
        await route.fulfill({body:'<p>Formulář přijat</p>',contentType:'text/html; charset=utf-8'});
      });
      await page.goto(url('detail'));
      const passwordForm=page.locator('[data-user-password]');
      await passwordForm.getByRole('button',{name:'Vygenerovat heslo'}).click();
      const password=passwordForm.locator('input[name=password]');
      const first=await password.inputValue();
      assert.match(first,/^[A-Za-z0-9_-]{24}$/);
      assert.equal(await password.getAttribute('type'),'text');
      await passwordForm.getByRole('button',{name:'Vygenerovat heslo'}).click();
      assert.notEqual(await password.inputValue(),first);
      await passwordForm.getByRole('button',{name:'Skrýt',exact:true}).click();
      assert.equal(await password.getAttribute('type'),'password');
      assert.equal(await page.evaluate(()=>localStorage.length),0,'Passwords must never persist in localStorage.');
      const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2);
      assert.equal(overflow,false,`Page overflow at ${width}px`);
      await page.screenshot({path:`/tmp/admin-users-screenshots/accounts-${width}.png`,fullPage:true});
      await passwordForm.getByRole('button',{name:'Uložit nové heslo'}).click();
      await page.getByText('Formulář přijat').waitFor();
      assert.equal(submitted.get('action'),'user-password'); assert.equal(submitted.get('csrf'),'test-token');
      assert.match(submitted.get('password'),/^[A-Za-z0-9_-]{24}$/);
      assert.equal(submitted.get('revision'),'a'.repeat(64));
      await page.goto(url('create'));
      const form=page.locator('[data-user-profile]');
      await form.getByLabel('Jméno',{exact:true}).fill('Nový správce');
      await form.getByLabel('Přihlašovací e-mail').fill('new@example.test');
      await form.locator('select[name=role]').selectOption('admin');
      await form.getByRole('button',{name:'Vygenerovat heslo'}).click();
      await form.getByRole('button',{name:'Vytvořit účet',exact:true}).click();
      await page.getByText('Formulář přijat').waitFor();
      assert.equal(submitted.get('action'),'user-create'); assert.equal(submitted.get('role'),'admin');
      assert.equal(submitted.get('username'),'','Internal identifier is optional on creation.');
      await page.goto(url('detail'));
      await page.locator('summary').filter({hasText:/^Smazat účet$/}).click();
      const deletion=page.locator('form').filter({has:page.locator('input[value=user-delete]')});
      const checkbox=deletion.getByRole('checkbox');
      assert.equal(await checkbox.getAttribute('required'),'');
      await checkbox.check(); await deletion.getByRole('button',{name:'Smazat účet'}).click();
      await page.getByText('Formulář přijat').waitFor();
      assert.equal(submitted.get('confirm_delete'),'1'); assert.equal(submitted.get('id'),'2');
      assert.deepEqual(errors,[]);
      await page.close();
    }
    const page=await browser.newPage({javaScriptEnabled:false});
    await page.route('https://**/*',route=>route.abort());
    await page.goto(url('detail'));
    assert.equal(await page.getByRole('button',{name:'Vygenerovat heslo'}).count(),0);
    assert.equal(await page.locator('[data-user-password] input[name=password]').isVisible(),true);
    await page.goto(url('uninstalled'));
    assert.equal(await page.locator('#account-management').count(),0);
    assert.equal(await page.getByRole('link',{name:'aktualizuj SQL tabulky'}).count(),1);
    await page.close();
  } finally {
    if (browser) await browser.close(); server.kill();
    files.forEach(file=>{try{unlinkSync(file);}catch{}});
  }
});
