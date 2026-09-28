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
    const size = document.getElementById('shoe-size').value;
    window.DobrodruziCart.add({ name: `Terraventure 5 Men's, ${size}`, price: 3990 },
      Number(quantity.value));
  });
})();
