import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn,execFileSync} from 'node:child_process';
import {mkdirSync,writeFileSync,unlinkSync} from 'node:fs';
import {chromium} from 'playwright';

test('one-click seller assignment, mobile layout and private catalog filters',async()=>{
  const cases=['settings','catalog','public'];
  const files=cases.map(name=>`tests/fixtures/assignment-${name}.html`);
  files.forEach((f,i)=>writeFileSync(f,execFileSync('php',['tests/fixtures/product-partner-assignment.php',cases[i]])));
  const server=spawn('php',['-S','127.0.0.1:8797','-t',process.cwd()],{stdio:'ignore'});
  const url=name=>'http://127.0.0.1:8797/tests/fixtures/assignment-'+name+'.html';
  let browser;
  try{
    for(let i=0;i<50;i++){try{await fetch(url('settings'));break;}catch{await new Promise(r=>setTimeout(r,100));}}
    browser=await chromium.launch({headless:true});mkdirSync('/tmp/assignment-screenshots',{recursive:true});
    for(const width of [390,768,1440]){
      const page=await browser.newPage({viewport:{width,height:1050},javaScriptEnabled:false});
      await page.route('https://**/*',r=>r.abort());
      let submitted;
      await page.route('**/admin/**',async r=>{submitted=new URLSearchParams(r.request().postData());await r.fulfill({body:'<p>Saved in fixture</p>'});});
      await page.goto(url('settings'));
      const root=page.locator('#product-partner-assignment');
      assert.equal(await root.getByRole('button',{name:'Doplnit chybějící prodávající (2)',exact:true}).isVisible(),true);
      assert.equal(await root.getByRole('button',{name:'Přepočítat prodávající podle priorit (3)',exact:true}).isVisible(),false);
      await root.getByText('Zobrazit návrhy a důvody přeskočení (3)',{exact:true}).first().click();
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Settings overflow ${width}`);
      await root.screenshot({path:`/tmp/assignment-screenshots/settings-${width}.png`});
      await root.getByRole('button',{name:'Doplnit chybějící prodávající (2)',exact:true}).click();
      await page.getByText('Saved in fixture').waitFor();
      assert.equal(submitted.get('action'),'products-partners-assign');assert.equal(submitted.get('assignment_scope'),'missing');
      assert.equal(submitted.get('csrf'),'test-token');assert.equal(submitted.get('assignment_token').length,64);
      await page.goto(url('settings'));await root.getByText('Přepočítat i již vybrané prodávající',{exact:true}).click();
      await root.getByRole('button',{name:'Přepočítat prodávající podle priorit (3)',exact:true}).click();await page.getByText('Saved in fixture').waitFor();
      assert.equal(submitted.get('assignment_scope'),'all');
      await page.goto(url('catalog'));
      assert.equal(await page.getByLabel('Partner / dodavatel',{exact:true}).inputValue(),'7');
      assert.equal(await page.locator('#search-form [name="partner_id"]').inputValue(),'7');
      for (const name of ['Zveřejněné','Načíst další produkty']) assert.match(await page.getByRole('link',{name,exact:true}).getAttribute('href'),/partner_id=7/);
      assert.equal(await page.getByLabel('Prodávající a dodavatelé',{exact:true}).inputValue(),'no_partner');
      assert.equal(await page.locator('#search-form [name="supplier_state"]').inputValue(),'no_partner');
      assert.match(await page.getByRole('link',{name:'Zveřejněné',exact:true}).getAttribute('href'),/supplier_state=no_partner/);
      await page.getByText('Vybrat kategorii',{exact:true}).click();
      assert.match(await page.getByRole('navigation',{name:'Kategorie spravovaných produktů'}).getByRole('link',{name:'Spaní',exact:true}).getAttribute('href'),/supplier_state=no_partner/);
      assert.match(await page.getByRole('navigation',{name:'Kategorie spravovaných produktů'}).getByRole('link',{name:'Spaní',exact:true}).getAttribute('href'),/partner_id=7/);
      assert.equal(await page.locator('.manage-supplier-status').count(),1);
      assert.match(await page.getByRole('link',{name:'Načíst další produkty'}).getAttribute('href'),/supplier_state=no_partner/);
      await page.getByRole('heading',{name:'Správa produktů'}).scrollIntoViewIfNeeded();
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`Catalog overflow ${width}`);
      await page.screenshot({path:`/tmp/assignment-screenshots/catalog-${width}.png`});
      await page.getByLabel('Prodávající a dodavatelé',{exact:true}).selectOption('both');
      await page.getByLabel('Partner / dodavatel',{exact:true}).selectOption('8');
      await page.route('**/cs?**',async r=>{const u=new URL(r.request().url());assert.equal(u.searchParams.get('supplier_state'),'both');assert.equal(u.searchParams.get('partner_id'),'8');assert.equal(u.searchParams.get('visibility'),'draft');assert.equal(u.searchParams.get('search'),'spacák');assert.equal(u.searchParams.has('offset'),false);await r.fulfill({body:'<p>Filter fixture</p>'});});
      await page.getByRole('button',{name:'Filtrovat produkty'}).click();await page.getByText('Filter fixture').waitFor();
      await page.goto(url('public'));
      assert.equal(await page.locator('.catalog-supplier-filter,.manage-supplier-status').count(),0,'Admin data appeared in public UI.');
      await page.close();
    }
  }finally{await browser?.close();server.kill();files.forEach(f=>unlinkSync(f));}
});
