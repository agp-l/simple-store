import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {mkdirSync,writeFileSync,unlinkSync} from 'node:fs';
import {chromium} from 'playwright';

test('media collections, article previews and selection on desktop and mobile', async () => {
  const files=[]; let server,browser;
  const url=name=>`http://127.0.0.1:8796/tests/fixtures/media-${name}.html`;
  try {
    for(const name of ['media','pages','posts','cover']) { const path=`tests/fixtures/media-${name}.html`;files.push(path);writeFileSync(path,execFileSync('php',['tests/fixtures/admin-media.php',name])); }
    server=spawn('php',['-S','127.0.0.1:8796','-t',process.cwd()],{stdio:'ignore'});
    for(let i=0;i<50;i++){try{await fetch(url('media'));break;}catch{await new Promise(r=>setTimeout(r,100));}}
    browser=await chromium.launch();mkdirSync('/tmp/admin-media-screenshots',{recursive:true});
    for(const width of [320,390,768,1100,1440,1920]) {
      const page=await browser.newPage({viewport:{width,height:950}});const errors=[];page.on('pageerror',error=>errors.push(error.message));
      await page.route('https://**/*',route=>route.abort());
      await page.addInitScript(()=>Object.defineProperty(navigator,'clipboard',{value:{writeText:async value=>{window.copiedMedia=value;}}}));
      for(const name of ['media','pages','posts','cover']) {
        await page.goto(url(name));
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,`${name} overflow ${width}`);
        if([390,1440].includes(width))await page.screenshot({path:`/tmp/admin-media-screenshots/${name}-${width}.png`,fullPage:true});
      }
      await page.goto(url('media'));
      assert.equal(await page.locator('[data-library-requires-selection]').first().isDisabled(),true);
      await page.locator('[data-library-select-all]').check();
      assert.equal(await page.locator('.library-card.is-selected').count(),6);
      await page.locator('.library-copy-primary').first().click();
      assert.equal(await page.evaluate(()=>window.copiedMedia),'http://127.0.0.1:8796/images/batoh.webp');
      await page.locator('#media-selection select').selectOption('b'.repeat(32));
      let submitted;
      await page.route('**/admin/media/**',async route=>{submitted=new URLSearchParams(route.request().postData());await route.fulfill({contentType:'text/html; charset=utf-8',body:'<p>Saved fixture</p>'});});
      await page.getByRole('button',{name:'Zařadit do složky',exact:true}).click();await page.getByText('Saved fixture',{exact:true}).waitFor();
      assert.equal(submitted.get('folder'),'b'.repeat(32));assert.equal(submitted.getAll('paths[]').length,6);
      await page.unroute('**/admin/media/**');
      await page.goto(url('posts'));
      assert.equal(await page.locator('.content-type-tabs [aria-current]').innerText(),'Články');
      assert.ok((await page.locator('.panel-document-actions a').allTextContents()).some(label=>label.includes('Fotografie')));
      await page.goto(url('cover'));
      const requests=[];
      await page.route('**/admin/**',async route=>{
        requests.push(route.request().url());
        if(route.request().method()==='POST'){const body=route.request().postData();assert.match(body,/cover-image/);await route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({error:'Fixture: změna se neukládá'})});return;}
        await route.fulfill({contentType:'application/json',body:JSON.stringify({files:[{path:'images/batoh.webp',thumb:'images/batoh.webp',card:'images/batoh.webp',label:'Batoh',group:'library'}],total:1})});
      });
      await page.getByRole('button',{name:'Vybrat nebo nahrát obrázek'}).click();
      assert.equal(await page.locator('#media-upload-form input').getAttribute('multiple'),null);
      await page.locator('#media-library-filter [name="scope"]').selectOption('all');
      await page.getByRole('button',{name:'Použít jako náhled',exact:true}).waitFor();
      assert.ok(requests.some(request=>request.includes('api=library')));
      await page.getByRole('button',{name:'Použít jako náhled',exact:true}).click();
      await page.getByText('Fixture: změna se neukládá',{exact:true}).waitFor();
      assert.deepEqual(errors,[]);await page.close();
    }
  } finally {await browser?.close();server?.kill();for(const path of files)try{unlinkSync(path);}catch{}}
});
