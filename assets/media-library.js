(() => {
  const form = document.getElementById('media-selection');
  if (!form) return;
  const folders = document.querySelector('[data-library-folder-panel]');
  const mobile = window.matchMedia('(max-width: 800px)');
  const adaptFolders = () => { if (folders) folders.open = !mobile.matches; };
  adaptFolders(); mobile.addEventListener('change', adaptFolders);
  const boxes = [...document.querySelectorAll('input[form="media-selection"][type="checkbox"]')];
  const all = document.querySelector('[data-library-select-all]');
  const status = document.querySelector('[data-library-status]');
  const update = () => {
    const selected = boxes.filter(box => box.checked);
    const photos = selected.reduce((sum, box) => sum + Number(box.dataset.photoCount || 1), 0);
    const directories = selected.some(box => box.name === 'owners[]');
    document.querySelector('[data-library-selection-count]').textContent = directories
      ? `Složky: ${selected.length} · fotografie: ${photos}` : `${photos} vybráno`;
    all.checked = boxes.length > 0 && selected.length === boxes.length;
    all.indeterminate = selected.length > 0 && selected.length < boxes.length;
    all.disabled = boxes.length === 0;
    document.querySelectorAll('[data-library-requires-selection]').forEach(button => { button.disabled = selected.length === 0; });
    boxes.forEach(box => box.closest('.library-card, .library-directory').classList.toggle('is-selected', box.checked));
  };
  boxes.forEach(box => box.addEventListener('change', update));
  all.addEventListener('change', () => { boxes.forEach(box => { box.checked = all.checked; }); update(); });
  update();
  document.querySelectorAll('[data-library-url]').forEach(input => {
    input.value = new URL(input.value, location.origin).href;
    input.addEventListener('click', () => input.select());
  });
  async function copy(path, output = status) {
    const url = new URL(path, location.origin).href;
    try { await navigator.clipboard.writeText(url); output.textContent = 'Adresa obrázku je zkopírovaná.'; }
    catch { window.prompt('Zkopíruj adresu obrázku:', url); }
  }
  const previews = [...document.querySelectorAll('[data-library-preview]')];
  const dialog = document.querySelector('[data-library-preview-dialog]');
  let previewIndex = 0;
  const showPreview = index => {
    if (!dialog || !previews.length) return;
    previewIndex = (index + previews.length) % previews.length;
    const item = previews[previewIndex];
    dialog.querySelector('h2').textContent = item.dataset.previewTitle;
    const image = dialog.querySelector('[data-library-preview-image]');
    image.src = item.dataset.libraryPreview; image.alt = item.dataset.previewTitle;
    dialog.querySelector('[data-library-preview-original]').href = item.dataset.previewOriginal;
    dialog.querySelector('[data-library-preview-position]').textContent = `${previewIndex + 1} / ${previews.length}`;
    dialog.querySelector('[data-library-preview-status]').textContent = '';
    for (const name of ['previous', 'next']) dialog.querySelector(`[data-library-preview-${name}]`).disabled = previews.length < 2;
    if (!dialog.open) dialog.showModal();
  };
  dialog?.querySelector('[data-library-preview-close]').addEventListener('click', () => dialog.close());
  dialog?.querySelector('[data-library-preview-previous]').addEventListener('click', () => showPreview(previewIndex - 1));
  dialog?.querySelector('[data-library-preview-next]').addEventListener('click', () => showPreview(previewIndex + 1));
  dialog?.querySelector('[data-library-preview-copy]').addEventListener('click', () => copy(previews[previewIndex].dataset.previewOriginal, dialog.querySelector('[data-library-preview-status]')));
  dialog?.addEventListener('keydown', event => {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); showPreview(previewIndex + (event.key === 'ArrowLeft' ? -1 : 1)); }
  });
  dialog?.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  document.addEventListener('click', async event => {
    const expand = event.target.closest('[data-library-expand]');
    if (expand) {
      const target = document.getElementById(expand.dataset.libraryExpand);
      if (target) { event.preventDefault(); target.open = true; target.scrollIntoView({block: 'start', behavior: 'smooth'}); target.querySelector('input[type="file"]')?.focus({preventScroll: true}); }
    }
    const preview = event.target.closest('[data-library-preview]');
    if (preview && dialog && !event.ctrlKey && !event.metaKey && !event.shiftKey) { event.preventDefault(); showPreview(previews.indexOf(preview)); }
    const button = event.target.closest('[data-library-copy]');
    if (button) await copy(button.dataset.libraryCopy);
  });
  document.querySelector('[data-library-upload]')?.addEventListener('submit', event => {
    const files = [...event.currentTarget.querySelector('[type="file"]').files];
    if (files.length > 20 || files.some(file => file.size > 12 * 1024 * 1024 || file.size === 0)) {
      event.preventDefault(); window.alert('Vyber nejvýše 20 fotografií, každou do 12 MiB.');
    }
  });
})();
