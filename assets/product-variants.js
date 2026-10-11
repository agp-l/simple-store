(() => {
  const picker = document.getElementById('product-variant');
  const price = document.querySelector('[data-variant-price]');
  const stock = document.querySelector('[data-variant-stock]');
  const btc = document.querySelector('[data-variant-btc]');
  if (!picker || !price || !stock) return;
  const initial = { price: price.textContent, stock: stock.textContent, btc: btc?.textContent || '' };
  picker.addEventListener('change', () => {
    const option = picker.selectedOptions[0];
    if (!option?.value) {
      price.textContent = initial.price; stock.textContent = initial.stock;
      if (btc) btc.textContent = initial.btc;
      return;
    }
    price.textContent = new Intl.NumberFormat('cs-CZ').format(Number(option.dataset.price));
    stock.textContent = option.dataset.stock;
    stock.parentElement.classList.toggle('stock-wait', option.dataset.stock !== 'Skladem');
    if (btc) btc.textContent = option.dataset.btc || '';
    // Reuse the gallery controller so selection and lightbox stay synchronized.
    const thumbnail = Array.from(document.querySelectorAll('[data-gallery-image]'))
      .find(button => button.dataset.galleryImage === option.dataset.image);
    thumbnail?.click();
  });
})();
