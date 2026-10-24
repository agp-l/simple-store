import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {mkdirSync,writeFileSync,unlinkSync,readFileSync} from 'node:fs';
import {chromium} from 'playwright';

test('product folders preserve context, preview photos and add to the gallery on desktop and mobile', async () => {
  const cases=['product-folders','product-photos','product-open','product-search','product-empty','page-folders','post-folders'];
  const paths=[];let browser,server;
  const url=name=>`http://127.0.0.1:8797/tests/fixtures/folders-${name}.html`;
  const html=name=>readFileSync(`tests/fixtures/folders-${name}.html`,'utf8');
  try {
    for(const name of cases){const path=`tests/fixtures/folders-${name}.html`;paths.push(path);writeFileSync(path,execFileSync('php',['tests/fixtures/admin-media.php',name]));}
    server=spawn('php',['-S','127.0.0.1:8797','-t',process.cwd()],{stdio:'ignore'});
    for(let i=0;i<50;i++){try{await fetch(url(cases[0]));break;}catch{await new Promise(r=>setTimeout(r,100));}}
    browser=await chromium.launch();mkdirSync('/tmp/media-folders-screenshots',{recursive:true});
    for(const width of [320,390,768,1100,1440,1920]) {
      const page=await browser.newPage({viewport:{width,height:950}});const errors=[];
      page.on('pageerror',error=>errors.push(error.message));await page.route('https://**/*',route=>route.abort());
      await page.addInitScript(()=>Object.defineProperty(navigator,'clipboard',{value:{writeText:async value=>{window.copiedMedia=value;}}}));
      for(const name of cases){
        await page.goto(url(name));
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,`${name} overflow ${width}`);
        if([390,1440].includes(width)&&['product-folders','product-open'].includes(name))await page.screenshot({path:`/tmp/media-folders-screenshots/${name}-${width}.png`,fullPage:true});
      }
      await page.goto(url('product-folders'));
      assert.equal(await page.locator('.library-directory').count(),3);
      assert.equal(await page.locator('.library-card').count(),0);
      assert.equal(await page.locator('.library-view-switch [aria-current]').innerText(),'Složky');
      assert.match(await page.locator('.library-directory').last().innerText(),/Produkt bez aktuálního záznamu/);
      await page.locator('input[name="owners[]"]').first().check();
      assert.equal(await page.locator('[data-library-selection-count]').innerText(),'Složky: 1 · fotografie: 2');
      await page.getByRole('button',{name:'Zrušit výběr',exact:true}).click();assert.equal(await page.locator('.library-directory.is-selected').count(),0);
      await page.locator('input[name="owners[]"]').first().check();
      let submitted;
      await page.route(/\/admin(?:\.php|\/)/,async route=>{
        if(route.request().method()==='POST'){
          submitted=new URLSearchParams(route.request().postData());
          await route.fulfill({contentType:'text/html; charset=utf-8',body:'<p>Saved collection</p>'});return;
        }
        const query=new URL(route.request().url()).searchParams;
        const fixture=query.get('owner')?'product-open':query.get('view')==='photos'?'product-photos':'product-folders';
        await route.fulfill({contentType:'text/html; charset=utf-8',body:html(fixture)});
      });
      await page.getByRole('button',{name:'Export vybraných',exact:true}).click();await page.getByText('Saved collection').waitFor();
      assert.deepEqual(submitted.getAll('owners[]'),['products:'+'1'.repeat(32)]);
      assert.equal(submitted.get('action'),'media-library-export');
      await page.goto(url('product-folders'));
      await page.locator('.library-directory-open').first().click();
      assert.ok(new URL(page.url()).searchParams.get('owner'));
      assert.equal(await page.locator('.library-card').count(),2);
      assert.match(await page.locator('.library-breadcrumbs').innerText(),/Batoh na víkendovou výpravu/);
      await page.locator('[data-library-preview]').first().click();
      const preview=page.locator('[data-library-preview-dialog]');assert.equal(await preview.isVisible(),true);
      assert.equal(await preview.locator('[data-library-preview-position]').innerText(),'1 / 2');
      await preview.getByRole('button',{name:'Další →',exact:true}).click();
      assert.equal(await preview.locator('[data-library-preview-position]').innerText(),'2 / 2');
      await preview.getByRole('button',{name:'Kopírovat adresu',exact:true}).click();
      assert.match(await page.evaluate(()=>window.copiedMedia),new RegExp('/products/'+'1'.repeat(32)+'/'));
      await page.keyboard.press('ArrowLeft');assert.equal(await preview.locator('[data-library-preview-position]').innerText(),'1 / 2');
      await page.keyboard.press('Escape');assert.equal(await preview.isVisible(),false);
      await page.getByRole('link',{name:'← Zpět na složky',exact:true}).click();
      assert.equal(await page.locator('.library-directory').count(),3);
      await page.getByRole('link',{name:'Obrázky',exact:true}).click();assert.equal(await page.locator('.library-card').count(),6);
      await page.unroute(/\/admin(?:\.php|\/)/);
      await page.goto(url('product-search'));assert.equal(await page.locator('.library-directory').count(),1);
      assert.match(await page.locator('.library-directory-open').getAttribute('href'),/parent_q=STAN/);
      await page.goto(url('product-empty'));assert.equal(await page.locator('.library-card').count(),0);assert.equal(await page.locator('.library-owner-header').count(),1);
      await page.goto(url('product-open'));
      let mode;
      await page.route(/\/admin(?:\.php|\/)/,async route=>{
        if(route.request().method()==='POST'){
          const body=route.request().postData();mode=body.match(/name="mode"\r\n\r\n([^\r]+)/)?.[1];
          await route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({error:'Fixture: gallery mode checked'})});return;
        }
        await route.fulfill({contentType:'application/json',body:JSON.stringify({files:[{path:'images/batoh.webp',thumb:'images/batoh.webp',card:'images/batoh.webp',label:'Batoh',uses:[]}]})});
      });
      await page.getByRole('button',{name:'Přidat fotografie',exact:true}).click();
      await page.getByRole('button',{name:'Přidat do galerie',exact:true}).click();
      await page.getByText('Fixture: gallery mode checked',{exact:true}).waitFor();assert.equal(mode,'gallery-add');
      await page.locator('[data-media-close]').click();
      await page.getByRole('link',{name:'Nahrát',exact:true}).click();assert.equal(await page.locator('#library-upload').getAttribute('open'),'');
      assert.deepEqual(errors,[]);await page.close();
    }
  }finally{await browser?.close();server?.kill();for(const path of paths)try{unlinkSync(path);}catch{}}
});
