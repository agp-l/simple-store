(() => {
  const batch = document.getElementById('variant-batch');
  if (!batch) return;
  const root = document.querySelector('[data-variant-manager]');
  const rows = Array.from(root.querySelectorAll('[data-variant-row]'));
  const priceInputs = rows.map(row => row.querySelector('[name="price_czk"]'));
  const state = root.querySelector('[data-variant-save-state]');
  const inherit = root.querySelector('[data-variant-inherit]');
  const undo = root.querySelector('[data-variant-prices-undo]');
  const money = value => new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 }).format(value);
  let basePrice = Number(root.dataset.basePrice);
  let previousPrices = null, submitted = false, sending = false;
  const recovered = root.dataset.recovered === '1';
  const collect = () => rows.map(row => {
    const change = { variant_id: row.dataset.variantRow };
    for (const input of row.querySelectorAll('input[form],select[form]')) {
      change[input.name] = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
    }
    return change;
  });
  const initial = JSON.stringify(collect());
  batch.hidden = false;

  function update() {
    let ownPrices = 0;
    rows.forEach((row, i) => {
      const own = priceInputs[i].value !== '';
      if (own) ownPrices++;
      priceInputs[i].placeholder = String(basePrice);
      row.querySelector('[data-variant-price-note]').textContent = own ? 'Vlastní cena' : 'Společná cena';
    });
    inherit.querySelector('[type="submit"]').disabled = ownPrices === 0;
    root.querySelector('[data-variant-price-count]').textContent = ownPrices === 0
      ? 'Všechny varianty přebírají společnou cenu produktu.'
      : `Vlastní cenu má ${ownPrices} z ${rows.length} variant. Ostatní přebírají cenu produktu.`;
    root.dataset.dirty = recovered || JSON.stringify(collect()) !== initial ? 'true' : 'false';
    state.hidden = root.dataset.dirty !== 'true';
    root.querySelector('[data-variant-save-top]').hidden = state.hidden;
    state.textContent = 'Neuložené změny variant — potvrď tlačítkem Uložit změny variant.';
    undo.hidden = previousPrices === null;
  }
  rows.forEach(row => {
    const changed = event => {
      if (event.target.name === 'price_czk') previousPrices = null;
      update();
    };
    row.addEventListener('input', changed);
    row.addEventListener('change', changed);
  });
  inherit.addEventListener('submit', event => {
    event.preventDefault();
    previousPrices = priceInputs.map(input => input.value);
    priceInputs.forEach(input => { input.value = ''; });
    update();
  });
  undo.addEventListener('click', () => {
    if (previousPrices === null) return;
    priceInputs.forEach((input, i) => { input.value = previousPrices[i]; });
    previousPrices = null;
    update();
  });
  batch.addEventListener('submit', async event => {
    event.preventDefault();
    if (sending) return;
    for (const row of rows) {
      for (const input of row.querySelectorAll('input[form],select[form]')) {
        if (!input.reportValidity()) return;
      }
    }
    sending = true;
    try {
      // Finish a simultaneous edit of the shared price before saving its inheriting rows.
      await window.SimpleStoreMediaContext?.getRevision();
      batch.elements.rows_json.value = JSON.stringify(collect());
      submitted = true;
      HTMLFormElement.prototype.submit.call(batch);
    } catch (error) {
      state.hidden = false;
      state.textContent = 'Varianty nebyly uloženy: ' + error.message;
      sending = false;
    }
  });
  // PHP-only per-row and bulk inheritance fallbacks remain regular authenticated forms.
  rows.forEach(row => row.querySelector('form').addEventListener('submit', event => {
    event.preventDefault(); batch.requestSubmit();
  }));
  document.addEventListener('product-price-saved', event => {
    if (!Number.isInteger(event.detail?.price) || event.detail.price < 1) return;
    basePrice = event.detail.price;
    document.querySelectorAll('[data-edit-operation="set"][data-edit-field="price_czk"]').forEach(target => {
      if (target !== document.activeElement) target.textContent = money(basePrice);
    });
    update();
  });
  window.addEventListener('pageshow', () => { submitted = false; sending = false; update(); });
  window.addEventListener('beforeunload', event => {
    if (!submitted && root.dataset.dirty === 'true') { event.preventDefault(); event.returnValue = ''; }
  });
  update();
})();
