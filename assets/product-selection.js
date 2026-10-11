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
        announce('Pořadí uloženo.');
      } catch (error) {
        restore(); announce(error instanceof SyntaxError ? 'Server změnu nepotvrdil. Obnov stránku a zkontroluj přihlášení.' : error.message, true);
      } finally { busy = false; list.removeAttribute('aria-busy'); }
    }
    [...list.children].forEach((item) => {
      const handle = item.querySelector('.selection-drag');
      if (!handle) return;
      handle.draggable = true;
      handle.addEventListener('dragstart', (event) => {
        if (busy) { event.preventDefault(); return; }
        dragged = item; item.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', item.dataset.key);
      });
      handle.addEventListener('dragend', () => {
        item.classList.remove('is-dragging');
        if (dragged) restore();
        dragged = null;
      });
      handle.addEventListener('keydown', (event) => {
        if (busy || !event.altKey || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();
        if (event.key === 'ArrowUp' && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
        if (event.key === 'ArrowDown' && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
        handle.focus(); save();
      });
    });
    list.addEventListener('dragover', (event) => {
      if (!dragged || busy) return;
      event.preventDefault();
      const target = event.target.closest('li[data-key]');
      if (!target || target === dragged || target.parentElement !== list) return;
      const rect = target.getBoundingClientRect();
      list.insertBefore(dragged, event.clientY < rect.top + rect.height / 2 ? target : target.nextSibling);
    });
    list.addEventListener('drop', (event) => {
      if (!dragged || busy) return;
      event.preventDefault(); dragged.classList.remove('is-dragging'); dragged = null; save();
    });
  });
})();
