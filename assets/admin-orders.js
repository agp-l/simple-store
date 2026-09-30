(() => {
  'use strict';

  async function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(value);
      return;
    }

    const field = document.createElement('textarea');
    field.value = value;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.append(field);
    field.select();
    const copied = document.execCommand('copy');
    field.remove();
    if (!copied) throw new Error('Kopírování není v tomto prohlížeči dostupné.');
  }

  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-order-copy]');
    if (!button) return;

    const text = button.getAttribute('data-order-copy');
    if (!text) return;

    const feedback = button.closest('.panel-panel').querySelector('.panel-order-copy-feedback');
    button.disabled = true;
    try {
      await copyText(text);
      if (feedback) feedback.textContent = 'Údaje byly zkopírovány.';
    } catch (error) {
      if (feedback) feedback.textContent = 'Kopírování selhalo. Označ a zkopíruj údaje z přehledu ručně.';
    } finally {
      button.disabled = false;
    }
  });
})();
