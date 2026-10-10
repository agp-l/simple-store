import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../assets/order-link.js', import.meta.url), 'utf8');

function page({ origin = 'https://shop.example.test', path = '/cs/objednavka/token',
  clipboard = null, secure = true, fallback = true } = {}) {
  let click;
  const copied = [];
  const field = { value: '/old-path', dataset: { orderPath: path },
    focus() {}, select() { this.selected = true; }, setSelectionRange() {} };
  const button = { hidden: true, addEventListener(type, handler) { click = handler; } };
  const feedback = { textContent: '' };
  const container = { querySelector: selector => ({ '[data-order-path]': field,
    '[data-copy-order-link]': button, '[data-copy-feedback]': feedback })[selector] };
  runInNewContext(source, { URL, navigator: { clipboard },
    window: { location: { origin }, isSecureContext: secure },
    document: { querySelectorAll: () => [container],
      execCommand() { copied.push(field.value); return fallback; } } });
  return { field, button, feedback, copied, click: () => click() };
}

test('shows and copies the full URL on the current hosting origin', async () => {
  const copied = [];
  const view = page({ clipboard: { async writeText(value) { copied.push(value); } } });
  assert.equal(view.field.value, 'https://shop.example.test/cs/objednavka/token');
  assert.equal(view.button.hidden, false);
  await view.click();
  assert.deepEqual(copied, [view.field.value]);
  assert.equal(view.feedback.textContent, 'Odkaz byl zkopírován.');
  assert.equal(view.button.disabled, false);
});

test('retains localhost port and installation directory with a copy fallback', async () => {
  const view = page({ origin: 'http://localhost:8080', path: '/simple-store/cs/objednavka/token', secure: false });
  await view.click();
  assert.equal(view.field.value, 'http://localhost:8080/simple-store/cs/objednavka/token');
  assert.deepEqual(view.copied, [view.field.value]);
});

test('falls back after denied clipboard access and explains failed copying', async () => {
  const view = page({ clipboard: { async writeText() { throw new Error('Denied'); } }, fallback: false });
  await view.click();
  assert.equal(view.field.selected, true);
  assert.equal(view.feedback.textContent, 'Zkopírujte označený odkaz ručně.');
  assert.equal(view.button.disabled, false);
});

test('does not enable copying a path leading to another origin', () => {
  const view = page({ path: '//foreign.example.test/order' });
  assert.equal(view.button.hidden, true);
});
