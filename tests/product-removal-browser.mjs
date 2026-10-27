import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {writeFileSync,unlinkSync,mkdirSync} from 'node:fs';
import {chromium} from 'playwright';
test('product removal preview and retry are clear and usable on mobile and desktop',async()=>{
 const modes=['preview','default','pending','success'];
 for(const mode of modes) writeFileSync(`tests/fixtures/removal-${mode}.html`,execFileSync('php',['tests/fixtures/product-removal.php',mode]));
 const server=spawn('php',['-S','127.0.0.1:8799','-t',process.cwd()],{stdio:'ignore'});let browser;
 try {
  for(let i=0;i<50;i++){try{await fetch('http://127.0.0.1:8799/tests/fixtures/removal-preview.html');break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true});mkdirSync('/tmp/product-removal-screenshots',{recursive:true});
  for(const width of [390,1440]) for(const mode of modes){
   const page=await browser.newPage({viewport:{width,height:1050}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.goto(`http://127.0.0.1:8799/tests/fixtures/removal-${mode}.html`);
   if(mode==='preview'||mode==='default'){
    await page.getByRole('heading',{name:'Zkontroluj smazání produktu',exact:true}).waitFor();
    const form=page.locator('form:has(input[name="preview_id"])');
    assert.equal(await form.evaluate(el=>el.checkValidity()),false);
    await form.getByRole('checkbox').check();assert.equal(await form.evaluate(el=>el.checkValidity()),true);
    assert.equal(await form.locator('input[name="csrf"]').inputValue(),'test-token');
    assert.equal(await page.getByRole('heading',{name:'Odstraní se',exact:true}).count(),1);
    assert.equal(await page.getByRole('heading',{name:'Zůstane zachované',exact:true}).count(),1);
    const columns=await page.locator('.product-removal-grid').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length);
    assert.equal(columns,width===390?1:2);
   }else{
    await page.getByRole('heading',{name:'Produkt je odstraněný z nabídky',exact:true}).waitFor();
    assert.equal(await page.getByRole('button',{name:'Dokončit úklid fotografií',exact:true}).count(),mode==='pending'?2:0);
   }
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Overflow ${mode} ${width}`);
   await page.screenshot({path:`/tmp/product-removal-screenshots/${mode}-${width}.png`,fullPage:true});
   assert.deepEqual(errors,[]);await page.close();
  }
 }finally{await browser?.close();server.kill();for(const mode of modes)unlinkSync(`tests/fixtures/removal-${mode}.html`);}
});
