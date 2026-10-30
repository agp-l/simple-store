(() => {
  const input = document.getElementById('google-client-secret');
  const toggle = document.querySelector('[data-google-secret-toggle]');
  if (!input || !toggle) return;
  toggle.hidden = false;
  toggle.addEventListener('click', () => {
    const hidden = input.type === 'text';
    input.type = hidden ? 'password' : 'text';
    toggle.textContent = hidden ? 'Zobrazit klíč' : 'Skrýt klíč';
    toggle.setAttribute('aria-pressed', String(hidden));
  });
})();
