import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const classes = () => ({add() {}, remove() {}, toggle() {}});
const status = {textContent: '', classList: classes()};
const list = {children: [], dataset: {scope: 'spani', language: 'cs', csrf: 'token', endpoint: '/admin.php'},
  parentElement: {querySelector: () => status}, setAttribute() {}, removeAttribute() {},
  append(item) { this.children = this.children.filter(x => x !== item); this.children.push(item); },
  insertBefore(item, target) { this.children = this.children.filter(x => x !== item); const i = this.children.indexOf(target); this.children.splice(i < 0 ? this.children.length : i, 0, item); }};
const item = (key) => {
  const handlers = {};
  const handle = {addEventListener: (name, fn) => handlers[name] = fn, focus() {}, setPointerCapture() {}, releasePointerCapture() {}};
  const row = {dataset: {key}, parentElement: list, classList: classes(), handlers, querySelector: () => handle,
    closest: () => row, getBoundingClientRect: () => ({top: 0, height: 100}),
    get previousElementSibling() { return list.children[list.children.indexOf(row) - 1]; },
    get nextElementSibling() { return list.children[list.children.indexOf(row) + 1]; },
    get nextSibling() { return this.nextElementSibling; }};
  return row;
};
const a = item('a'.repeat(32)), b = item('b'.repeat(32)); list.children = [a, b];
let responseOK = true, payload, requests = 0;
const document = {querySelectorAll: () => [list], getElementById: () => null, elementFromPoint: () => a};
vm.runInNewContext(fs.readFileSync('assets/product-selection.js', 'utf8'), {document, URLSearchParams, Map,
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
b.handlers.pointermove({pointerId: 1, clientX: 0, clientY: 80});
assert.deepEqual(list.children, [a, b]);
b.handlers.pointercancel();
assert.deepEqual(list.children, [b, a], 'Cancelled drag persisted a new order.');
assert.equal(requests, 2);
b.handlers.pointerdown({button: 0, pointerId: 2, preventDefault() {}});
b.handlers.pointermove({pointerId: 2, clientX: 0, clientY: 80});
b.handlers.pointerup({pointerId: 2}); await settle();
assert.deepEqual(list.children, [a, b]); assert.equal(requests, 3);
console.log('Product selection pointer, keyboard, persistence and failure recovery tests passed.');
