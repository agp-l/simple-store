(() => {
  const generate = document.querySelector('[data-variant-generate]');
  if (generate) {
    const groups = generate.elements.groups;
    const preview = generate.querySelector('[data-variant-generate-preview]');
    const error = generate.querySelector('[data-variant-generate-error]');
    const button = generate.querySelector('[type="submit"]');
    const initial = new URLSearchParams(new FormData(generate)).toString();
    let submitting = false;
    const validate = () => {
      const lines = groups.value.trim().split(/\r\n|[\n\r\u0085\u2028\u2029]/u).filter(line => line.trim());
      if (!lines.length) throw new Error('Nejdřív vyplň vlastnosti a hodnoty, například Velikost: S | M | L.');
      if (lines.length > 8) throw new Error('Použij nejvýše 8 vlastností.');
      const names = new Set();
      let count = 1;
      for (const line of lines) {
        const colon = line.indexOf(':');
        const name = line.slice(0, colon).trim();
        const values = line.slice(colon + 1).split('|').map(value => value.trim());
        if (colon < 1 || !name || values.some(value => !value)) throw new Error('Každý řádek vyplň ve tvaru Velikost: S | M | L. Za dvojtečkou ani mezi oddělovači nesmí být prázdná hodnota.');
        if (names.has(name)) throw new Error('Každou vlastnost napiš jen jednou.');
        names.add(name);
        count *= new Set(values).size;
        if (count > 1000) throw new Error('Vzniklo by více než 1 000 kombinací. Zmenši počet hodnot.');
      }
      return count;
    };
    const update = () => {
      generate.dataset.dirty = generate.dataset.recovered === '1' || new URLSearchParams(new FormData(generate)).toString() !== initial ? 'true' : 'false';
      error.hidden = true;
      groups.removeAttribute('aria-invalid');
      preview.hidden = false;
      try { preview.textContent = `Zadaných kombinací: ${validate()}. Existující varianty se zachovají.`; }
      catch (problem) { preview.textContent = problem.message; }
    };
    generate.addEventListener('input', update);
    generate.addEventListener('change', update);
    // Native required checks remain available when JavaScript is disabled.
    generate.noValidate = true;
    generate.addEventListener('submit', async event => {
      event.preventDefault();
      if (submitting) return;
      error.hidden = true;
      try { validate(); }
      catch (problem) {
        error.textContent = problem.message; error.hidden = false;
        groups.setAttribute('aria-invalid', 'true'); groups.focus(); return;
      }
      if (!generate.reportValidity()) return;
      submitting = true; generate.dataset.submitting = 'true'; button.disabled = true;
      preview.textContent = 'Ukládám varianty…';
      try {
        await window.SimpleStoreMediaContext?.getRevision();
        HTMLFormElement.prototype.submit.call(generate);
      } catch (problem) {
        submitting = false; generate.dataset.submitting = 'false'; button.disabled = false;
        error.textContent = 'Varianty nebyly vytvořeny: ' + problem.message; error.hidden = false;
      }
    });
    window.addEventListener('pageshow', () => {
      submitting = false; generate.dataset.submitting = 'false'; button.disabled = false; update();
    });
    window.addEventListener('beforeunload', event => {
      if (!submitting && generate.dataset.dirty === 'true') { event.preventDefault(); event.returnValue = ''; }
    });
    update();
  }
  const batch = document.getElementById('variant-batch');
  if (!batch) return;
  const root = document.querySelector('[data-variant-manager]');
  const rows = Array.from(root.querySelectorAll('[data-variant-row]'));
  const priceInputs = rows.map(row => row.querySelector('[name="price_czk"]'));
  const state = root.querySelector('[data-variant-save-state]');
  const inherit = root.querySelector('[data-variant-inherit]');
  const undo = root.querySelector('[data-variant-prices-undo]');
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
  document.querySelector('#variant-stock-mode form')?.addEventListener('submit', event => {
    if (root.dataset.dirty !== 'true') return;
    event.preventDefault();
    state.hidden = false;
    state.textContent = 'Nejdřív ulož změny v tabulce variant, potom přepni režim skladu.';
    root.querySelector('[data-variant-save-top]').focus();
  });

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
    const photo = row.querySelector('[name="image_path"]');
    const initialPhoto = photo.value;
    const group = row.closest('.variant-table-group');
    const image = group.querySelector('[data-partner-image]');
    const photoNote = group.querySelector('[data-partner-image-note]');
    const initialPhotoNote = photoNote?.textContent;
    const changed = event => {
      if (event.target.name === 'price_czk') previousPrices = null;
      if (event.target === photo && image) {
        const option = photo.selectedOptions[0];
        image.src = option.dataset.photoPreview;
        group.querySelector('[data-partner-image-link]').href = option.dataset.photoFull;
        photoNote.textContent = photo.value === initialPhoto ? initialPhotoNote
          : 'Vybraná fotografie — ulož změny variant.';
      }
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
    update();
  });
  window.addEventListener('pageshow', () => { submitted = false; sending = false; update(); });
  window.addEventListener('beforeunload', event => {
    if (!submitted && root.dataset.dirty === 'true') { event.preventDefault(); event.returnValue = ''; }
  });
  update();
})();
