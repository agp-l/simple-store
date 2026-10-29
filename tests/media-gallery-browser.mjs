import test from 'node:test';
import assert from 'node:assert/strict';
import {spawn, execFileSync} from 'node:child_process';
import {mkdirSync, writeFileSync, unlinkSync} from 'node:fs';
import {chromium} from 'playwright';

test('product gallery detaches, preserves references and deletes only after confirmation', async () => {
  const fixture='tests/fixtures/media-gallery.html';let server,browser;
  const url='http://127.0.0.1:8797/'+fixture;
  try {
    writeFileSync(fixture,execFileSync('php',['tests/fixtures/media-gallery.php']));
    server=spawn('php',['-S','127.0.0.1:8797','-t',process.cwd()],{stdio:'ignore'});
    for(let i=0;i<50;i++){try{await fetch(url);break;}catch{await new Promise(r=>setTimeout(r,100));}}
    browser=await chromium.launch();mkdirSync('/tmp/media-gallery-screenshots',{recursive:true});
    for(const width of [390,1440]) {
      const page=await browser.newPage({viewport:{width,height:950}});const errors=[];page.on('pageerror',error=>errors.push(error.message));
      const path='images/media/products/'+'a'.repeat(32)+'/boty--'+'b'.repeat(24)+'.webp';
      const file=(label,uses,can_delete,p=path)=>({path:p,label,thumb:'images/batoh.webp',card:'images/batoh.webp',uses,can_delete,delete_reason:can_delete?'':'Fotografie se stále používá.'});
      let attached=true,deleted=false,failed=true;const mutations=[];
      await page.route('**/admin/**',async route=>{
        if(route.request().method()==='GET') {
          await route.fulfill({json:{files:[...(deleted?[]:[file('Boty',attached?['gallery']:[],!attached)]),file('Barva', ['variant'],false,'images/variant.webp'),file('Sdílená',['main'],false,'images/shared.webp')],total:3,folders:{}}});return;
        }
        const data=Object.fromEntries([...route.request().postData().matchAll(/name="(\w+)"\r\n\r\n([^\r]*)/g)].map(match=>[match[1],match[2]]));
        mutations.push(data);assert.equal(data.csrf,'fixture-token');assert.equal(data.path,path);
        if(data.action==='media-detach') {
          assert.equal(data.revision,'4');
          if(failed){failed=false;await route.fulfill({status:409,json:{error:'Obsah se mezitím změnil. Obnov stránku.'}});return;}
          attached=false;await route.fulfill({json:{revision:5,detached:path,url:'/cs/produkt/test?edit=1'}});return;
        }
        assert.equal(data.action,'media-delete');assert.equal(data.revision,'5');deleted=true;
        await route.fulfill({json:{revision:5,deleted:path}});
      });
      await page.goto(url);await page.getByRole('button',{name:'Spravovat fotografie'}).click();
      const card=page.locator('.media-item').filter({has:page.getByText('Boty',{exact:true})});
      await card.getByRole('button',{name:'Odebrat z galerie',exact:true}).waitFor();
      assert.equal(await card.getByRole('button',{name:'Smazat soubor',exact:true}).isDisabled(),true);
      assert.equal(await page.getByText('Fotografii používá varianta. Přiřazení změň v tabulce variant.').count(),1);
      await page.screenshot({path:`/tmp/media-gallery-screenshots/gallery-${width}.png`,fullPage:true});
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
      await card.getByRole('button',{name:'Odebrat z galerie',exact:true}).click();
      await page.getByText('Obsah se mezitím změnil. Obnov stránku.',{exact:true}).waitFor();
      assert.equal(await card.count(),1);assert.equal(await page.evaluate(()=>galleryRevision),4);
      await card.getByRole('button',{name:'Odebrat z galerie',exact:true}).click();
      await page.getByText('Odebráno a uloženo. Soubor zůstává v knihovně.',{exact:true}).waitFor();
      assert.equal(await page.evaluate(()=>galleryRevision),5);
      assert.equal(await card.getByRole('button',{name:'Smazat soubor',exact:true}).isEnabled(),true);
      assert.equal(await card.getByRole('button',{name:'Přidat do galerie',exact:true}).count(),1);
      page.once('dialog',dialog=>dialog.dismiss());
      await card.getByRole('button',{name:'Smazat soubor',exact:true}).click();assert.equal(mutations.length,2);
      page.once('dialog',dialog=>dialog.accept());
      await card.getByRole('button',{name:'Smazat soubor',exact:true}).click();
      await page.getByText('Fotografie a její miniatury byly smazány z disku.',{exact:true}).waitFor();
      assert.equal(await card.count(),0);assert.equal(mutations.length,3);
      await page.locator('#media-library-filter [name="scope"]').selectOption('all');
      await page.getByText('3 obrázků v knihovně',{exact:true}).waitFor();
      assert.equal(await page.getByRole('button',{name:'Odebrat hlavní fotografii',exact:true}).count(),1);
      assert.deepEqual(errors,[]);await page.close();
    }
  } finally {await browser?.close();server?.kill();try{unlinkSync(fixture);}catch{}}
});
