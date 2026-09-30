import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const handlers = new Map();
const field = (value = '') => ({ value });
const id = field();
const name = field();
const address = field();
const zip = field();
const type = field();
const radio = { checked: false };
const status = { textContent: '' };
const selection = { textContent: '' };
const frame = { src: '', dataset: { src: 'https://b2c.cpost.cz/locations/?type=BALIKOVNY&skipLocation=true' }, contentWindow: {} };
const close = { addEventListener: (event, listener) => handlers.set(`close:${event}`, listener) };
const dialogStatus = { textContent: '' };
const dialog = {
  open: false,
  showModal() { this.open = true; },
  close() { this.open = false; },
  querySelector: (selector) => ({ '[data-balikovna-map]': frame, '[data-balikovna-close]': close,
    '[data-balikovna-dialog-status]': dialogStatus })[selector],
};
const inputs = {
  'input[name="method"][value="balikovna_pickup"]': radio,
  '[data-balikovna-id]': id, '[data-balikovna-name]': name,
  '[data-balikovna-address]': address, '[data-balikovna-zip]': zip,
  '[data-balikovna-type]': type, '[data-balikovna-selection]': selection,
  '[data-balikovna-status]': status,
};
const form = {
  querySelector: (selector) => inputs[selector],
  addEventListener: (event, listener) => handlers.set(`form:${event}`, listener),
};
const button = {
  closest: () => form,
  focus: () => { button.focused = true; },
  addEventListener: (event, listener) => handlers.set(`button:${event}`, listener),
};
const document = {
  querySelector: (selector) => ({ '[data-balikovna-open]': button, '[data-balikovna-dialog]': dialog })[selector],
};
const window = { addEventListener: (event, listener) => handlers.set(`window:${event}`, listener) };
runInNewContext(readFileSync(new URL('../assets/balikovna-checkout.js', import.meta.url), 'utf8'),
  { document, window, TextEncoder });

handlers.get('button:click')();
assert.equal(dialog.open, true);
assert.equal(radio.checked, true);
assert.match(frame.src, /type=BALIKOVNY/);

const point = { id: 'B10000', type: 'BALIKOVNY', zip: '10000', name: 'Praha 10',
  address: 'Černokostelecká 2020/20, Strašnice, 10000, Praha' };
const message = (origin, source, payload = { message: 'pickerResult', point }) =>
  handlers.get('window:message')({ origin, source, data: payload });
message('https://example.invalid', frame.contentWindow);
assert.equal(id.value, '');
message('https://b2c.cpost.cz', {});
assert.equal(id.value, '');
message('https://b2c.cpost.cz', frame.contentWindow, { message: 'unrelated', point });
assert.equal(id.value, '');
message('https://b2c.cpost.cz', frame.contentWindow,
  { message: 'pickerResult', point: { ...point, zip: '999' } });
assert.equal(id.value, '');
message('https://b2c.cpost.cz', frame.contentWindow,
  { message: 'pickerResult', point: { ...point, type: 'OTHER' } });
assert.equal(id.value, '');
assert.match(dialogStatus.textContent, /úplné údaje/);

let prevented = false;
handlers.get('form:submit')({ preventDefault: () => { prevented = true; } });
assert.equal(prevented, true);
assert.equal(button.focused, true);

message('https://b2c.cpost.cz', frame.contentWindow);
assert.equal(id.value, 'B10000');
assert.equal(zip.value, '10000');
assert.equal(type.value, 'BALIKOVNY');
assert.equal(name.value, 'Praha 10');
assert.equal(address.value, point.address);
assert.match(selection.textContent, /Praha 10/);
assert.equal(dialog.open, false);
prevented = false;
handlers.get('form:submit')({ preventDefault: () => { prevented = true; } });
assert.equal(prevented, false);
zip.value = '';
handlers.get('form:submit')({ preventDefault: () => { prevented = true; } });
assert.equal(prevented, true);
console.log('Balíkovna checkout map tests passed.');
