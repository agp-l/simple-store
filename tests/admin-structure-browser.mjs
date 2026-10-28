import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {mkdirSync,writeFileSync,unlinkSync} from 'node:fs';
import {chromium} from 'playwright';

test('category ordering, menu choices and backup review on desktop and touch',async()=>{
  const fixtures={categories:['categories'],menus:['menus'],content:['menus','content'],backup:['categories','backup']};
  const files=[];let server,browser;
  const url=name=>`http://127.0.0.1:8793/tests/fixtures/structure-${name}.html`;
  try {
    for(const [name,args]of Object.entries(fixtures)){const file=`tests/fixtures/structure-${name}.html`;files.push(file);writeFileSync(file,execFileSync('php',['tests/fixtures/admin-structure.php',...args]));}
    server=spawn('php',['-S','127.0.0.1:8793','-t',process.cwd()],{stdio:'ignore'});
    for(let i=0;i<50;i++){try{await fetch(url('categories'),{method:'HEAD'});break;}catch{await new Promise(r=>setTimeout(r,100));}}
    browser=await chromium.launch();mkdirSync('/tmp/admin-structure-screenshots',{recursive:true});
    for(const width of [320,390,768,1100,1440,1920]){
      const page=await browser.newPage({viewport:{width,height:950},hasTouch:width<800});const errors=[];
      page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());await page.route('https://**/*',r=>r.abort());
      for(const name of Object.keys(fixtures)){
        await page.goto(url(name));
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,`${name} overflow ${width}`);
        if([390,1440].includes(width))await page.screenshot({path:`/tmp/admin-structure-screenshots/${name}-${width}.png`,fullPage:true});
      }
      await page.goto(url('menus'));
      const source=page.locator('[data-menu-source]');
      assert.equal(await source.locator('[data-source-fields="categories"]').isVisible(),true);
      assert.equal(await source.locator('[data-source-fields="content"]').isVisible(),false);
      await source.locator('[name="source"]').selectOption('content');
      assert.equal(await source.locator('[data-source-fields="content"]').isVisible(),true);
      assert.equal(await source.locator('[data-source-fields="categories"]').isVisible(),false);
      assert.equal(await source.getByLabel('Pořadí Blogu',{exact:true}).inputValue(),'0');
      await page.goto(url('content'));
      assert.equal(await page.getByLabel('Pořadí Blogu',{exact:true}).inputValue(),'20');
      assert.deepEqual(await page.locator('.structure-menu-preview .structure-badge').allTextContents(),['Pořadí 10','Pořadí 20','Pořadí 30']);
      await page.getByLabel('Pořadí Blogu',{exact:true}).fill('40');
      let menuPost;
      await page.route('**/admin/menus/**',async r=>{menuPost=new URLSearchParams(r.request().postData());await r.fulfill({contentType:'text/html',body:'<p>Saved menu fixture</p>'});});
      await page.getByRole('button',{name:'Uložit nastavení menu',exact:true}).click();await page.getByText('Saved menu fixture').waitFor();
      assert.equal(menuPost.get('blog_order'),'40');assert.equal(menuPost.get('include_blog'),'1');
      await page.unroute('**/admin/menus/**');
      await page.goto(url('menus'));
      const destination=page.locator('[data-menu-destination]');
      assert.equal(await destination.locator('[name="parent_id"] option').count(),2,'Self and descendants are excluded.');
      await destination.locator('[name="destination_type"]').selectOption('external');
      assert.equal(await destination.locator('[name="destination_external"]').isVisible(),true);
      assert.equal(await destination.locator('[name="destination_page"]').isVisible(),false);
      await page.goto(url('categories'));
      const roots=page.locator('[data-category-group=""] > [data-category-node]');
      assert.deepEqual(await roots.evaluateAll(els=>els.map(e=>e.dataset.categoryNode)),['spani','batohy','obleceni']);
      await page.getByRole('button',{name:'Posunout nahoru: Batohy',exact:true}).click();
      assert.deepEqual(await roots.evaluateAll(els=>els.map(e=>e.dataset.categoryNode)),['batohy','spani','obleceni']);
      assert.equal(await page.locator('[name="positions[batohy]"]').inputValue(),'10');
      assert.equal(await page.locator('[data-category-node="spani"] [data-category-node]').count(),4,'Whole branch stays with its parent.');
      await page.locator('[data-tree-search]').fill('zimni');
      assert.equal(await page.locator('[data-category-node="spani/spacaky/zimni"]').isVisible(),true);
      assert.equal(await page.locator('[data-category-node="batohy"]').isVisible(),false);
      assert.equal(await page.locator('[data-tree-drag]:visible:enabled').count(),0);
      await page.locator('[data-tree-search]').fill('');
      await page.getByRole('button',{name:'Sbalit podkategorie',exact:true}).click();
      assert.equal(await page.locator('[data-category-node="spani/spacaky"]').isVisible(),false);
      if(width===1440){
        const handle=page.locator('[data-category-node="obleceni"] > [data-category-row] [data-tree-drag]');
        const target=page.locator('[data-category-node="batohy"] > [data-category-row]');
        await handle.scrollIntoViewIfNeeded();const a=await handle.boundingBox(),b=await target.boundingBox();
        await page.mouse.move(a.x+a.width/2,a.y+a.height/2);await page.mouse.down();await page.mouse.move(b.x+80,b.y+4,{steps:12});await page.mouse.up();
        assert.deepEqual(await roots.evaluateAll(els=>els.map(e=>e.dataset.categoryNode)),['obleceni','batohy','spani']);
      }
      if(width===390){
        const node=page.locator('[data-category-node="obleceni"] > [data-category-row]');
        await page.locator('[data-category-group=""]').evaluate(el=>scrollTo({top:el.getBoundingClientRect().top+scrollY-100,behavior:'instant'}));
        const handle=node.locator('[data-tree-drag]'),a=await handle.boundingBox(),b=await page.locator('[data-category-node="batohy"] > [data-category-row]').boundingBox();
        const cdp=await page.context().newCDPSession(page);
        await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:a.x+a.width/2,y:a.y+a.height/2}]});
        await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:b.x+65,y:b.y+5}]});
        await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
        assert.deepEqual(await roots.evaluateAll(els=>els.map(e=>e.dataset.categoryNode)),['obleceni','batohy','spani']);
      }
      await page.locator('[name="positions[batohy]"]').fill('7');
      let post;
      await page.route('**/admin/categories/**',async r=>{post=new URLSearchParams(r.request().postData());await r.fulfill({contentType:'text/html',body:'<p>Saved fixture</p>'});});
      await page.getByRole('button',{name:'Uložit pořadí',exact:true}).click();await page.getByText('Saved fixture').waitFor();
      assert.equal(post.get('positions[batohy]'),'7');assert.equal(post.get('action'),'category-order');assert.equal(post.get('csrf'),'fixture-token');
      assert.equal([...post.keys()].filter(x=>x.startsWith('positions[')).length,10,'Collapsed children are submitted too.');
      assert.deepEqual(errors,[]);await page.close();
    }
    const page=await browser.newPage({javaScriptEnabled:false,viewport:{width:390,height:900}});await page.goto(url('categories'));
    assert.equal(await page.locator('[name="positions[spani/spacaky]"]').isVisible(),true);assert.equal(await page.getByRole('button',{name:'Uložit pořadí',exact:true}).isVisible(),true);await page.close();
  }finally{if(browser)await browser.close();server?.kill();for(const f of files)try{unlinkSync(f);}catch{}}
});
