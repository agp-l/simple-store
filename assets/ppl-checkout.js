/* PPL owns the map; checkout only copies the selected point into the form. */
(() => {
  const widget = document.querySelector('[data-ppl-widget]');
  const button = document.querySelector('[data-ppl-open]');
  if (!widget || !button) return;

  const form = button.closest('form');
  const radio = form.querySelector('input[name="method"][value="ppl_pickup"]');
  const code = form.querySelector('[data-ppl-code]');
  const name = form.querySelector('[data-ppl-name]');
  const address = form.querySelector('[data-ppl-address]');
  const country = form.querySelector('[data-ppl-country]');
  const selection = form.querySelector('[data-ppl-selection]');
  const status = form.querySelector('[data-ppl-status]');

  const string = (value) => typeof value === 'string' ? value.trim() : '';
  const first = (...values) => values.map(string).find(Boolean) || '';
  const clear = () => {
    code.value = name.value = address.value = country.value = '';
    selection.textContent = 'Místo zatím není vybrané. Použijte tlačítko u PPL.';
  };

  button.addEventListener('click', () => {
    radio.checked = true;
    if (typeof widget.open !== 'function') {
      status.textContent = 'Mapa PPL se nenačetla. Zkontrolujte připojení nebo nastavení klíče a zkuste to znovu.';
      return;
    }
    status.textContent = '';
    try {
      widget.open();
    } catch (_) {
      status.textContent = 'Mapu PPL nelze otevřít. Zkontrolujte nastavení klíče a zkuste to znovu.';
    }
  });

  widget.addEventListener('ppl-accesspointwidget-select', (event) => {
    const point = event.detail || {};
    const pointCode = first(point.code, point.id);
    const place = first(point.name, point.title, point.accessPointName);
    const location = point.address && typeof point.address === 'object' ? point.address : {};
    const street = first(location.street, point.street);
    const city = first(location.city, point.city);
    const postalCode = first(location.zipCode, location.postalCode, location.zip,
      point.zipCode, point.postalCode, point.zip);
    const pointAddress = first(typeof point.address === 'string' ? point.address : '',
      [street, city, postalCode].filter(Boolean).join(', '));
    const pointCountry = first(location.countryCode, location.country, point.countryCode, point.country, 'CZ').toUpperCase();

    if (!/^KM[0-9]{7}$/.test(pointCode) || !place || !pointAddress || pointCountry !== 'CZ' ||
        place.length > 190 || pointAddress.length > 190) {
      clear();
      status.textContent = 'Vybrané místo nemá úplnou adresu nebo český KM kód. Vyberte prosím jiné místo v mapě PPL.';
      return;
    }
    radio.checked = true;
    code.value = pointCode;
    name.value = place;
    address.value = pointAddress;
    country.value = pointCountry;
    selection.textContent = `${place} · ${pointAddress} (${pointCode})`;
    status.textContent = 'Místo bylo vybráno. Pokračujte k platbě.';
  });

  widget.addEventListener('ppl-accesspointwidget-error', () => {
    status.textContent = 'Mapa PPL hlásí chybu. Zkontrolujte připojení a povolenou doménu klíče.';
  });

  form.addEventListener('submit', (event) => {
    if (radio.checked && (!/^KM[0-9]{7}$/.test(code.value) || !name.value || !address.value || country.value !== 'CZ')) {
      event.preventDefault();
      status.textContent = 'Nejdřív vyberte výdejní místo v mapě PPL.';
      button.focus();
    }
  });
})();
