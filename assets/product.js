(() => {
  const quantity = document.getElementById('qty');
  if (!quantity) return;
  document.getElementById('minus').addEventListener('click', () => {
    quantity.value = Math.max(1, Number(quantity.value) - 1);
  });
  document.getElementById('plus').addEventListener('click', () => {
    quantity.value = Math.min(99, Number(quantity.value) + 1);
  });
  document.getElementById('detail-add').addEventListener('click', () => {
    const button = document.getElementById('detail-add');
    const sizeField = document.getElementById('shoe-size');
    if (sizeField && !sizeField.value) {
      document.getElementById('detail-feedback').textContent = 'Nejdřív vyberte velikost.';
      sizeField.focus();
      return;
    }
    const name = button.dataset.name || document.querySelector('.detail-info h1').textContent.trim();
    const price = Number(button.dataset.price || 3990);
    window.DobrodruziCart.add({ name: sizeField ? `${name}, ${sizeField.value}` : name, price },
      Number(quantity.value));
  });
})();
