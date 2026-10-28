import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {writeFileSync,unlinkSync,mkdirSync} from 'node:fs';
import {chromium} from 'playwright';
test('blog magazine, centered story and category editor work on desktop and mobile',async()=>{
 const modes=['index','manage','story','edit'];
 for(const mode of modes)writeFileSync(`tests/fixtures/blog-${mode}.html`,execFileSync('php',['tests/fixtures/blog-magazine.php',mode]));
 const server=spawn('php',['-S','127.0.0.1:9884','-t','.'],{stdio:'ignore'});
 let browser;
 try{
  for(let i=0;i<50;i++){try{if((await fetch('http://127.0.0.1:9884/tests/fixtures/blog-index.html')).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  browser=await chromium.launch({headless:true});mkdirSync('/tmp/blog-screenshots',{recursive:true});
  for(const width of [1440,390])for(const mode of modes){
   const page=await browser.newPage({viewport:{width,height:1000}});
   const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.goto(`http://127.0.0.1:9884/tests/fixtures/blog-${mode}.html`);
   assert.equal(await page.locator('h1:visible').count(),1,'Blog should have one visible main heading.');
   if(mode==='index'||mode==='manage'){
    const geometry=await page.locator('.blog-card').evaluateAll(cards=>cards.map(c=>({w:c.getBoundingClientRect().width,y:c.getBoundingClientRect().y})));
    assert.equal(geometry.length,6);
    assert.equal(await page.locator('.blog-placement-controls').count(),mode==='manage'?6:0);
    if(mode==='manage'){
     assert.equal(await page.getByRole('button',{name:'Zrušit první místo',exact:true}).getAttribute('aria-pressed'),'true');
     assert.equal(await page.getByRole('button',{name:'Odepnout',exact:true}).getAttribute('aria-pressed'),'true');
     assert.equal(await page.locator('.blog-placement-controls input[name=expected]').first().getAttribute('value').then(v=>v.length),64);
     assert.ok(await page.getByRole('link',{name:'Upravit úvod blogu',exact:true}).getAttribute('href').then(v=>v.includes('tab=appearance')));
    }
    if(width===1440){assert.ok(geometry[0].w>geometry[1].w*1.9);assert.equal(geometry[0].y,geometry[1].y);}
    else assert.ok(Math.abs(geometry[0].w-geometry[1].w)<2);
    assert.ok(await page.getByRole('link',{name:'Spaní venku 4',exact:true}).getAttribute('href').then(h=>h.includes('topic=spani-venku')));
    await page.getByRole('link',{name:'Všechny příběhy 6',exact:true}).waitFor();
   }else{
    const pos=await page.locator('.cms-article').evaluate(el=>{const r=el.getBoundingClientRect();return {left:r.left,right:innerWidth-r.right};});
    assert.ok(Math.abs(pos.left-pos.right)<2,`Article off-center ${JSON.stringify(pos)}`);
    if(mode==='story'){
     assert.equal(await page.locator('.blog-toc').evaluate(el=>el.open),true);
     await page.locator('.blog-toc nav').waitFor({state:'visible'});
     const links=await page.locator('.blog-toc a').count();assert.ok(links>=3);
     assert.equal(await page.locator('.blog-toc a').evaluateAll(links=>links.every(a=>document.querySelector(a.getAttribute('href')))),true);
    }else{
     assert.equal(await page.getByLabel('Kategorie blogu',{exact:true}).getAttribute('data-content-select'),'topic');
     const requestPromise=page.waitForRequest(r=>r.url().endsWith('/admin/')&&r.method()==='POST');
     await page.route('**/admin/',route=>route.fulfill({status:422,contentType:'application/json',body:JSON.stringify({error:'TEST: request captured'})}));
     await page.getByLabel('Kategorie blogu',{exact:true}).selectOption('cestopisy');
     const request=await requestPromise;const body=new URLSearchParams(request.postData());
     assert.equal(body.get('field'),'topic');assert.equal(body.get('value'),'cestopisy');assert.equal(body.get('revision'),'28');
    }
   }
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Overflow ${mode} ${width}`);
   await page.locator('main img').evaluateAll(images=>Promise.all(images.map(image=>{image.loading='eager';return image.decode();})));
   assert.deepEqual(errors,[]);
   await page.screenshot({path:`/tmp/blog-screenshots/${mode}-${width}.png`,fullPage:true});await page.close();
  }
 }finally{await browser?.close();server.kill();for(const mode of modes)unlinkSync(`tests/fixtures/blog-${mode}.html`);}
});
