'use strict';
document.querySelector('[data-copy-product-prompt]')?.addEventListener('click', async () => {
    const input = document.getElementById('ai-product-instructions');
    const status = document.querySelector('[data-copy-product-status]');
    if (!input || !status) return;
    try {
        await navigator.clipboard.writeText(input.value);
        status.textContent = 'Zkopírováno včetně kategorií.';
    } catch {
        input.focus();
        input.select();
        status.textContent = 'Text je vybraný. Zkopíruj jej pomocí Ctrl+C nebo ⌘C.';
    }
});
