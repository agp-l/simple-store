(() => {
  const quantity = document.getElementById('qty');
  if (!quantity) return;
  const mainImage = document.getElementById('detail-image');
  const thumbnails = Array.from(document.querySelectorAll('[data-gallery-image]'));
  const lightbox = document.getElementById('detail-lightbox');
  const lightboxImage = document.getElementById('detail-lightbox-image');
  const previous = document.getElementById('detail-lightbox-previous');
  const next = document.getElementById('detail-lightbox-next');
  const counter = document.getElementById('detail-lightbox-counter');
  let currentIndex = 0;

  const selectImage = index => {
    currentIndex = (index + thumbnails.length) % thumbnails.length;
    mainImage.src = thumbnails[currentIndex].dataset.galleryImage;
    thumbnails.forEach((item, position) =>
      item.setAttribute('aria-pressed', String(position === currentIndex)));
    if (lightbox.open) showImage();
  };
  const showImage = () => {
    // Gallery URLs point to the full-size asset, not the thumbnail variant.
    lightboxImage.src = mainImage.src;
    counter.textContent = `${currentIndex + 1} / ${Math.max(1, thumbnails.length)}`;
  };
  const move = direction => {
    if (lightbox.open && thumbnails.length > 1) selectImage(currentIndex + direction);
  };
  thumbnails.forEach((button, index) => button.addEventListener('click', () => selectImage(index)));
  previous.hidden = next.hidden = counter.hidden = thumbnails.length < 2;
  previous.addEventListener('click', () => move(-1));
  next.addEventListener('click', () => move(1));
  document.getElementById('detail-image-open').addEventListener('click', () => {
    showImage();
    lightbox.showModal();
  });
  document.getElementById('detail-lightbox-close').addEventListener('click', () => lightbox.close());
  lightbox.addEventListener('keydown', event => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    event.preventDefault();
    move(event.key === 'ArrowLeft' ? -1 : 1);
  });
  lightbox.addEventListener('click', event => { if (event.target === lightbox) lightbox.close(); });
  lightbox.addEventListener('close', () => lightboxImage.removeAttribute('src'));
  document.getElementById('minus').addEventListener('click', () => {
    quantity.value = Math.max(1, (Number(quantity.value) || 1) - 1);
  });
  document.getElementById('plus').addEventListener('click', () => {
    quantity.value = Math.min(99, (Number(quantity.value) || 0) + 1);
  });
})();
