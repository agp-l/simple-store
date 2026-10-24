(() => {
  const form = document.getElementById('media-selection');
  if (!form) return;
  const folders = document.querySelector('[data-library-folder-panel]');
  const mobile = window.matchMedia('(max-width: 800px)');
  const adaptFolders = () => { if (folders) folders.open = !mobile.matches; };
  adaptFolders(); mobile.addEventListener('change', adaptFolders);
  const boxes = [...document.querySelectorAll('input[form="media-selection"][name="paths[]"]')];
  const all = document.querySelector('[data-library-select-all]');
  const status = document.querySelector('[data-library-status]');
  const update = () => {
    const count = boxes.filter(box => box.checked).length;
    document.querySelector('[data-library-selection-count]').textContent = `${count} vybráno`;
    all.checked = boxes.length > 0 && count === boxes.length;
    all.indeterminate = count > 0 && count < boxes.length;
    document.querySelectorAll('[data-library-requires-selection]').forEach(button => { button.disabled = count === 0; });
    boxes.forEach(box => box.closest('.library-card').classList.toggle('is-selected', box.checked));
  };
  boxes.forEach(box => box.addEventListener('change', update));
  all.addEventListener('change', () => { boxes.forEach(box => { box.checked = all.checked; }); update(); });
  update();
  document.querySelectorAll('[data-library-url]').forEach(input => {
    input.value = new URL(input.value, location.origin).href;
    input.addEventListener('click', () => input.select());
  });
  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-library-copy]');
    if (!button) return;
    const url = new URL(button.dataset.libraryCopy, location.origin).href;
    try { await navigator.clipboard.writeText(url); status.textContent = 'Adresa obrázku je zkopírovaná.'; }
    catch { window.prompt('Zkopíruj adresu obrázku:', url); }
  });
  document.querySelector('[data-library-upload]')?.addEventListener('submit', event => {
    const files = [...event.currentTarget.querySelector('[type="file"]').files];
    if (files.length > 20 || files.some(file => file.size > 12 * 1024 * 1024 || file.size === 0)) {
      event.preventDefault(); window.alert('Vyber nejvýše 20 fotografií, každou do 12 MiB.');
    }
  });
})();
