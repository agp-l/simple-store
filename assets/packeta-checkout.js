/* The hosted Packeta widget handles the map; the server resolves the chosen ID. */
(() => {
  const block = document.querySelector('.checkout-packeta-fields');
  const button = document.querySelector('[data-packeta-open]');
  if (!block || !button) return;

  const form = button.closest('form');
  const radio = form.querySelector('input[name="method"][value="zasilkovna_pickup"]');
  const id = block.querySelector('[data-packeta-id]');
  const selection = block.querySelector('[data-packeta-selection]');
  const status = block.querySelector('[data-packeta-status]');
  const options = JSON.parse(block.dataset.packetaOptions);

  button.addEventListener('click', () => {
    radio.checked = true;
    if (!window.Packeta || !Packeta.Widget || typeof Packeta.Widget.pick !== 'function') {
      status.textContent = 'Mapa Zásilkovny se nenačetla. Zkontrolujte připojení a zkuste to znovu.';
      return;
    }
    status.textContent = '';
    try {
      Packeta.Widget.pick(block.dataset.packetaKey, (point) => {
        if (!point) return;
        const code = String(point.id || '');
        if (!/^[A-Za-z0-9_-]{1,80}$/.test(code) || String(point.country).toLowerCase() !== 'cz') {
          id.value = '';
          selection.textContent = 'Místo zatím není vybrané. Použijte tlačítko u Zásilkovny.';
          status.textContent = 'Vyberte prosím české výdejní místo Zásilkovny.';
          return;
        }
        id.value = code;
        selection.textContent = `${point.name || 'Výdejní místo'} · ${point.street || ''}, ${point.city || ''}, ${point.zip || ''} (ID ${code})`;
        status.textContent = 'Místo bylo vybráno. Pokračujte k platbě.';
      }, options);
    } catch (_) {
      status.textContent = 'Mapu nelze otevřít. Zkontrolujte nastavení Zásilkovny nebo to zkuste později.';
    }
  });

  form.addEventListener('submit', (event) => {
    if (radio.checked && !id.value) {
      event.preventDefault();
      status.textContent = 'Nejdřív vyberte výdejní místo v mapě Zásilkovny.';
      button.focus();
    }
  });
})();
