import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

function gallery(count) {
  const element = () => ({
    dataset: {}, handlers: {}, attributes: {}, src: '/full-0.webp', value: 1,
    addEventListener(type, fn) { (this.handlers[type] ??= []).push(fn); },
    emit(type, extra = {}) { for (const fn of this.handlers[type] ?? []) fn({ target: this, ...extra }); },
    setAttribute(key, value) { this.attributes[key] = value; },
    removeAttribute(key) { delete this[key]; },
    showModal() { this.open = true; },
    close() { this.open = false; this.emit('close'); },
  });
  const ids = Object.fromEntries(['qty', 'minus', 'plus', 'detail-image', 'detail-image-open', 'detail-lightbox', 'detail-lightbox-image', 'detail-lightbox-close', 'detail-lightbox-previous', 'detail-lightbox-next', 'detail-lightbox-counter'].map(id => [id, element()]));
  const thumbs = Array.from({ length: count }, (_, i) => Object.assign(element(), { dataset: { galleryImage: `/full-${i}.webp` } }));
  vm.runInNewContext(readFileSync(new URL('../assets/product.js', import.meta.url), 'utf8'), { document: { getElementById: id => ids[id], querySelectorAll: () => thumbs } });
  return { ids, thumbs };
}

test('opens selected full-size image, cycles both ways and keeps thumbnails in sync', () => {
  const { ids, thumbs } = gallery(3);
  thumbs[1].emit('click');
  ids['detail-image-open'].emit('click');
  assert.equal(ids['detail-lightbox-image'].src, '/full-1.webp');
  ids['detail-lightbox-next'].emit('click');
  assert.equal(ids['detail-lightbox-image'].src, '/full-2.webp');
  ids['detail-lightbox-next'].emit('click');
  assert.equal(ids['detail-lightbox-image'].src, '/full-0.webp');
  let prevented = false;
  ids['detail-lightbox'].emit('keydown', { key: 'ArrowLeft', preventDefault() { prevented = true; } });
  assert.ok(prevented);
  assert.equal(ids['detail-lightbox-image'].src, '/full-2.webp');
  assert.equal(thumbs[2].attributes['aria-pressed'], 'true');
  assert.equal(thumbs[0].attributes['aria-pressed'], 'false');
  assert.equal(ids['detail-lightbox-counter'].textContent, '3 / 3');
  ids['detail-lightbox-close'].emit('click');
  assert.equal(ids['detail-lightbox-image'].src, undefined);
  ids['detail-image-open'].emit('click');
  assert.equal(ids['detail-lightbox-image'].src, '/full-2.webp');
});

test('single image without thumbnails opens and closes without navigation', () => {
  const { ids } = gallery(0);
  assert.ok(ids['detail-lightbox-next'].hidden);
  assert.ok(ids['detail-lightbox-previous'].hidden);
  ids['detail-image-open'].emit('click');
  ids['detail-lightbox'].emit('keydown', { key: 'ArrowRight', preventDefault() {} });
  assert.equal(ids['detail-lightbox-image'].src, '/full-0.webp');
  ids['detail-lightbox'].emit('click');
  assert.equal(ids['detail-lightbox'].open, false);
});
