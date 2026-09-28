(() => {
  const form = document.querySelector('.admin-editor');
  if (!form) return;
  const title = form.querySelector('[name="name"], [name="title"]');
  const slug = form.querySelector('[name="slug"]');
  if (title && slug) {
    let automatic = !slug.value;
    const makeSlug = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 190).replace(/-$/g, '');
    title.addEventListener('input', () => { if (automatic) slug.value = makeSlug(title.value); });
    slug.addEventListener('input', () => { automatic = slug.value === ''; });
  }
})();
