/* GLS ShopDeliveryService sends a selected parcelshop from its map iframe. */
(() => {
  const button = document.querySelector('[data-gls-open]');
  const dialog = document.querySelector('[data-gls-dialog]');
  if (!button || !dialog) return;

  const form = button.closest('form');
  const radio = form.querySelector('input[name="method"][value="gls_pickup"]');
  const frame = dialog.querySelector('[data-gls-map]');
  const close = dialog.querySelector('[data-gls-close]');
  const dialogStatus = dialog.querySelector('[data-gls-dialog-status]');
  const id = form.querySelector('[data-gls-id]');
  const name = form.querySelector('[data-gls-name]');
  const address = form.querySelector('[data-gls-address]');
  const country = form.querySelector('[data-gls-country]');
  const selection = form.querySelector('[data-gls-selection]');
  const status = form.querySelector('[data-gls-status]');
  const allowedOrigins = ['https://maps.gls-czech.cz', 'https://ps-maps.gls-czech.cz'];
  const value = (field) => typeof field === 'string' ? field.trim() : '';
  const bytes = (field) => new TextEncoder().encode(field).length;

  button.addEventListener('click', () => {
    radio.checked = true;
    if (typeof dialog.showModal !== 'function') {
      status.textContent = 'Tento prohlížeč neumí otevřít mapu GLS. Použijte prosím aktuální prohlížeč.';
      return;
    }
    dialogStatus.textContent = '';
    status.textContent = '';
    if (!frame.src) frame.src = frame.dataset.src;
    dialog.showModal();
  });
  close.addEventListener('click', () => dialog.close());

  window.addEventListener('message', (event) => {
    if (!dialog.open || !allowedOrigins.includes(event.origin) || event.source !== frame.contentWindow) return;
    const detail = event.data && typeof event.data === 'object' ? event.data.parcelshop?.detail : null;
    if (!detail || typeof detail !== 'object') return;

    const pointId = value(detail.pclshopid);
    const pointName = value(detail.name);
    const street = value(detail.address);
    const city = value(detail.city);
    const zip = value(detail.zipcode);
    const pointCountry = value(detail.ctrcode).toUpperCase();
    const pointAddress = `${street}, ${city}, ${zip}`;
    if (!/^[A-Za-z0-9_-]{3,80}$/.test(pointId) || !pointName || !street || !city || !zip ||
        pointCountry !== 'CZ' || bytes(pointName) > 190 || bytes(pointAddress) > 190) {
      dialogStatus.textContent = 'Vybrané místo GLS nemá úplné české údaje. Zvolte prosím jiné místo.';
      return;
    }
    radio.checked = true;
    id.value = pointId;
    name.value = pointName;
    address.value = pointAddress;
    country.value = pointCountry;
    selection.textContent = `${pointName} · ${pointAddress} (ID ${pointId})`;
    status.textContent = 'Místo bylo vybráno. Pokračujte k platbě.';
    dialog.close();
  });

  form.addEventListener('submit', (event) => {
    if (radio.checked && (!/^[A-Za-z0-9_-]{3,80}$/.test(id.value) || !name.value ||
        !address.value || country.value !== 'CZ')) {
      event.preventDefault();
      status.textContent = 'Nejdřív vyberte výdejní místo v mapě GLS.';
      button.focus();
    }
  });
})();
