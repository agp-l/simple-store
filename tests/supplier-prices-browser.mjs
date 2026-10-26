import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {writeFileSync,unlinkSync,mkdirSync} from 'node:fs';
import {chromium} from 'playwright';

test('supplier manager responsive controls, safe review and single-step runner',async()=>{
 const file='tests/fixtures/supplier-prices.html';writeFileSync(file,execFileSync('php',['tests/fixtures/supplier-prices.php']));
 const server=spawn('php',['-S','127.0.0.1:8797','-t',process.cwd()],{stdio:'ignore'});let browser;
 try{
  for(let i=0;i<50;i++){try{await fetch('http://127.0.0.1:8797/'+file);break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({headless:true});mkdirSync('/tmp/supplier-screenshots',{recursive:true});
  for(const width of [390,768,1440]){
   const page=await browser.newPage({viewport:{width,height:1050}});const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.route('https://**/*',r=>r.abort());
   await page.goto('http://127.0.0.1:8797/'+file);
   await page.getByRole('heading',{name:'Ceny dodavatelů',exact:true}).waitFor();
   assert.equal(await page.locator('#supplier-product-field').isVisible(),false);
   await page.locator('#supplier-scope').selectOption('one');assert.equal(await page.locator('#supplier-product-field').isVisible(),true);
   assert.ok(await page.locator('[name=product_key]').evaluate(el=>el.required));
   assert.match(await page.locator('.supplier-result-title a').first().getAttribute('href'),/\/cs\/produkt\/spacak\?edit=1/);
   assert.equal(await page.getByRole('button',{name:'Použít cenu i prodávajícího',exact:true}).count(),1);
   assert.match(await page.locator('#price-job-3 .supplier-current-note').innerText(),/Partner nebo odkazy/);
   assert.equal(await page.locator('#price-job-3 .supplier-current-sources a').getAttribute('href'),'https://www.4camping.cz/p/test/#oranzova');
   assert.equal(await page.locator('#price-job-3 [name=fresh]').count(),0,'New source offered a forced re-fetch of the old seller');
   await page.locator('#price-job-3 summary').click();
   assert.equal(await page.getByRole('link',{name:'Odkaz použitý při tomto historickém pokusu ↗'}).getAttribute('href'),'https://www.alza.cz/old.htm');

   assert.match(await page.getByRole('link',{name:'Obnovit výsledky',exact:true}).getAttribute('href'), /\?refresh=[a-f0-9]{12}#price-history$/,'Refresh must issue a new GET, not just navigate to an anchor');
   await page.locator('#supplier-scope').selectOption('problems');
   assert.equal(await page.locator('#supplier-retry-field').isVisible(),true);
   await page.locator('#supplier-scope').selectOption('one');
   assert.equal(await page.locator('#supplier-retry-field input').isEnabled(),false);
   assert.match(await page.locator('[data-pricing-example="1210"] strong').innerText(),/1.400/);
   await page.locator('[name=pricing_margin]').fill('200');
   assert.match(await page.locator('[data-pricing-example="1210"] strong').innerText(),/1.500/);
   assert.equal(await page.getByText('Cena platí pro varianty používající základní dodavatelskou cenu.',{exact:false}).count(),1);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Overflow at ${width}`);
   await page.screenshot({path:`/tmp/supplier-screenshots/manager-${width}.png`,fullPage:true});
   let ticks=0, delayNext=false, keepWorking=false, release;
   await page.route('**/admin/supplier-prices/',async route=>{
    const post=new URLSearchParams(route.request().postData());assert.equal(post.get('action'),'supplier-price-tick');assert.equal(post.get('csrf'),'test-token');ticks++;
    if(delayNext){delayNext=false;await new Promise(resolve=>{release=async()=>{await route.fulfill({contentType:'application/json',body:JSON.stringify({state:'idle',wait:30})});resolve();};});}
    else await route.fulfill({contentType:'application/json',body:JSON.stringify({state:keepWorking?'working':'idle',wait:60})});
   });
   await page.getByRole('button',{name:'Zpracovávat v tomto okně',exact:true}).click();
   await page.getByText('Fronta je dokončená.',{exact:false}).waitFor();assert.equal(ticks,1);
   assert.equal(await page.getByRole('button',{name:'Přerušit v tomto okně',exact:true}).isDisabled(),true);
   if(width===1440){
    delayNext=true;keepWorking=true;
    await page.getByRole('button',{name:'Zpracovávat v tomto okně',exact:true}).click();
    for(let i=0;i<100&&!release;i++)await new Promise(r=>setTimeout(r,10));assert.ok(release);
    await page.getByRole('button',{name:'Přerušit v tomto okně',exact:true}).click();
    await page.getByRole('button',{name:'Zpracovávat v tomto okně',exact:true}).click();
    await page.getByText('Zpracování běží',{exact:false}).waitFor();await release();
    await new Promise(r=>setTimeout(r,100));
    assert.match(await page.locator('#supplier-run-status').innerText(),/Zpracování běží/,'Old response stopped a newer runner');
    await page.getByRole('button',{name:'Přerušit v tomto okně',exact:true}).click();
   }
   assert.deepEqual(errors,[]);await page.close();
  }
 }finally{await browser?.close();server.kill();unlinkSync(file);}
});
