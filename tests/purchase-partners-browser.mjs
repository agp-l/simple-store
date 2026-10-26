import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {mkdirSync,writeFileSync,unlinkSync} from 'node:fs';
import {chromium} from 'playwright';

test('seller website, portable JSON preview and responsive directory',async()=>{
  const files=['list','preview','error','delete'].map(name=>`tests/fixtures/partners-${name}.html`);
  for(let i=0;i<files.length;i++)writeFileSync(files[i],execFileSync('php',['tests/fixtures/purchase-partners.php',['list','preview','error','delete'][i]]));
  const server=spawn('php',['-S','127.0.0.1:8796','-t',process.cwd()],{stdio:'ignore'});
  const url=file=>'http://127.0.0.1:8796/'+file;
  let browser;
  try{
    for(let i=0;i<50;i++){try{await fetch(url(files[0]));break;}catch{await new Promise(r=>setTimeout(r,100));}}
    browser=await chromium.launch({headless:true});mkdirSync('/tmp/partner-screenshots',{recursive:true});
    for(const width of [390,768,1440]){
      const page=await browser.newPage({viewport:{width,height:1050}});const errors=[];
      page.on('pageerror',e=>errors.push(e.message));await page.route('https://**/*',r=>r.abort());
      await page.goto(url(files[0]));
      assert.equal(await page.getByLabel('Web prodávajícího',{exact:false}).inputValue(),'https://www.pod7kilo.cz/');
      assert.equal(await page.locator('.partner-website').count(),3);
      assert.match(await page.getByRole('link',{name:'Exportovat prodávající do JSON'}).getAttribute('href'),/backup=export/);
      assert.equal(await page.locator('#partner-form > form').getAttribute('method'),'post');
      assert.equal(await page.getByRole('button',{name:'Smazat prodávajícího',exact:true}).isDisabled(),true);
      assert.match(await page.getByRole('link',{name:'Zobrazit (3)',exact:true}).getAttribute('href'),/manage=1&partner_id=1/);
      await page.goto(url(files[1]));
      assert.match(await page.locator('.partner-import-counts').innerText(),/3\s+nových/);
      const apply=page.getByRole('button',{name:'Importovat profily (3)'});
      await apply.scrollIntoViewIfNeeded();
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Overflow at ${width}`);
      await page.locator('#partner-backup').screenshot({path:`/tmp/partner-screenshots/import-${width}.png`});
      let submitted=false;
      await page.route('**/admin/purchase-partners/**',async route=>{
        const post=new URLSearchParams(route.request().postData());
        assert.equal(post.get('action'),'purchase-partner-import');assert.equal(post.get('csrf'),'test-token');
        assert.equal(post.get('backup_token'),'preview-token');
        assert.equal(JSON.parse(post.get('backup_json')).partners.length,3);submitted=true;
        await route.fulfill({body:'<p>Import fixture complete</p>',contentType:'text/html'});
      });
      await apply.click();await page.getByText('Import fixture complete').waitFor();assert.ok(submitted);
      await page.goto(url(files[2]));await page.getByRole('alert').filter({hasText:'Soubor neobsahuje platný JSON.'}).waitFor();
      assert.equal(await page.locator('[name="backup_json"]').inputValue(),'{broken <script>');
      assert.equal(await page.getByRole('button',{name:'Importovat profily (3)'}).count(),0);
      await page.unroute('**/admin/purchase-partners/**');
      await page.goto(url(files[3]));
      const remove=page.getByRole('button',{name:'Smazat prodávajícího',exact:true});
      assert.equal(await remove.isEnabled(),true);
      await page.getByLabel('Potvrzuji smazání tohoto profilu prodávajícího.').check();
      await page.route('**/admin/purchase-partners/**',async route=>{
        const post=new URLSearchParams(route.request().postData());
        assert.equal(post.get('action'),'purchase-partner-delete');assert.equal(post.get('csrf'),'test-token');
        assert.equal(post.get('id'),'2');assert.equal(post.get('version'),'1');assert.equal(post.get('confirm_delete'),'1');
        await route.fulfill({body:'<p>Deleted fixture</p>',contentType:'text/html'});
      });
      await remove.scrollIntoViewIfNeeded();
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Delete overflow ${width}`);
      await page.locator('#partner-delete').screenshot({path:`/tmp/partner-screenshots/delete-${width}.png`});
      await remove.click();await page.getByText('Deleted fixture').waitFor();
      assert.deepEqual(errors,[]);await page.close();
    }
  }finally{await browser?.close();server.kill();for(const f of files)unlinkSync(f);}
});
