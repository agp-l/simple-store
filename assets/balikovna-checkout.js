/* The official Balíkovna map posts a pickerResult from its iframe. */
(() => {
  const button = document.querySelector('[data-balikovna-open]');
  const dialog = document.querySelector('[data-balikovna-dialog]');
  if (!button || !dialog) return;

  const form = button.closest('form');
  const radio = form.querySelector('input[name="method"][value="balikovna_pickup"]');
  const frame = dialog.querySelector('[data-balikovna-map]');
  const close = dialog.querySelector('[data-balikovna-close]');
  const dialogStatus = dialog.querySelector('[data-balikovna-dialog-status]');
  const id = form.querySelector('[data-balikovna-id]');
  const name = form.querySelector('[data-balikovna-name]');
  const address = form.querySelector('[data-balikovna-address]');
  const zip = form.querySelector('[data-balikovna-zip]');
  const type = form.querySelector('[data-balikovna-type]');
  const selection = form.querySelector('[data-balikovna-selection]');
  const status = form.querySelector('[data-balikovna-status]');
  const value = (field) => typeof field === 'string' ? field.trim() : '';
  const bytes = (field) => new TextEncoder().encode(field).length;
  const valid = () => /^[0-9]{1,12}$/.test(id.value) && /^[0-9]{5}$/.test(zip.value) &&
    type.value === 'BALIKOVNY' && name.value.length > 0 && address.value.length > 0 &&
    bytes(name.value) <= 190 && bytes(address.value) <= 190;

  button.addEventListener('click', () => {
    radio.checked = true;
    if (typeof dialog.showModal !== 'function') {
      status.textContent = 'Tento prohlížeč neumí otevřít mapu Balíkovny. Použijte prosím aktuální prohlížeč.';
      return;
    }
    dialogStatus.textContent = '';
    status.textContent = '';
    if (!frame.src) frame.src = frame.dataset.src;
    dialog.showModal();
  });
  close.addEventListener('click', () => dialog.close());

  window.addEventListener('message', (event) => {
    if (!dialog.open || event.origin !== 'https://b2c.cpost.cz' ||
        event.source !== frame.contentWindow || !event.data ||
        typeof event.data !== 'object' || event.data.message !== 'pickerResult') return;
    const point = event.data.point;
    if (!point || typeof point !== 'object') return;

    const pointId = value(String(point.id ?? event.data.id ?? ''));
    const pointName = value(point.name);
    const pointAddress = value(point.address);
    const pointZip = value(point.zip);
    if (!/^[0-9]{1,12}$/.test(pointId) || !/^[0-9]{5}$/.test(pointZip) ||
        point.type !== 'BALIKOVNY' || !pointName || !pointAddress ||
        bytes(pointName) > 190 || bytes(pointAddress) > 190) {
      dialogStatus.textContent = 'Vybrané místo nemá úplné údaje Balíkovny. Zvolte prosím jiné místo.';
      return;
    }
    radio.checked = true;
    id.value = pointId;
    name.value = pointName;
    address.value = pointAddress;
    zip.value = pointZip;
    type.value = 'BALIKOVNY';
    selection.textContent = `${pointName} · ${pointAddress} (ID ${pointId})`;
    status.textContent = 'Balíkovna byla vybrána. Pokračujte k platbě.';
    dialog.close();
  });

  form.addEventListener('submit', (event) => {
    if (radio.checked && !valid()) {
      event.preventDefault();
      status.textContent = 'Nejdřív vyberte místo nebo box v mapě Balíkovny.';
      button.focus();
    }
  });
})();
