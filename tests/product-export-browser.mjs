import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';

const script = readFileSync(new URL('../assets/product-export.js', import.meta.url), 'utf8');
test('select-all and individual deselection serialize over 1000 products into one field', () => {
  const control = (value = '') => ({value, checked: false, disabled: false, handlers: {}, addEventListener(name, handler) { this.handlers[name] = handler; }});
  const choices = Array.from({length: 1002}, (_, index) => control(index.toString(16).padStart(32, '0') + ':cs'));
  const all = control(), json = control(), count = {}, button = control();
  const form = {querySelectorAll: () => choices, querySelector: selector => ({'[data-select-all]': all, '[name="selected_json"]': json, '[data-selection-count]': count, '[data-export-selected]': button})[selector], addEventListener() {}};
  vm.runInNewContext(script, {document: {getElementById: () => form}});
  assert.equal(button.disabled, true);
  all.checked = true; all.handlers.change();
  assert.equal(JSON.parse(json.value).length, 1002);
  choices[7].checked = false; choices[7].handlers.change();
  assert.equal(count.textContent, 1001);
  assert.equal(all.indeterminate, true);
  assert.equal(JSON.parse(json.value).includes(choices[7].value), false);
  assert.equal(button.disabled, false);
  all.checked = false; all.handlers.change();
  assert.equal(json.value, '[]');
  assert.equal(button.disabled, true);
});
