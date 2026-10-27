import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {writeFileSync,unlinkSync,mkdirSync} from 'node:fs';
import {chromium} from 'playwright';
test('partner offer overview: clear coverage, default variant, safe sources and responsive import',async()=>{
 const file='tests/fixtures/partner-offers.html';writeFileSync(file,execFileSync('php',['tests/fixtures/partner-offers.php']));
 const server=spawn('php',['-S','127.0.0.1:8798','-t',process.cwd()],{stdio:'ignore'});let browser;
 try{
  for(let i=0;i<50;i++){try{await fetch('http://127.0.0.1:8798/'+file);break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true});mkdirSync('/tmp/supplier-screenshots',{recursive:true});
  for(const width of [390,1440]){
   const page=await browser.newPage({viewport:{width,height:1050}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.goto('http://127.0.0.1:8798/'+file);
   await page.getByRole('heading',{name:'Pokrytí katalogu',exact:true}).waitFor();
   assert.equal(await page.getByText('Základní varianta',{exact:true}).count(),2);
   assert.equal(await page.getByRole('button',{name:'Připravit náhled přidání'}).count(),1);
   await page.getByText('Formát a pravidla pro AI',{exact:true}).click();
   assert.match(await page.locator('.supplier-json-example').innerText(),/simple-store.partner-sources.v1/);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Overflow ${width}`);
   let ticks=0;await page.route('**/admin/supplier-prices/',async route=>{ticks++;await route.fulfill({contentType:'application/json',body:'{"state":"idle","wait":30}'});});
   await page.getByRole('button',{name:'Zpracovávat v tomto okně',exact:true}).click();
   await page.getByText('Fronta je dokončená.',{exact:false}).waitFor();assert.equal(ticks,1);
   await page.screenshot({path:`/tmp/supplier-screenshots/offers-${width}.png`,fullPage:true});
   assert.deepEqual(errors,[]);await page.close();
  }
 }finally{await browser?.close();server.kill();unlinkSync(file);}
});
