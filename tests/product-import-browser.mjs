import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';

const script = readFileSync(new URL('../assets/product-import.js', import.meta.url), 'utf8');
function fixture(responses) {
  let submit;
  const button = {disabled: false};
  const message = {textContent: ''};
  const element = () => ({textContent: '', children: [], append(child) { this.children.push(child); this.lastChild = child; }, replaceChildren() { this.children = []; }});
  const rows = element(); const progress = element();
  const form = {
    // A named control shadows HTMLFormElement.action in real browsers.
    action: {name: 'action', value: 'product-zip-step', toString: () => '[object HTMLInputElement]'},
    getAttribute: name => name === 'action' ? '/admin.php?section=product-import#zip-import' : null,
    dataset: {basePath: '/'},
    querySelector: selector => selector === 'button' ? button : message,
    addEventListener: (name, handler) => { if (name === 'submit') submit = handler; },
  };
  const requests = [];
  vm.runInNewContext(script, {
    document: {getElementById: id => ({'zip-step-form': form, 'zip-result-rows': rows, 'zip-progress': progress})[id], createElement: element},
    FormData: class { constructor() {this.values = {action: 'product-zip-step', csrf: 'valid-token'};} set(k, v) {this.values[k] = v;} },
    fetch: async (url, options) => {requests.push({url, options}); return responses.shift();},
  });
  return {submit: () => submit({preventDefault() {}}), requests, button, message};
}
const response = (state, error = '', status = 200) => ({ok: status === 200, status,
  headers: {get: () => 'application/json; charset=utf-8'}, json: async () => ({state, error})});
test('ZIP request uses the form URL despite its named action control and continues past failed products', async () => {
  const failed = {folder: 'bad', status: 'error', message: 'Invalid JSON'};
  const app = fixture([
    response({items: [failed, {folder: 'good', status: 'pending', message: ''}]}),
    response({items: [failed, {folder: 'good', status: 'success', message: 'Saved', saved: {language: 'cs', slug: 'good'}}]}),
  ]);
  await app.submit();
  assert.equal(app.requests.length, 2);
  for (const {url, options} of app.requests) {
    assert.equal(url, '/admin.php?section=product-import#zip-import');
    assert.equal(options.method, 'POST');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.body.values.action, 'product-zip-step');
    assert.equal(options.body.values.ajax, '1');
  }
  assert.equal(app.button.disabled, true);
  assert.match(app.message.textContent, /Hotovo/);
});
test('authentication error is displayed and is not retried', async () => {
  const app = fixture([response(null, 'Platnost přihlášení vypršela.', 403)]);
  await app.submit();
  assert.equal(app.requests.length, 1);
  assert.match(app.message.textContent, /Platnost přihlášení vypršela/);
  assert.equal(app.button.disabled, false);
});
test('non-JSON response includes HTTP status and stops without replaying a write', async () => {
  const app = fixture([{status: 502, headers: {get: () => 'text/html'}}]);
  await app.submit();
  assert.equal(app.requests.length, 1);
  assert.match(app.message.textContent, /HTTP 502/);
});
