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
    quantity.value = Math.max(1, (Number(quantity.value) || 1) - 1);
  });
  document.getElementById('plus').addEventListener('click', () => {
    quantity.value = Math.min(99, (Number(quantity.value) || 0) + 1);
  });
})();
