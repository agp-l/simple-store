(() => {
  'use strict';

  document.querySelectorAll('[data-order-link]').forEach(container => {
    const field = container.querySelector('[data-order-path]');
    const button = container.querySelector('[data-copy-order-link]');
    const feedback = container.querySelector('[data-copy-feedback]');
    if (!field || !button || !feedback) return;

    // Use the origin actually visited, including localhost ports and subdirectories.
    const link = new URL(field.dataset.orderPath, window.location.origin);
    if (link.origin !== window.location.origin) return;
    field.value = link.href;
    button.hidden = false;

    const copySelection = () => {
      field.focus();
      field.select();
      field.setSelectionRange(0, field.value.length);
      if (!document.execCommand('copy')) throw new Error('Clipboard unavailable');
    };

    button.addEventListener('click', async () => {
      button.disabled = true;
      feedback.textContent = '';
      try {
        if (navigator.clipboard && window.isSecureContext) {
          try {
            await navigator.clipboard.writeText(field.value);
          } catch (error) {
            copySelection();
          }
        } else {
          copySelection();
        }
        feedback.textContent = 'Odkaz byl zkopírován.';
      } catch (error) {
        field.focus();
        field.select();
        feedback.textContent = 'Zkopírujte označený odkaz ručně.';
      } finally {
        button.disabled = false;
      }
    });
  });
})();
