(() => {
  const batch = document.getElementById('variant-batch');
  if (!batch) return;
  const rows = Array.from(document.querySelectorAll('[data-variant-row]'));
  batch.hidden = false;
  let dirty = false;
  rows.forEach(row => row.addEventListener('input', () => { dirty = true; }));
  batch.addEventListener('submit', event => {
    const changes = [];
    for (const row of rows) {
      const change = { variant_id: row.dataset.variantRow };
      for (const input of row.querySelectorAll('input[form],select[form]')) {
        if (!input.reportValidity()) { event.preventDefault(); return; }
        change[input.name] = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
      }
      changes.push(change);
    }
    batch.elements.rows_json.value = JSON.stringify(changes);
    dirty = false;
  });
  // Keep a PHP-only per-row fallback, but save the whole table when JavaScript is available.
  rows.forEach(row => row.querySelector('form').addEventListener('submit', event => {
    event.preventDefault(); batch.requestSubmit();
  }));
  window.addEventListener('beforeunload', event => {
    if (dirty) { event.preventDefault(); event.returnValue = ''; }
  });
})();
