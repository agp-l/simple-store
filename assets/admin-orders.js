(() => {
  'use strict';

  async function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(value);
        return;
      } catch {
        // A browser may deny Clipboard API access while still allowing a user-triggered copy.
      }
    }

    const focused = document.activeElement;
    const selection = window.getSelection();
    const ranges = selection ? Array.from({ length: selection.rangeCount }, (_, i) => selection.getRangeAt(i).cloneRange()) : [];
    const field = document.createElement('textarea');
    field.value = value;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.append(field);
    try {
      field.select();
      if (!document.execCommand('copy')) throw new Error('Kopírování není v tomto prohlížeči dostupné.');
    } finally {
      field.remove();
      focused?.focus({ preventScroll: true });
      if (selection) {
        selection.removeAllRanges();
        ranges.forEach(range => selection.addRange(range));
      }
    }
  }

  const resetTimers = new WeakMap();
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-order-copy]');
    if (!button || button.getAttribute('aria-busy') === 'true') return;

    const text = button.getAttribute('data-order-copy');
    if (!text) return;

    const feedback = button.closest('.panel-panel')?.querySelector('.panel-order-copy-feedback');
    const idle = button.querySelector('.order-copy-idle');
    const done = button.querySelector('.order-copy-done');
    clearTimeout(resetTimers.get(button));
    if (idle) idle.hidden = false;
    if (done) done.hidden = true;
    button.removeAttribute('data-copied');
    button.setAttribute('aria-busy', 'true');
    try {
      await copyText(text);
      const label = button.getAttribute('data-order-copy-label');
      if (feedback) feedback.textContent = label ? `Zkopírováno: ${label}.` : 'Údaje byly zkopírovány.';
      button.setAttribute('data-copied', 'true');
      if (idle) idle.hidden = true;
      if (done) done.hidden = false;
      resetTimers.set(button, setTimeout(() => {
        button.removeAttribute('data-copied');
        if (idle) idle.hidden = false;
        if (done) done.hidden = true;
      }, 2500));
    } catch (error) {
      if (feedback) feedback.textContent = 'Kopírování selhalo. Označ a zkopíruj údaje z přehledu ručně.';
    } finally {
      button.removeAttribute('aria-busy');
    }
  });
})();
