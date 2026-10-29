import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const classes = () => ({add() {}, remove() {}, toggle() {}});
const status = {textContent: '', classList: classes()};
const listHandlers = {};
let capturedPointer = null;
const list = {addEventListener: (name, fn) => listHandlers[name] = fn,
  setPointerCapture: id => capturedPointer = id,
  releasePointerCapture: () => capturedPointer = null,
  hasPointerCapture: id => capturedPointer === id,
  children: [], dataset: {scope: 'spani', language: 'cs', csrf: 'token', endpoint: '/admin.php'},
  parentElement: {querySelector: () => status}, setAttribute() {}, removeAttribute() {},
  append(item) { this.children = this.children.filter(x => x !== item); this.children.push(item); },
  insertBefore(item, target) { this.children = this.children.filter(x => x !== item); const i = this.children.indexOf(target); this.children.splice(i < 0 ? this.children.length : i, 0, item); }};
const item = (key) => {
  const handlers = {};
  const position = {textContent: ''};
  const buttons = ['up', 'down'].map(direction => ({dataset: {selectionMove: direction}, handlers: {}, attrs: {},
    addEventListener(name, fn) { this.handlers[name] = fn; }, setAttribute(name, value) { this.attrs[name] = value; }, focus() {}}));
  const handle = {addEventListener: (name, fn) => handlers[name] = fn, focus() {}, setPointerCapture() { throw new Error('Capture must not belong to a moving row.'); }, releasePointerCapture() {}};
  const row = {dataset: {key}, parentElement: list, classList: classes(), handlers, buttons, position,
    querySelector: selector => selector === '.selection-drag' ? handle : position, querySelectorAll: () => buttons,
    closest: () => row, getBoundingClientRect: () => ({top: 0, height: 100}),
    get previousElementSibling() { return list.children[list.children.indexOf(row) - 1]; },
    get nextElementSibling() { return list.children[list.children.indexOf(row) + 1]; },
    get nextSibling() { return this.nextElementSibling; }};
  return row;
};
const a = item('a'.repeat(32)), b = item('b'.repeat(32)); list.children = [a, b];
let responseOK = true, payload, requests = 0, activeCatalog = null, sortValue = 'price-asc', redirected = '';
const location = {href: 'https://shop.example/cs/kategorie-produktu/spani?sort=random&offset=12&seed=0123456789abcdef&max_weight=500', assign: url => redirected = url};
const document = {querySelectorAll: () => [list], getElementById: id => id === 'catalog' ? activeCatalog : id === 'sort' ? {value: sortValue} : null, elementFromPoint: () => a};
vm.runInNewContext(fs.readFileSync('assets/product-selection.js', 'utf8'), {document, URLSearchParams, URL, Map, location,
  fetch: async (_url, options) => { requests++; payload = options.body; return {ok: responseOK, json: async () => responseOK ? {saved: true} : {error: 'Conflict'}}; }});
const settle = () => new Promise(resolve => setImmediate(resolve));
b.handlers.keydown({altKey: true, key: 'ArrowUp', preventDefault() {}}); await settle();
assert.deepEqual(list.children, [b, a]);
assert.deepEqual(JSON.parse(payload.get('expected')), [a.dataset.key, b.dataset.key]);
assert.deepEqual(JSON.parse(payload.get('order')), [b.dataset.key, a.dataset.key]);
assert.equal(payload.get('csrf'), 'token');
assert.equal(status.textContent, 'Pořadí uloženo.');
responseOK = false;
b.handlers.keydown({altKey: true, key: 'ArrowDown', preventDefault() {}}); await settle();
assert.deepEqual(list.children, [b, a], 'Failed save did not restore acknowledged order.');
assert.equal(status.textContent, 'Conflict');
responseOK = true;
b.handlers.pointerdown({button: 0, pointerId: 1, preventDefault() {}});
assert.equal(capturedPointer, 1, 'The stationary list must retain capture across row moves.');
listHandlers.pointermove({pointerId: 1, clientX: 0, clientY: 80});
assert.deepEqual(list.children, [a, b]);
listHandlers.lostpointercapture({target: b, pointerId: 1});
assert.deepEqual(list.children, [a, b], 'A descendant capture event must not cancel the list drag.');
listHandlers.pointercancel();
assert.deepEqual(list.children, [b, a], 'Cancelled drag persisted a new order.');
assert.equal(requests, 2);
b.handlers.pointerdown({button: 0, pointerId: 2, preventDefault() {}});
listHandlers.pointermove({pointerId: 2, clientX: 0, clientY: 80});
listHandlers.pointerup({pointerId: 2}); await settle();
assert.deepEqual(list.children, [a, b]); assert.equal(requests, 3);
console.log('Product selection pointer, keyboard, persistence and failure recovery tests passed.');

let prepends = 0;
activeCatalog = {querySelectorAll: () => [{dataset: {productKey: a.dataset.key}}, {dataset: {productKey: b.dataset.key}}], prepend() { prepends++; }};
b.handlers.keydown({altKey: true, key: 'ArrowUp', preventDefault() {}}); await settle();
assert.equal(prepends, 0, 'Saving tips must not override explicit catalog ordering.');
assert.equal(redirected, '');
sortValue = 'random';
b.handlers.keydown({altKey: true, key: 'ArrowDown', preventDefault() {}}); await settle();
const refreshed = new URL(redirected);
assert.equal(refreshed.searchParams.has('offset'), false, 'Changed recommendations must reset pagination.');
assert.equal(refreshed.searchParams.get('max_weight'), '500');
assert.equal(refreshed.searchParams.get('seed'), '0123456789abcdef');
assert.equal(prepends, 0, 'Category preview must reload complete ordering instead of moving an incomplete card subset.');
list.dataset.scope = 'home'; redirected = '';
b.handlers.keydown({altKey: true, key: 'ArrowUp', preventDefault() {}}); await settle();
assert.equal(prepends, 2, 'Homepage preview should still update immediately.');
assert.equal(redirected, '');
console.log('Recommendation preview respects explicit sorts and resets random pagination.');

assert.equal(b.position.textContent, '1');
assert.equal(b.buttons[0].attrs['aria-disabled'], 'true');
let prevented = false;
b.buttons[1].handlers.click({preventDefault() { prevented = true; }});
assert.equal(prevented, true, 'Enhanced move must not also submit the fallback form.');
const requestsDuringSave = requests;
a.buttons[1].handlers.click({preventDefault() {}});
let submitBlocked = false;
listHandlers.submit({preventDefault() { submitBlocked = true; }});
assert.equal(submitBlocked, true, 'Removal must wait for the outstanding reorder.');
await settle();
assert.equal(requests, requestsDuringSave, 'Rapid clicks must not race saves.');
assert.deepEqual(list.children, [a, b]);
assert.equal(a.position.textContent, '1'); assert.equal(b.position.textContent, '2');
assert.equal(b.buttons[1].attrs['aria-disabled'], 'true');
b.buttons[1].handlers.click({preventDefault() {}}); await settle();
assert.equal(requests, requestsDuringSave, 'Moving past the boundary should not save.');
responseOK = false;
b.buttons[0].handlers.click({preventDefault() {}}); await settle();
assert.deepEqual(list.children, [a, b]);
assert.equal(a.position.textContent, '1'); assert.equal(b.position.textContent, '2');
assert.equal(status.textContent, 'Conflict');
console.log('Visible move buttons, positions, boundaries and concurrent-save protection passed.');
