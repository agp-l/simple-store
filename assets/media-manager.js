(() => {
  const dialog = document.getElementById('media-picker');
  if (!dialog) return;
  const pageConfig = document.getElementById('media-page-config');
  let context = window.SimpleStoreMediaContext;
  const pageGrid = document.getElementById('media-page-grid');
  const dialogGrid = document.getElementById('media-dialog-grid');
  const status = dialog.querySelector('[data-media-dialog-status]');
  const pageStatus = document.querySelector('[data-media-status]');
  let mode = 'gallery-add';
  let index = null;
  let busy = false;

  async function request(fields) {
    const response = await fetch(context.endpoint, {
      method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' },
      body: fields
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.revision) throw new Error(data.error || 'Server změnu nepotvrdil.');
    return data;
  }

  if (pageConfig) {
    const config = JSON.parse(pageConfig.textContent);
    context = {
      ...config, getRevision: async () => config.revision,
      choose: async path => {
        const form = new FormData();
        for (const [name, value] of Object.entries({action: 'media-attach', csrf: config.csrf,
          key: config.key, type: config.type, language: config.language,
          revision: String(config.revision), path})) form.set(name, value);
        const result = await request(form);
        config.revision = result.revision;
        if (pageStatus) pageStatus.textContent = `Vloženo · revize ${config.revision}`;
      },
      uploaded: result => {
        config.revision = result.revision;
        if (pageStatus) pageStatus.textContent = `Nahráno a vloženo · revize ${config.revision}`;
        load();
      }
    };
    document.getElementById('media-target').addEventListener('change', event => {
      location.assign(event.target.value);
    });
    document.querySelector('[data-media-open]').addEventListener('click', () => open('library'));
    render(pageGrid, config.files);
  }

  function absolute(path) {
    return path.startsWith('https://') ? path : new URL(context.basePath + path, location.origin).href;
  }

  function render(container, files) {
    if (!container) return;
    container.replaceChildren();
    if (!files.length) {
      const empty = document.createElement('p');
      empty.textContent = 'Zatím tu nejsou žádné nahrané fotografie. Starší soubory v images/ můžeš vložit přes adresu.';
      container.append(empty);
      return;
    }
    for (const file of files) {
      const card = document.createElement('article');
      card.className = 'media-item';
      const image = document.createElement('img');
      image.src = absolute(file.thumb);
      image.alt = file.label.replaceAll('-', ' ');
      image.loading = 'lazy';
      const title = document.createElement('strong');
      title.textContent = file.label.replaceAll('-', ' ');
      const buttons = document.createElement('div');
      buttons.className = 'media-item-actions';
      const choose = document.createElement('button');
      choose.type = 'button';
      choose.textContent = context.type === 'product' && (pageGrid || mode === 'main-image')
        ? 'Použít jako hlavní' : 'Vložit obrázek';
      choose.addEventListener('click', async () => {
        if (busy) return;
        try {
          busy = true;
          await context.choose(file.path, mode, index);
          if (dialog.open) dialog.close();
          if (pageGrid) load();
        } catch (error) { status.textContent = error.message; }
        finally { busy = false; }
      });
      buttons.append(choose);
      for (const [label, path] of [['Velký', file.path], ['Karta', file.card], ['Miniatura', file.thumb]]) {
        const copy = document.createElement('button');
        copy.type = 'button';
        copy.textContent = `Kopírovat ${label.toLowerCase()}`;
        copy.setAttribute('aria-label', `Kopírovat adresu: ${label}`);
        copy.addEventListener('click', async () => {
          const url = absolute(path);
          try {
            await navigator.clipboard.writeText(url);
            status.textContent = `Adresa zkopírována: ${label.toLowerCase()}.`;
            if (pageStatus) pageStatus.textContent = status.textContent;
          } catch { window.prompt('Zkopíruj adresu obrázku:', url); }
        });
        buttons.append(copy);
      }
      card.append(image, title, buttons);
      container.append(card);
    }
  }

  async function load() {
    const url = new URL(context.endpoint, location.href);
    for (const [key, value] of Object.entries({section: 'media', api: 'list',
      type: context.type, key: context.key, language: context.language})) url.searchParams.set(key, value);
    try {
      const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}, cache: 'no-store'});
      const data = await response.json();
      if (!response.ok || !Array.isArray(data.files)) throw new Error(data.error || 'Fotografie se nepodařilo načíst.');
      render(dialogGrid, data.files);
      render(pageGrid, data.files);
    } catch (error) { status.textContent = error.message; }
  }

  function open(nextMode, nextIndex = null, current = '') {
    mode = nextMode;
    index = nextIndex;
    status.textContent = '';
    dialog.querySelector('[name="path"]').value = current;
    dialog.showModal();
    load();
  }
  window.SimpleStoreMedia = {open};
  dialog.querySelector('[data-media-close]').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });

  document.getElementById('media-upload-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    const form = event.currentTarget;
    const files = form.querySelector('[type="file"]').files;
    if (!files.length || files.length > 12 || [...files].some(file => file.size > 12 * 1024 * 1024)) {
      status.textContent = 'Vyber 1 až 12 fotografií, každou nejvýše 12 MB.';
      return;
    }
    busy = true;
    status.textContent = 'Nahrávám a zpracovávám fotografie…';
    try {
      const revision = await context.getRevision();
      const fields = new FormData(form);
      for (const [name, value] of Object.entries({action: 'media-upload', csrf: context.csrf,
        key: context.key, type: context.type, language: context.language,
        revision: String(revision)})) fields.set(name, value);
      const result = await request(fields);
      form.reset();
      dialog.close();
      context.uploaded(result);
    } catch (error) { status.textContent = error.message; }
    finally { busy = false; }
  });

  document.getElementById('media-url-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    busy = true;
    try {
      await context.getRevision();
      await context.choose(event.currentTarget.elements.path.value.trim(), mode, index);
      dialog.close();
      if (pageGrid) load();
    } catch (error) { status.textContent = error.message; }
    finally { busy = false; }
  });
})();
