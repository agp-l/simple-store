import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const handlers = new Map();
const field = (value = '') => ({ value });
const code = field();
const name = field();
const address = field();
const country = field();
const selection = { textContent: '' };
const status = { textContent: '' };
const radio = { checked: false };
const fields = {
  'input[name="method"][value="ppl_pickup"]': radio,
  '[data-ppl-code]': code, '[data-ppl-name]': name,
  '[data-ppl-address]': address, '[data-ppl-country]': country,
  '[data-ppl-selection]': selection, '[data-ppl-status]': status,
};
const form = {
  querySelector: (selector) => fields[selector],
  addEventListener: (event, callback) => handlers.set(`form:${event}`, callback),
};
const widget = {
  open: () => { widget.opened = true; },
  addEventListener: (event, callback) => handlers.set(event, callback),
};
const button = {
  closest: () => form,
  focus: () => { button.focused = true; },
  addEventListener: (event, callback) => handlers.set(`button:${event}`, callback),
};
const document = {
  querySelector: (selector) => ({ '[data-ppl-widget]': widget, '[data-ppl-open]': button })[selector],
};
runInNewContext(readFileSync(new URL('../assets/ppl-checkout.js', import.meta.url), 'utf8'), { document });
handlers.get('button:click')();
assert.equal(widget.opened, true);
assert.equal(radio.checked, true);

handlers.get('ppl-accesspointwidget-select')({ detail: {
  code: 'KM1234567', name: 'ParcelBox Brno', street: 'Nádražní 12',
  city: 'Brno', zipCode: '60200', country: 'CZ',
} });
assert.equal(code.value, 'KM1234567');
assert.equal(address.value, 'Nádražní 12, Brno, 60200');
assert.equal(name.value, 'ParcelBox Brno');
assert.equal(country.value, 'CZ');
assert.match(selection.textContent, /ParcelBox Brno/);

handlers.get('ppl-accesspointwidget-select')({ detail: {
  code: 'KM9876543', name: 'ParcelShop Praha',
  address: { street: 'Vinohradská 1', city: 'Praha', postalCode: '12000' },
} });
assert.equal(address.value, 'Vinohradská 1, Praha, 12000');

handlers.get('ppl-accesspointwidget-select')({ detail: {
  code: 'KM1234567', name: 'Foreign', address: 'Main Street, Wien', country: 'AT',
} });
assert.equal(code.value, '');
let prevented = false;
handlers.get('form:submit')({ preventDefault: () => { prevented = true; } });
assert.equal(prevented, true);
assert.equal(button.focused, true);
console.log('PPL checkout widget tests passed.');
