import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { chromium } from 'playwright';

test('AI instructions combine current variant contract, saved preferences and categories on mobile and desktop', async () => {
    const file='tests/fixtures/product-prompt-render.html';
    const url='http://127.0.0.1:8797/'+file;
    let server, browser;
    try {
        writeFileSync(file, execFileSync('php',['tests/fixtures/product-prompt.php']));
        server=spawn('php',['-S','127.0.0.1:8797','-t',process.cwd()],{stdio:'ignore'});
        for(let i=0;i<50;i++) { try { await fetch(url); break; } catch { await new Promise(resolve=>setTimeout(resolve,100)); } }
        browser=await chromium.launch({headless:true});
        mkdirSync('/tmp/product-prompt-screenshots',{recursive:true});
        for(const width of [390,1440]) {
            const page=await browser.newPage({viewport:{width,height:1000},permissions:['clipboard-read','clipboard-write']});
            const errors=[]; page.on('pageerror',error=>errors.push(error.message));
            await page.goto(url);
            const prompt=await page.getByLabel('Kompletní zadání pro AI').inputValue();
            assert.match(prompt,/variants\.items\[\]\.suppliers/);
            assert.match(prompt,/suppliers_expected/);
            assert.match(prompt,/Piš česky a věcně/);
            assert.match(prompt,/batohy — Batohy/);
            assert.equal(await page.getByLabel('Kompletní zadání pro AI').getAttribute('readonly'),'');
            await page.getByRole('button',{name:'Zkopírovat celé zadání'}).click();
            await page.getByText('Zkopírováno včetně kategorií.').waitFor();
            assert.equal(await page.evaluate(()=>navigator.clipboard.readText()),prompt);
            assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
            assert.deepEqual(errors,[]);
            await page.screenshot({path:`/tmp/product-prompt-screenshots/help-${width}.png`,animations:'disabled'});
            await page.close();
        }
    } finally {
        await browser?.close(); server?.kill();
        try { unlinkSync(file); } catch {}
    }
});
