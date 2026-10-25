import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {writeFileSync,unlinkSync} from 'node:fs';
import {chromium} from 'playwright';
test('family preview shows exact combinations and requires an explicit selection on mobile and desktop',async()=>{
 const file='tests/fixtures/product-families.html';writeFileSync(file,execFileSync('php',['tests/fixtures/product-families.php']));
 const server=spawn('php',['-S','127.0.0.1:8798','-t',process.cwd()],{stdio:'ignore'});let browser;
 try{
  for(let i=0;i<50;i++){try{await fetch('http://127.0.0.1:8798/'+file);break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true});
  for(const width of [390,768,1440]){
   const page=await browser.newPage({viewport:{width,height:1050}});await page.route('https://**/*',r=>r.abort());await page.goto('http://127.0.0.1:8798/'+file);
   assert.equal(await page.getByRole('link',{name:'Sjednotit produkty',exact:true}).getAttribute('aria-current'),'page');
   assert.equal(await page.locator('input[name="families[]"]:checked').count(),0);
   assert.equal(await page.locator('tbody tr').count(),2);
   assert.ok(await page.locator('input[name="confirm"]').evaluate(el=>el.required));
   assert.match(await page.locator('table').innerText(),/170 cm/);assert.match(await page.locator('table').innerText(),/195 cm/);
   assert.equal(await page.getByRole('button',{name:'Vrátit sloučení #1',exact:true}).count(),1);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Family UI overflows at ${width}`);
   await page.close();
  }
 }finally{await browser?.close();server.kill();unlinkSync(file);}
});
