import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const handlers = new Map();
const field = (value = '') => ({ value });
const id = field();
const name = field();
const address = field();
const country = field();
const radio = { checked: false };
const status = { textContent: '' };
const selection = { textContent: '' };
const frame = { src: '', dataset: { src: 'https://maps.gls-czech.cz/?find=1&ctrcode=CZ&lng=cs' }, contentWindow: {} };
const close = { addEventListener: (type, callback) => handlers.set(`close:${type}`, callback) };
const dialogStatus = { textContent: '' };
const dialog = {
  open: false,
  showModal() { this.open = true; },
  close() { this.open = false; },
  querySelector: (selector) => ({ '[data-gls-map]': frame, '[data-gls-close]': close,
    '[data-gls-dialog-status]': dialogStatus })[selector],
};
const inputs = {
  'input[name="method"][value="gls_pickup"]': radio,
  '[data-gls-id]': id, '[data-gls-name]': name, '[data-gls-address]': address,
  '[data-gls-country]': country, '[data-gls-selection]': selection, '[data-gls-status]': status,
};
const form = {
  querySelector: (selector) => inputs[selector],
  addEventListener: (type, callback) => handlers.set(`form:${type}`, callback),
};
const button = {
  closest: () => form,
  focus: () => { button.focused = true; },
  addEventListener: (type, callback) => handlers.set(`button:${type}`, callback),
};
const document = {
  querySelector: (selector) => ({ '[data-gls-open]': button, '[data-gls-dialog]': dialog })[selector],
};
const window = { addEventListener: (type, callback) => handlers.set(`window:${type}`, callback) };
runInNewContext(readFileSync(new URL('../assets/gls-checkout.js', import.meta.url), 'utf8'),
  { document, window, TextEncoder });

handlers.get('button:click')();
assert.equal(dialog.open, true);
assert.equal(radio.checked, true);
assert.match(frame.src, /ctrcode=CZ/);

const message = (origin, source, detail) => handlers.get('window:message')({
  origin, source, data: { parcelshop: { detail } },
});
const point = { pclshopid: '26711-GLSCZ_DEPO47', name: 'GLS ParcelShop Brno',
  address: 'Nádražní 12', city: 'Brno', zipcode: '60200', ctrcode: 'CZ' };
message('https://example.invalid', frame.contentWindow, point);
assert.equal(id.value, '');
message('https://maps.gls-czech.cz', {}, point);
assert.equal(id.value, '');
message('https://maps.gls-czech.cz', frame.contentWindow, { ...point, ctrcode: 'AT' });
assert.equal(id.value, '');
assert.match(dialogStatus.textContent, /české/);

message('https://maps.gls-czech.cz', frame.contentWindow, point);
assert.equal(id.value, '26711-GLSCZ_DEPO47');
assert.equal(name.value, 'GLS ParcelShop Brno');
assert.equal(address.value, 'Nádražní 12, Brno, 60200');
assert.equal(country.value, 'CZ');
assert.equal(dialog.open, false);
assert.match(selection.textContent, /GLS ParcelShop Brno/);

let prevented = false;
handlers.get('form:submit')({ preventDefault: () => { prevented = true; } });
assert.equal(prevented, false);
id.value = '';
handlers.get('form:submit')({ preventDefault: () => { prevented = true; } });
assert.equal(prevented, true);
assert.equal(button.focused, true);
console.log('GLS checkout map tests passed.');
