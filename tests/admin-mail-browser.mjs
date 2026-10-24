import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { chromium } from 'playwright';

test('customer emails and editable previews on desktop and mobile', async () => {
  const fixtures = [['order','own'],['order','mixed'],['paid','assisted'],['invoice','mixed'],
    ['case_receipt','own'],['case_event','own'],['password_reset','own'],['late_paid','mixed']];
  const files = [];
  let server, browser;
  const write = (name,args) => { const file=`tests/fixtures/mail-${name}.html`; files.push(file);writeFileSync(file,execFileSync('php',['tests/fixtures/admin-mail.php',...args])); };
  const url = name => `http://127.0.0.1:8791/tests/fixtures/mail-${name}.html`;
  try {
    write('settings',['order','mixed']);
    fixtures.forEach(([code,mode]) => write(`${code}-${mode}`,[code,mode,'email']));
    server=spawn('php',['-S','127.0.0.1:8791','-t',process.cwd()],{stdio:'ignore'});
    for(let i=0;i<50;i++) {try {await fetch(url('settings'), {method:'HEAD'});break;}catch {await new Promise(r=>setTimeout(r,100));}}
    browser=await chromium.launch({headless:true});
    mkdirSync('/tmp/admin-mail-screenshots',{recursive:true});
    for(const width of [320,390,768,1440]) {
      const page=await browser.newPage({viewport:{width,height:950}});
      const errors=[];page.on('pageerror',e=>errors.push(e.message));
      page.on('dialog',dialog=>dialog.accept());
      await page.route('https://**/*',route=>route.abort());
      for(const [code,mode] of fixtures) {
        await page.goto(url(`${code}-${mode}`));
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,`${code}/${mode} overflow at ${width}`);
        assert.ok(await page.locator('h1').isVisible());
        assert.ok(await page.getByText('dobrodruzi.cz',{exact:true}).first().isVisible());
        if(width===390 || width===1440) await page.screenshot({fullPage:true,path:`/tmp/admin-mail-screenshots/${code}-${mode}-${width}.png`});
      }
      await page.goto(url('settings'));
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,`Settings overflow at ${width}`);
      assert.equal(await page.locator('input[name="smtp_password"]').inputValue(),'');
      assert.equal(await page.locator('input[name="templates[invoice][enabled]"]').count(),0,'Required transactional messages are not disabled with order stages.');
      assert.equal(await page.locator('input[name="copy[case_status_resolved]"]').count(),1);
      await page.getByRole('button',{name:'Mobil',exact:true}).click();
      const preview=await page.locator('.mail-preview-stage iframe').boundingBox();
      assert.ok(preview.width<=390 && preview.width<=width);
      const emailFrame=page.frameLocator('iframe[title="Náhled e-mailu"]');
      await emailFrame.getByText('Dodání od partnera',{exact:false}).first().waitFor();
      if(width===390 || width===1440) { await page.evaluate(()=>scrollTo({top:0,behavior:'instant'})); await page.screenshot({path:`/tmp/admin-mail-screenshots/settings-${width}.png`}); }
      const search=page.locator('[data-mail-search]');
      await search.fill('bezpecnost');
      assert.equal(await page.locator('[data-mail-group]:visible').count(),1);
      await search.fill('');
      assert.ok(await page.locator('[data-mail-group]:visible').count()>10);
      const order=page.locator('[data-mail-group]').filter({has:page.locator('input[name="templates[order][subject]"]')});
      await order.locator('textarea').fill('Rozepsaný úvod');
      await order.getByRole('button',{name:'Obnovit výchozí texty',exact:true}).click();
      assert.match(await order.locator('textarea').inputValue(),/děkujeme za objednávku/);
      assert.match(await page.locator('[data-mail-state]').textContent(),/Pro použití je uložte/);
      const signature=page.locator('[data-mail-group]').filter({has:page.locator('[name="copy[footer]"]')});
      await signature.locator('summary').click();
      await signature.locator('[name="copy[footer]"]').fill('Napište nám <b>kdykoli</b>.');
      await page.locator('[name="preview_code"]').selectOption('invoice');
      await page.locator('[name="preview_mode"]').selectOption('assisted');
      let post;
      await page.route('**/admin/settings/**',async route=>{
        post=new URLSearchParams(route.request().postData());
        await route.fulfill({body:'<p>Náhled zachycen</p>',contentType:'text/html; charset=utf-8'});
      });
      await page.getByRole('button',{name:'Zobrazit náhled',exact:true}).click();
      await page.getByText('Náhled zachycen').waitFor();
      assert.equal(post.get('action'),'preview-mail');assert.equal(post.get('csrf'),'test-token');
      assert.equal(post.get('copy[footer]'),'Napište nám <b>kdykoli</b>.');
      assert.equal(post.get('preview_code'),'invoice');assert.equal(post.get('preview_mode'),'assisted');
      assert.deepEqual(errors,[]);
      await page.close();
    }
  } finally {if(browser) await browser.close();server?.kill();files.forEach(f=>{try{unlinkSync(f);}catch{}});}
});
