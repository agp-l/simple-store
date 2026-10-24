import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { chromium } from 'playwright';

test('OSVC evidence: usable tables, retained drafts, exact amounts and safe form controls', async () => {
  const modes = ['money','draft','edit','funds','balances','closing'];
  const files = modes.map(mode => `tests/fixtures/tax-${mode}.html`);
  modes.forEach((mode,i) => writeFileSync(files[i], execFileSync('php',['tests/fixtures/tax-evidence.php',mode,'shell'])));
  const server=spawn('php',['-S','127.0.0.1:8784','-t',process.cwd()],{stdio:'ignore'});
  const url=mode=>`http://127.0.0.1:8784/tests/fixtures/tax-${mode}.html`;
  let browser;
  try {
    for(let i=0;i<40;i++){try{await fetch(url('money'));break;}catch{await new Promise(r=>setTimeout(r,100));}}
    browser=await chromium.launch({headless:true});
    mkdirSync('/tmp/tax-evidence-screenshots',{recursive:true});
    for(const width of [1440,768,390,320]) {
      const page=await browser.newPage({viewport:{width,height:1000}});
      const errors=[];page.on('pageerror',e=>errors.push(String(e)));
      for(const mode of modes) {
        await page.goto(url(mode));
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Page overflows in ${mode} ${width}`);
        if(mode==='money') {
          await page.getByText('+ Přidat peněžní pohyb',{exact:true}).click();
          await page.locator('[data-ledger-form] select[name=purpose]').selectOption('principal_return');
          assert.equal(await page.locator('[data-ledger-form] select[name=tax_kind]').inputValue(),'nondeductible');
          assert.equal(await page.locator('[data-ledger-form] select[name=direction]').inputValue(),'expense');
          assert.match(await page.locator('table').textContent(),/122,15 Kč/);
        }
        if(mode==='draft') {
          assert.equal(await page.locator('input[name=description]').inputValue(),'Neztratit rozpracované údaje');
          assert.equal(await page.locator('[data-ledger-form] input[name=amount_czk]').inputValue(),'chyba');
          assert.equal(await page.locator('[data-ledger-form] input[name=reference]').inputValue(),'REF-NEZTRATIT');
        }
        if(mode==='edit') assert.equal(await page.locator('[data-ledger-form] input[name=version]').inputValue(),'2');
        if(mode==='funds') {
          await page.getByText('+ Zapsat platbu nebo vratku',{exact:true}).click();
          await page.getByRole('button',{name:'Vyplnit celou cenu objednávky'}).click();
          assert.equal(await page.locator('input[name=own_czk]').inputValue(),'122');
          assert.equal(await page.locator('input[name=principal_czk]').inputValue(),'1678');
          await page.locator('select[name=movement]').selectOption('refund');
          assert.equal(await page.locator('input[name=own_czk]').inputValue(),'0');
          assert.equal(await page.locator('input[name=principal_czk]').inputValue(),'0');
        }
        if(mode==='closing') assert.match(await page.locator('table').textContent(),/-50,00 Kč/);
        await page.screenshot({path:`/tmp/tax-evidence-screenshots/${mode}-${width}.png`,fullPage:true});
      }
      assert.deepEqual(errors,[]);
      await page.close();
    }
    const page=await browser.newPage({javaScriptEnabled:false});
    await page.goto(url('draft'));
    assert.equal(await page.locator('input[name=description]').inputValue(),'Neztratit rozpracované údaje');
    assert.equal(await page.locator('[data-ledger-form] input[name=year]').inputValue(),'2026');
    await page.close();
  } finally {if(browser)await browser.close();server.kill();files.forEach(file=>unlinkSync(file));}
});
