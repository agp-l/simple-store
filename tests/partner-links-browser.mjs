import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { chromium } from 'playwright';

test('partner links share a visible editor with scoped photos, private URLs and guarded forms', async () => {
  const fixtures = { variants: ['--admin','--partners'], simple: ['--admin','--partners','--empty'], public: ['--partners'] };
  for (const [key,args] of Object.entries(fixtures)) writeFileSync(`tests/fixtures/partner-${key}.html`,execFileSync('php',['tests/fixtures/product-variants.php',...args]));
  const server=spawn('php',['-S','127.0.0.1:8786','-t',process.cwd()],{stdio:'ignore'});
  let browser;
  try {
    for(let i=0;i<50;i++){try{await (await fetch('http://127.0.0.1:8786/tests/fixtures/partner-simple.html')).arrayBuffer();break;}catch{await new Promise(r=>setTimeout(r,100));}}
    browser=await chromium.launch({headless:true});
    mkdirSync('/tmp/partner-link-screenshots',{recursive:true});
    for(const width of [1440,768,390]) for(const js of [true,false]) {
      const page=await browser.newPage({viewport:{width,height:1050},javaScriptEnabled:js});
      const errors=[];page.on('pageerror',e=>errors.push(e.message));
      await page.route('https://**/*',r=>r.abort());
      await page.goto('http://127.0.0.1:8786/tests/fixtures/partner-variants.html');
      const groups=page.locator('.variant-table-group');
      if(js) assert.equal(await page.locator('[data-variant-save-bar]').evaluate(el=>getComputedStyle(el).position),'static','Save control must not float over edited rows');
      const blue=groups.nth(2);
      const live=blue.locator('.variant-partner-links > .variant-partner-entry');
      assert.equal(await live.count(),2,'Exact offer and add form visible without expansion');
      assert.equal(await live.first().locator('input[name=url]').isVisible(),true);
      const variantFieldStyle=await live.first().locator('input[name=url]').evaluate(el=>{const s=getComputedStyle(el);return [s.paddingTop,s.borderRadius,s.fontSize];});
      assert.match(await blue.locator('[data-partner-image]').getAttribute('src'),/batoh.webp$/);
      assert.match(await groups.first().locator('[data-partner-image-note]').textContent(),/Fotografie stejné barvy/);
      assert.equal(await blue.locator('.partner-older-links').getAttribute('open'),null);
      assert.equal(await blue.locator('.partner-older-links input[name=url]').isVisible(),false);
      assert.equal(await page.locator('.partner-source-links').count(),1);
      assert.equal(await page.locator('.partner-source-links input[name=url]').count(),2);
      for (const input of await page.locator('.partner-source-links input[name=url]').all()) assert.equal(await input.isVisible(),false);
      const open=live.first().locator('.variant-partner-heading a');
      assert.equal(await open.getAttribute('target'),'_blank');assert.match(await open.getAttribute('rel'),/noopener/);
      assert.match(await open.getAttribute('href'),/color=blue&size=M/);
      const values=await live.first().locator('form').evaluate(f=>Object.fromEntries(new FormData(f)));
      assert.equal(values.version,'4');assert.match(values.variant_key,/^[a-f0-9]{32}$/);
      assert.equal(await live.first().locator('button').first().getAttribute('value'),'variant-save');
      if(js){
        const before=await groups.first().locator('[data-partner-image]').getAttribute('src');
        let posts=0;page.on('request',r=>{if(r.method()==='POST')posts++;});
        await blue.locator('select[name=image_path]').selectOption('');
        assert.match(await blue.locator('[data-partner-image]').getAttribute('src'),/product-placeholder.png$/);
        assert.match(await blue.locator('[data-partner-image-note]').textContent(),/ulož změny/);
        assert.equal(await blue.locator('[data-variant-photo-save]').isVisible(),true);
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Photo save controls overflow the viewport');
        await blue.screenshot({path:`/tmp/partner-link-screenshots/photo-draft-${width}.png`});
        assert.notEqual(await groups.first().locator('[data-partner-image]').getAttribute('src'),before, 'Inherited color photo follows the changed source');
        assert.match(await groups.first().locator('[data-partner-image]').getAttribute('src'),/product-placeholder.png$/);
        assert.equal(posts,0,'Photo preview must not write unsaved data');
        await blue.locator('select[name=image_path]').selectOption('images/batoh.webp');
        assert.equal(await blue.locator('[data-variant-photo-save]').isVisible(),false,'Reverting the photo must clear the pending save notice');
      }
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Variant page overflows');
      await blue.screenshot({path:`/tmp/partner-link-screenshots/variant-${width}-${js}.png`});
      await page.goto('http://127.0.0.1:8786/tests/fixtures/partner-simple.html');
      assert.equal(await page.locator('.partner-source-links').count(),0);
      const base=page.locator('#supplier-links');
      let stockPosts=0;page.on('request',r=>{if(r.method()==='POST')stockPosts++;});
      assert.equal(await base.locator('form').count(),2);
      assert.equal(await page.locator('#product-base-offer .base-offer-row').count(),1,'Simple product must use the same offer section');
      await page.getByRole('spinbutton',{name:'Volné kusy skladem',exact:true}).fill('12');
      const stock=await page.locator('#product-base-stock').evaluate(form=>Object.fromEntries(new FormData(form)));
      assert.equal(stock.quantity,'12','The table stock input must belong to its save form');
      assert.equal(stock.action,'set-product-stock');assert.equal(stock.csrf,'test-token');
      assert.equal(await page.getByRole('navigation',{name:'Části editoru produktu'}).count(),1);

      assert.equal(await base.locator('input[name=url]').first().isVisible(),true);
      const simpleFieldStyle=await base.locator('input[name=url]').first().evaluate(el=>{const s=getComputedStyle(el);return [s.paddingTop,s.borderRadius,s.fontSize];});
      assert.deepEqual(simpleFieldStyle,variantFieldStyle,'Simple and variant offers must share field styling');
      assert.equal(simpleFieldStyle[0],'8px');
      assert.equal(await base.locator('details').count(),0);
      assert.match(await base.locator('[data-partner-image]').getAttribute('src'),/product-placeholder.png$/);
      assert.match(await base.locator('input[name=expected_link]').first().inputValue(),/^[a-f0-9]{64}$/);
      assert.equal(await base.locator('button').first().getAttribute('value'),'save');
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Simple page overflows');
      // Keep the surrounding stock row and save boundary in the visual proof, too.
      await page.screenshot({path:`/tmp/partner-link-screenshots/simple-${width}-${js}.png`,fullPage:true});
      assert.equal(await base.count(),1);
      assert.equal(stockPosts,0,'Typing stock must wait for its explicit save button');
      assert.deepEqual(errors,[]);
      await page.goto('http://127.0.0.1:8786/tests/fixtures/partner-public.html');
      assert.equal(await page.locator('.variant-partner-form').count(),0);
      assert.equal(await page.locator('a[href*="example.test/jacket"]').count(),0);
      await page.close();
    }
  }finally{await browser?.close();server.kill();for(const key of Object.keys(fixtures))unlinkSync(`tests/fixtures/partner-${key}.html`);}
});

