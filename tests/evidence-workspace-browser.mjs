import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {mkdirSync,writeFileSync,unlinkSync} from 'node:fs';
import {chromium} from 'playwright';
test('Wide evidence table, retained document draft, number settings and guarded restart',async()=>{
 const modes=['overview','documents','document-draft','numbering','restart','invoice-edit','guide','settings','invoices'];
 const files=modes.map(m=>`tests/fixtures/workspace-${m}.html`);
 modes.forEach((m,i)=>writeFileSync(files[i],execFileSync('php',['tests/fixtures/evidence-workspace.php',m, ...(m==='invoice-edit'?[]:['shell'])])));
 const server=spawn('php',['-S','127.0.0.1:8796','-t',process.cwd()],{stdio:'ignore'});let browser;
 try{
  const url=m=>`http://127.0.0.1:8796/tests/fixtures/workspace-${m}.html`;
  for(let i=0;i<40;i++){try{await fetch(url('overview'));break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true});mkdirSync('/tmp/evidence-workspace-screenshots',{recursive:true});
  for(const width of [1720,1440,768,390,320]){
   const page=await browser.newPage({viewport:{width,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(String(e)));
   for(const mode of modes){await page.goto(url(mode));assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Overflow ${mode}/${width}`);
    if(mode==='overview'){
     assert.equal(await page.locator('table thead th').count(),6);
     assert.equal(await page.getByRole('link',{name:/Fio-vypis-2026-10.pdf/}).count(),5);
     assert.ok(await page.locator('.evidence-journal-table').evaluate(e=>e.getBoundingClientRect().width>=1060),'Table is cramped');
    }
    if(mode==='document-draft'){
     assert.equal(await page.locator('input[name=description]').inputValue(),'Neztratit fakturu <boty>');
     assert.equal(await page.locator('input[name=amount_czk]').inputValue(),'chyba');
     assert.equal(await page.locator('input[name=external_number]').inputValue(),'REF-NEZTRATIT');
     assert.deepEqual(await page.locator('select[name="entry_ids[]"]').evaluate(e=>[...e.selectedOptions].map(o=>o.value)),['1']);
    }
    if(mode==='numbering') {
      assert.equal(await page.locator('input[name=pattern]').first().inputValue(),'ESH-{YYYY}-{SEQ:4}');
      const form=page.locator('#series-new form');
      await form.locator('select[name=kind]').selectOption('invoice');
      await form.getByRole('button',{name:'E-shop',exact:true}).click();
      assert.equal(await form.locator('input[name=pattern]').inputValue(),'ESH-F-{YYYY}-{SEQ:4}');
      assert.equal(await form.locator('[data-number-preview]').textContent(),'ESH-F-2026-0001');
      await form.locator('input[name=next_number]').evaluate(el=>el.closest('details').open=true);
      await form.locator('input[name=next_number]').fill('150');
      assert.equal(await form.locator('[data-number-preview]').textContent(),'ESH-F-2026-0150');
      await form.locator('[data-number-year]').selectOption('');
      assert.equal(await form.locator('[data-number-preview]').textContent(),'ESH-F-0150');
      await form.locator('input[name=pattern]').fill('MAS/{YY}/{SEQ:2}');
      assert.equal(await form.locator('[data-number-preview]').textContent(),'MAS/26/150');
      await form.locator('input[name=pattern]').fill('<script>');
      assert.match(await form.locator('[data-number-preview]').textContent(),/Zkontroluj/);
      await form.locator('[data-number-prefix]').fill('ESH');
      await form.locator('[data-number-year]').selectOption('YYYY');
      await form.locator('[data-number-digits]').selectOption('4');
      await form.locator('input[name=name]').fill('Faktury e-shopu');
      let submitted;
      await page.route('**/admin/accounting/**', async route=>{
        submitted=new URLSearchParams(route.request().postData());
        await route.fulfill({body:'<p>Uloženo ve fixture</p>',contentType:'text/html; charset=utf-8'});
      });
      await form.getByRole('button',{name:'Vytvořit řadu'}).click();
      await page.getByText('Uloženo ve fixture').waitFor();
      assert.equal(submitted.get('pattern'),'ESH-{YYYY}-{SEQ:4}');
      assert.equal(submitted.get('next_number'),'150');
      assert.equal(submitted.get('csrf'),'test-csrf');
      await page.unroute('**/admin/accounting/**');
      await page.goto(url(mode));
    }
    if(mode==='restart'){
     assert.equal(await page.locator('input[name=password]').count(),1);
     await page.getByText('Trvale smazat celou obchodní historii',{exact:true}).click();
     assert.equal(await page.locator('main form').evaluate(f=>f.checkValidity()),false);
    }
    if(mode==='invoice-edit'){
     await page.getByText('Upravit údaje a obsah vystavené faktury',{exact:true}).click();
     await page.getByRole('button',{name:'Přidat položku'}).click();assert.equal(await page.locator('.evidence-invoice-item').count(),2);
     await page.locator('[data-remove-invoice-item]').last().click();assert.equal(await page.locator('.evidence-invoice-item').count(),1);
    }
    await page.screenshot({path:`/tmp/evidence-workspace-screenshots/${mode}-${width}.png`,fullPage:true});
   }assert.deepEqual(errors,[]);await page.close();
  }
  const plain=await browser.newPage({javaScriptEnabled:false,viewport:{width:390,height:900}});
  await plain.goto(url('numbering'));
  assert.equal(await plain.locator('#series-new input[name=pattern]').isVisible(),true);
  assert.equal(await plain.locator('#series-new [data-number-builder]').isVisible(),false);
  await plain.goto(url('document-draft'));
  assert.equal(await plain.locator('input[name=description]').inputValue(),'Neztratit fakturu <boty>');
  await plain.close();
 }finally{if(browser)await browser.close();server.kill();files.forEach(f=>unlinkSync(f));}
});
