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
    for(let i=0;i<50;i++){try{await fetch('http://127.0.0.1:8786/tests/fixtures/partner-simple.html');break;}catch{await new Promise(r=>setTimeout(r,100));}}
    browser=await chromium.launch({headless:true});
    mkdirSync('/tmp/partner-link-screenshots',{recursive:true});
    for(const width of [1440,768,390]) for(const js of [true,false]) {
      const page=await browser.newPage({viewport:{width,height:1050},javaScriptEnabled:js});
      const errors=[];page.on('pageerror',e=>errors.push(e.message));
      await page.route('https://**/*',r=>r.abort());
      await page.goto('http://127.0.0.1:8786/tests/fixtures/partner-variants.html');
      const groups=page.locator('.variant-table-group');
      const blue=groups.nth(2);
      const live=blue.locator('.variant-partner-links > .variant-partner-entry');
      assert.equal(await live.count(),2,'Exact offer and add form visible without expansion');
      assert.equal(await live.first().locator('input[name=url]').isVisible(),true);
      assert.match(await blue.locator('[data-partner-image]').getAttribute('src'),/batoh.webp$/);
      assert.match(await groups.first().locator('[data-partner-image-note]').textContent(),/barva zatím není přiřazená/);
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
        assert.equal(await groups.first().locator('[data-partner-image]').getAttribute('src'),before);
        assert.equal(posts,0,'Photo preview must not write unsaved data');
        await blue.locator('select[name=image_path]').selectOption('images/batoh.webp');
      }
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Variant page overflows');
      await blue.screenshot({path:`/tmp/partner-link-screenshots/variant-${width}-${js}.png`});
      await page.goto('http://127.0.0.1:8786/tests/fixtures/partner-simple.html');
      assert.equal(await page.locator('.partner-source-links').count(),0);
      const base=page.locator('#supplier-links');
      assert.equal(await base.locator('form').count(),2);
      assert.equal(await base.locator('input[name=url]').first().isVisible(),true);
      assert.equal(await base.locator('details').count(),0);
      assert.match(await base.locator('[data-partner-image]').getAttribute('src'),/product-placeholder.png$/);
      assert.match(await base.locator('input[name=expected_link]').first().inputValue(),/^[a-f0-9]{64}$/);
      assert.equal(await base.locator('button').first().getAttribute('value'),'save');
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Simple page overflows');
      await base.screenshot({path:`/tmp/partner-link-screenshots/simple-${width}-${js}.png`});
      assert.deepEqual(errors,[]);
      await page.goto('http://127.0.0.1:8786/tests/fixtures/partner-public.html');
      assert.equal(await page.locator('.variant-partner-form').count(),0);
      assert.equal(await page.locator('a[href*="example.test/jacket"]').count(),0);
      await page.close();
    }
  }finally{await browser?.close();server.kill();for(const key of Object.keys(fixtures))unlinkSync(`tests/fixtures/partner-${key}.html`);}
});
