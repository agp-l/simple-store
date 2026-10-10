import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const script = readFileSync(new URL('../assets/media-manager.js', import.meta.url), 'utf8');

for (const [mode, label] of [['main-image', 'Nahrát · první jako hlavní'], ['gallery-add', 'Nahrát do galerie'], ['section-image', 'Nahrát · první nahradí blok']]) {
  test(`opens ${mode} and loads local media without an external URL input`, async () => {
    const status = {textContent: ''};
    const primary = {textContent: ''};
    const close = {addEventListener() {}};
    const dialog = {shown: 0, showModal() {this.shown++;}, addEventListener() {},
      querySelector(selector) {return {'[data-media-dialog-status]': status, '.media-primary': primary, '[data-media-close]': close}[selector] ?? null;}};
    const grid = {replaceChildren() {}, append() {}};
    const requests = [];
    const window = {SimpleStoreMediaContext: {endpoint: '/admin.php', type: 'product', key: 'a'.repeat(32), language: 'cs', basePath: '/'}};
    const document = {
      getElementById(id) {return {'media-picker': dialog, 'media-dialog-grid': grid, 'media-upload-form': {addEventListener() {}}, 'detail-image': {dataset: {imagePath: 'images/photo.webp'}}}[id] ?? null;},
      querySelector() {return null;}, createElement() {return {textContent: ''};},
    };
    vm.runInNewContext(script, {window, document, URL, location: {href: 'https://shop.example/cs/produkt/test', origin: 'https://shop.example'},
      fetch: async url => {requests.push(String(url)); return {ok: true, json: async () => ({files: []})};}});
    assert.doesNotThrow(() => window.SimpleStoreMedia.open(mode, 0, 'images/old.webp'));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(dialog.shown, 1);
    assert.equal(primary.textContent, label);
    assert.equal(status.textContent, '');
    assert.equal(requests.length, 1);
    assert.match(requests[0], /section=media&api=list/);
  });
}
