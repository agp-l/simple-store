(() => {
  'use strict';
  document.querySelectorAll('[data-product-selection]').forEach((list) => {
    let dragged = null;
    let busy = false;
    const keys = () => [...list.children].map((item) => item.dataset.key);
    let saved = keys();
    const status = list.parentElement.querySelector('.selection-order-status');
    const announce = (message, error = false) => {
      if (status) { status.textContent = message; status.classList.toggle('is-error', error); }
    };
    const restore = () => {
      const items = new Map([...list.children].map((item) => [item.dataset.key, item]));
      saved.forEach((key) => list.append(items.get(key)));
    };
    async function save() {
      const order = keys();
      if (busy || JSON.stringify(order) === JSON.stringify(saved)) return;
      busy = true; list.setAttribute('aria-busy', 'true'); announce('Ukládám pořadí…');
      try {
        const body = new URLSearchParams({action: 'product-selection-order', csrf: list.dataset.csrf,
          language: list.dataset.language, scope: list.dataset.scope,
          order: JSON.stringify(order), expected: JSON.stringify(saved)});
        const response = await fetch(list.dataset.endpoint, {method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'}});
        const data = await response.json();
        if (!response.ok || data.saved !== true) throw new Error(data.error || 'Pořadí nebylo potvrzeno. Obnov stránku.');
        saved = order;
        const catalog = document.getElementById('catalog');
        if (catalog) {
          const cards = new Map([...catalog.querySelectorAll('[data-product-key]')].map((card) => [card.dataset.productKey, card]));
          [...order].reverse().forEach((key) => { if (cards.has(key)) catalog.prepend(cards.get(key)); });
        }
        announce('Pořadí uloženo.');
      } catch (error) {
        restore(); announce(error instanceof SyntaxError ? 'Server změnu nepotvrdil. Obnov stránku a zkontroluj přihlášení.' : error.message, true);
      } finally { busy = false; list.removeAttribute('aria-busy'); }
    }
    [...list.children].forEach((item) => {
      const handle = item.querySelector('.selection-drag');
      if (!handle) return;
      let pointer = null;
      handle.addEventListener('pointerdown', (event) => {
        if (busy || dragged || event.button !== 0) return;
        event.preventDefault();
        pointer = event.pointerId; dragged = item;
        handle.setPointerCapture(pointer); handle.focus(); item.classList.add('is-dragging');
      });
      handle.addEventListener('pointermove', (event) => {
        if (pointer !== event.pointerId || dragged !== item) return;
        const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('li[data-key]');
        if (!target || target === item || target.parentElement !== list) return;
        const rect = target.getBoundingClientRect();
        list.insertBefore(item, event.clientY < rect.top + rect.height / 2 ? target : target.nextSibling);
      });
      handle.addEventListener('pointerup', (event) => {
        if (pointer !== event.pointerId) return;
        pointer = null; dragged = null; item.classList.remove('is-dragging');
        handle.releasePointerCapture(event.pointerId); save();
      });
      const cancel = () => {
        if (pointer === null) return;
        pointer = null; dragged = null; item.classList.remove('is-dragging'); restore();
      };
      handle.addEventListener('pointercancel', cancel);
      handle.addEventListener('lostpointercapture', cancel);
      handle.addEventListener('keydown', (event) => {
        if (busy || !event.altKey || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();
        if (event.key === 'ArrowUp' && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
        if (event.key === 'ArrowDown' && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
        handle.focus(); save();
      });
    });
  });
})();