test('bulk color photos and independent partner saves preserve variant drafts', async () => {
  const path = 'tests/fixtures/partner-bulk.html';
  const html = execFileSync('php', ['tests/fixtures/product-variants.php', '--admin', '--partners']).toString();
  writeFileSync(path, html);
  const server = spawn('php', ['-S', '127.0.0.1:8787', '-t', process.cwd()], { stdio: 'ignore' });
  let browser;
  try {
    const url = 'http://127.0.0.1:8787/' + path;
    for (let i=0;i<50;i++) { try { await (await fetch(url)).arrayBuffer(); break; } catch { await new Promise(r=>setTimeout(r,100)); } }
    browser = await chromium.launch({headless:true});
    const page = await browser.newPage();
    const errors = []; page.on('pageerror',e=>errors.push(e.message));
    await page.route('https://**/*',r=>r.abort());
    await page.goto(url);
    const rows = page.locator('[data-variant-row]');
    const save = page.getByRole('button',{name:'Uložit změny všech variant',exact:true});
    assert.equal(await save.isDisabled(),true);
    assert.equal(await page.locator('[data-variant-row-save]:visible').count(),0);
    let posts = 0, mode = 'partner';
    page.on('request',r=>{if(r.method()==='POST')posts++;});
    await rows.nth(2).getByRole('button',{name:'Přiřadit celé barvě (3)',exact:true}).click();
    await rows.nth(0).locator('[name=quantity]').fill('3');
    assert.equal(posts,0,'Color assignment remains a draft until the single bulk save');
    assert.equal(await rows.nth(0).locator('[name=image_path]').inputValue(),'images/batoh.webp');
    assert.equal(await rows.nth(4).locator('[name=image_path]').inputValue(),'images/batoh.webp');
    assert.equal(await rows.nth(1).locator('[name=image_path]').inputValue(),'','Other colors must not change');
    assert.equal(await page.locator('.variant-table-group.is-dirty').count(),2);
    assert.match(await page.locator('[data-variant-save-state]').textContent(),/2 variant/);
    assert.equal(await rows.nth(2).locator('[data-variant-photo-save]').isVisible(),true,'The unchanged source row must show how to save its copied color photos');
    assert.match(await rows.nth(0).locator('[data-variant-row-state]').textContent(),/Čeká na uložení/);
    assert.equal(await rows.nth(1).locator('[data-variant-photo-save]').isVisible(),false,'Unchanged colors need no photo save notice');
    const partner = () => page.locator('.variant-table-group').nth(2).locator('.variant-partner-form').first();
    await partner().locator('[name=label]').fill('Modrá M ověřeno');
    await rows.nth(2).getByRole('button',{name:'Uložit všechny varianty',exact:true}).click();
    assert.equal(posts,0,'Bulk save must not silently discard a pending partner edit');
    assert.match(await page.locator('[data-variant-save-state]').textContent(),/Nejprve ulož rozpracovaný partnerský odkaz/);
    assert.equal(await page.locator('[data-variant-save-state]').evaluate(el=>el===document.activeElement),true,'A blocked save beside a photo must bring the explanation into view');
    await page.route('**/admin/',async route=>{
      const form = new URLSearchParams(route.request().postData());
      if (mode === 'partner') {
        assert.equal(form.get('operation'),'variant-save');
        assert.equal(form.get('label'),'Modrá M ověřeno');
        await route.fulfill({body:html.replaceAll('value="Modrá M"','value="Modrá M ověřeno"'),contentType:'text/html; charset=utf-8'});
      } else if (mode === 'batch') {
        assert.equal(form.get('operation'),'batch');
        const changes = JSON.parse(form.get('rows_json'));
        assert.equal(changes.length,2,'Submit only changed variants');
        assert.equal(changes[0].quantity,'3');
        assert.equal(changes[1].quantity,'1');
        assert.ok(changes.every(row=>row.image_path==='images/batoh.webp'));
        assert.ok(changes.every(row=>row.price_czk===''));
        await route.fulfill({body:'<p>Hromadně uloženo</p>',contentType:'text/html; charset=utf-8'});
      } else {
        await route.fulfill({body:html.replaceAll('b'.repeat(64),'c'.repeat(64)),contentType:'text/html; charset=utf-8'});
      }
    });
    await partner().getByRole('button',{name:'Uložit pouze odkaz',exact:true}).click();
    await page.waitForURL('**/admin/');
    await rows.nth(0).waitFor();
    assert.equal(await rows.nth(0).locator('[name=quantity]').inputValue(),'3','Partner save lost the stock draft');
    assert.equal(await rows.nth(4).locator('[name=image_path]').inputValue(),'images/batoh.webp','Partner save lost bulk photos');
    assert.equal(await page.locator('.variant-table-group.is-dirty').count(),2);
    mode = 'batch';
    await rows.nth(2).getByRole('button',{name:'Uložit všechny varianty',exact:true}).click(); await page.getByText('Hromadně uloženo',{exact:true}).waitFor();
    assert.equal(posts,2);
    await page.goto(url);
    await rows.nth(0).locator('[name=quantity]').fill('9');
    mode = 'concurrent';
    await partner().getByRole('button',{name:'Uložit pouze odkaz',exact:true}).click();
    await page.waitForURL('**/admin/'); await rows.nth(0).waitFor();
    assert.equal(await rows.nth(0).locator('[name=quantity]').inputValue(),'0','Never restore stale stock over a concurrent inventory change');
    assert.match(await page.locator('[data-variant-save-state]').textContent(),/nelze bezpečně obnovit/);
    assert.deepEqual(errors,[]);
  } finally { await browser?.close(); server.kill(); unlinkSync(path); }
});
