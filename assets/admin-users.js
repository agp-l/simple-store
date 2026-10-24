(() => {
  if (!window.crypto?.getRandomValues) return;
  document.querySelectorAll('[data-account-password]').forEach(group => {
    const input = group.querySelector('input[name="password"]');
    const status = group.querySelector('[role="status"]');
    group.querySelectorAll('button[type="button"]').forEach(button => { button.hidden = false; });
    group.querySelector('[data-generate-password]').addEventListener('click', () => {
      // 24 independent, unbiased selections from a 64-character alphabet (144 bits).
      const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
      input.value = Array.from(crypto.getRandomValues(new Uint8Array(24)), byte => alphabet[byte & 63]).join('');
      input.type = 'text';
      group.querySelector('[data-show-password]').textContent = 'Skrýt';
      status.textContent = 'Heslo je připravené. Pro změnu účtu je ještě ulož.';
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    group.querySelector('[data-show-password]').addEventListener('click', event => {
      input.type = input.type === 'password' ? 'text' : 'password';
      event.currentTarget.textContent = input.type === 'password' ? 'Zobrazit' : 'Skrýt';
    });
    group.querySelector('[data-copy-password]').addEventListener('click', async () => {
      if (!input.value) { status.textContent = 'Nejdřív zadej nebo vygeneruj heslo.'; return; }
      try {
        await navigator.clipboard.writeText(input.value);
        status.textContent = 'Heslo zkopírováno.';
      } catch {
        input.type = 'text'; input.focus(); input.select();
        group.querySelector('[data-show-password]').textContent = 'Skrýt';
        status.textContent = 'Heslo je označené. Zkopíruj je pomocí Ctrl+C nebo nabídky telefonu.';
      }
    });
  });
})();
