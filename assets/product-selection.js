(() => {
  'use strict';
  document.querySelectorAll('[data-product-selection]').forEach((list) => {
    let dragged = null;
    let pointer = null;
    let busy = false;
    const keys = () => [...list.children].map((item) => item.dataset.key);
    let saved = keys();
    const status = list.parentElement.querySelector('.selection-order-status');
    const announce = (message, error = false) => {
      if (status) { status.textContent = message; status.classList.toggle('is-error', error); }
    };
    const refreshPositions = () => {
      [...list.children].forEach((item, index) => {
        const position = item.querySelector('[data-selection-position]');
        if (position) position.textContent = String(index + 1);
        item.querySelectorAll('[data-selection-move]').forEach((button) => {
          const edge = button.dataset.selectionMove === 'up' ? index === 0 : index === list.children.length - 1;
          button.setAttribute('aria-disabled', String(busy || edge));
        });
      });
    };
    const restore = () => {
      const items = new Map([...list.children].map((item) => [item.dataset.key, item]));
      saved.forEach((key) => list.append(items.get(key)));
      refreshPositions();
    };
    async function save() {
      const order = keys();
      if (busy || JSON.stringify(order) === JSON.stringify(saved)) return;
      busy = true; list.setAttribute('aria-busy', 'true'); announce('Ukládám pořadí…');
      refreshPositions();
      try {
        const body = new URLSearchParams({action: 'product-selection-order', csrf: list.dataset.csrf,
          language: list.dataset.language, scope: list.dataset.scope,
          order: JSON.stringify(order), expected: JSON.stringify(saved)});
        const response = await fetch(list.dataset.endpoint, {method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'}});
        const data = await response.json();
        if (!response.ok || data.saved !== true) throw new Error(data.error || 'Pořadí nebylo potvrzeno. Obnov stránku.');
        saved = order;
        const catalog = document.getElementById('catalog');
        if (catalog && list.dataset.scope === 'home') {
          const cards = new Map([...catalog.querySelectorAll('[data-product-key]')].map((card) => [card.dataset.productKey, card]));
          [...order].reverse().forEach((key) => { if (cards.has(key)) catalog.prepend(cards.get(key)); });
        }
        announce('Pořadí uloženo.');
        if (catalog && list.dataset.scope !== 'home' && document.getElementById('sort')?.value === 'random') {
          // A changed recommendation order invalidates pagination, including unloaded cards.
          const next = new URL(location.href);
          next.searchParams.delete('offset');
          next.hash = 'produkty';
          location.assign(next.href);
        }
      } catch (error) {
        restore(); announce(error instanceof SyntaxError ? 'Server změnu nepotvrdil. Obnov stránku a zkontroluj přihlášení.' : error.message, true);
      } finally { busy = false; list.removeAttribute('aria-busy'); refreshPositions(); }
    }
    function move(item, direction) {
      if (busy || dragged) return;
      if (direction === 'up' && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
      if (direction === 'down' && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
      refreshPositions(); save();
    }
    [...list.children].forEach((item) => {
      item.querySelectorAll('[data-selection-move]').forEach((button) => {
        button.addEventListener('click', (event) => {
          event.preventDefault();
          move(item, button.dataset.selectionMove);
          button.focus();
        });
      });
      const handle = item.querySelector('.selection-drag');
      if (!handle) return;
      handle.addEventListener('pointerdown', (event) => {
        if (busy || dragged || event.button !== 0) return;
        event.preventDefault();
        pointer = event.pointerId; dragged = item;
        // Capture belongs to the stationary list, never to a row moved in the DOM.
        list.setPointerCapture(pointer); handle.focus(); item.classList.add('is-dragging');
      });
      handle.addEventListener('keydown', (event) => {
        if (!event.altKey || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();
        move(item, event.key === 'ArrowUp' ? 'up' : 'down');
        handle.focus();
      });
    });
    refreshPositions();
    list.addEventListener('submit', (event) => {
      // Removing a row during an outstanding reorder would leave the preview stale.
      if (busy || dragged) event.preventDefault();
    });
    list.addEventListener('pointermove', (event) => {
      if (pointer !== event.pointerId || !dragged) return;
      const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('li[data-key]');
      if (!target || target === dragged || target.parentElement !== list) return;
      const rect = target.getBoundingClientRect();
      list.insertBefore(dragged, event.clientY < rect.top + rect.height / 2 ? target : target.nextSibling);
      refreshPositions();
    });
    list.addEventListener('pointerup', (event) => {
      if (pointer !== event.pointerId || !dragged) return;
      pointer = null; dragged.classList.remove('is-dragging'); dragged = null;
      list.releasePointerCapture(event.pointerId); save();
    });
    const cancel = () => {
      if (pointer === null) return;
      const captured = pointer;
      pointer = null;
      if (dragged) dragged.classList.remove('is-dragging');
      dragged = null; restore();
      if (list.hasPointerCapture(captured)) list.releasePointerCapture(captured);
    };
    list.addEventListener('pointercancel', cancel);
    list.addEventListener('lostpointercapture', (event) => {
      if (event.target === list && event.pointerId === pointer) cancel();
    });
  });
})();
