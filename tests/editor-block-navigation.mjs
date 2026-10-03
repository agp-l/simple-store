import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

async function verify(name, file, configId, actionName, blockSelector, keyPrefix) {
  const key = 'a'.repeat(32);
  const current = `https://shop.test/cs/${name === 'product' ? 'produkt/' : 'blog/'}boty?edit=1#produkty`;
  const storage = new Map();
  const sessionStorage = {
    getItem: key => storage.has(key) ? storage.get(key) : null,
    setItem: (key, value) => storage.set(key, value),
    removeItem: key => storage.delete(key),
  };
  let reloaded = 0;
  let assigned = '';
  let focused = false;
  let scrolled = false;
  let timers = [];
  const block = {
    focus: () => { focused = true; },
    scrollIntoView: () => { scrolled = true; },
  };

  const load = (revision, count) => {
    const clicks = [];
    const location = {
      href: current, pathname: new URL(current).pathname,
      search: '?edit=1', hash: '#produkty',
      reload: () => { reloaded++; },
      assign: url => { assigned = url; },
    };
    const status = { textContent: '', classList: { add() {} } };
    const config = { key, revision, csrf: 'csrf', endpoint: '/admin.php',
      language: 'cs', type: name };
    const document = {
      getElementById: id => id === configId ? { textContent: JSON.stringify(config) } :
        id.endsWith('status') ? status : null,
      querySelectorAll: () => Array(count).fill({}),
      querySelector: selector => selector.includes(`${blockSelector}[data-section="2"]`) ? block : null,
      addEventListener: (event, callback) => {
        if (event === 'click') clicks.push(callback);
      },
      activeElement: null,
    };
    const window = { scrollY: 420, scrollTo() {}, addEventListener() {} };
    const context = { document, window, location, sessionStorage, URL, URLSearchParams,
      requestAnimationFrame: callback => callback(),
      setTimeout: callback => { timers.push(callback); return timers.length; },
      clearTimeout: () => { timers = []; },
      fetch: async () => ({ ok: true, json: async () => ({ revision: revision + 1,
        url: new URL(current).pathname + '?edit=1' }) }),
    };
    runInNewContext(readFileSync(new URL(`../assets/${file}`, import.meta.url), 'utf8'), context);
    return clicks;
  };

  const clicks = load(1, 2);
  const button = {
    dataset: { [actionName]: 'section-add',
      [name === 'product' ? 'editorValue' : 'value']: 'text',
      [name === 'product' ? 'editorIndex' : 'index']: '1' },
    hasAttribute: () => true,
  };
  for (const click of clicks) click({ target: { closest: selector =>
    selector === `[data-${name === 'product' ? 'editor' : 'content'}-action]` ? button : null } });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(timers.length, 1, `${name}: save did not schedule navigation`);
  timers.shift()();
  assert.equal(reloaded, 1, `${name}: a successful edit must reload the current URL`);
  assert.equal(assigned, '', `${name}: same-URL assignment must not replace reload`);
  assert.equal(storage.get(`${keyPrefix}-block-${key}`), '2', JSON.stringify([...storage]));

  load(2, 3);
  assert.equal(focused, true, `${name}: newly inserted text must be ready to edit`);
  assert.equal(scrolled, true, `${name}: the page must show the inserted block`);
}

await verify('product', 'inline-editor.js', 'inline-editor-config', 'editorAction',
  '.product-content-block', 'dobrodruzi-editor');
await verify('post', 'content-editor.js', 'content-editor-config', 'contentAction',
  '.cms-block', 'dobrodruzi-content');
console.log('Editor block navigation passed.');
