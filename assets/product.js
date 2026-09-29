(() => {
  const quantity = document.getElementById('qty');
  if (!quantity) return;
  const mainImage = document.getElementById('detail-image');
  document.querySelectorAll('[data-gallery-image]').forEach(button => button.addEventListener('click', () => {
    mainImage.src = button.dataset.galleryImage;
    document.querySelectorAll('[data-gallery-image]').forEach(item =>
      item.setAttribute('aria-pressed', String(item === button)));
  }));
  const lightbox = document.getElementById('detail-lightbox');
  const lightboxImage = document.getElementById('detail-lightbox-image');
  document.getElementById('detail-image-open').addEventListener('click', () => {
    lightboxImage.src = mainImage.src;
    lightbox.showModal();
  });
  document.getElementById('detail-lightbox-close').addEventListener('click', () => lightbox.close());
  lightbox.addEventListener('click', event => { if (event.target === lightbox) lightbox.close(); });
  lightbox.addEventListener('close', () => lightboxImage.removeAttribute('src'));
  document.getElementById('minus').addEventListener('click', () => {
    quantity.value = Math.max(1, Number(quantity.value) - 1);
  });
  document.getElementById('plus').addEventListener('click', () => {
    quantity.value = Math.min(99, Number(quantity.value) + 1);
  });
  document.getElementById('detail-add').addEventListener('click', () => {
    const button = document.getElementById('detail-add');
    const options = [...document.querySelectorAll('.product-option')];
    const missing = options.find(field => !field.value);
    if (missing) {
      document.getElementById('detail-feedback').textContent = 'Nejdřív vyberte ' +
        missing.dataset.optionName + '.';
      missing.focus();
      return;
    }
    document.getElementById('detail-feedback').textContent = '';
    const name = button.dataset.name || document.querySelector('.detail-info h1').textContent.trim();
    const price = Number(button.dataset.price);
    const selected = options.map(field => `${field.dataset.optionName}: ${field.value}`);
    window.DobrodruziCart.add({ name: selected.length ? `${name} · ${selected.join(' · ')}` : name, price },
      Number(quantity.value));
  });
})();
