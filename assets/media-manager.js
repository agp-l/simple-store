(() => {
  const dialog = document.getElementById('media-picker');
  if (!dialog) return;
  const pageConfig = document.getElementById('media-page-config');
  const pageSettings = pageConfig ? JSON.parse(pageConfig.textContent) : null;
  let context = window.SimpleStoreMediaContext;
  const pageGrid = document.getElementById('media-page-grid');
  const dialogGrid = document.getElementById('media-dialog-grid');
  const status = dialog.querySelector('[data-media-dialog-status]');
  const pageStatus = document.querySelector('[data-media-status]');
  let mode = pageSettings?.type === 'product'
    ? 'main-image' : 'section-add-image';
  let index = null;
  let busy = false;

  async function request(fields) {
    const response = await fetch(context.endpoint, {
      method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' },
      body: fields
    });
    const body = await response.text();
    let data;
    try { data = JSON.parse(body); }
    catch {
      throw new Error(`Server nevrátil platné JSON (HTTP ${response.status}). Zkontroluj odpověď požadavku admin.php v nástrojích pro vývojáře a chybový log PHP.`);
    }
    if (!response.ok) throw new Error(data.error || `Nahrávání selhalo (HTTP ${response.status}).`);
    if (!Number.isInteger(data.revision) || data.revision < 1) {
      throw new Error('Server nevrátil číslo nové revize. Obnov stránku a zkontroluj knihovnu fotografií.');
    }
    return data;
  }

  if (pageSettings) {
    const config = pageSettings;
    context = {
      ...config, getRevision: async () => config.revision,
      uploaded: (result, action) => {
        config.revision = result.revision;
        const mainLink = document.querySelector('[data-media-main]');
        if (mainLink && result.paths?.[0]) {
          mainLink.href = absolute(result.paths[0]);
          mainLink.textContent = result.paths[0];
        }
        if (pageStatus) pageStatus.textContent =
          `${action === 'media-upload' ? 'Nahráno' : 'Vloženo'} · revize ${config.revision}`;
        load();
      }
    };
    document.querySelector('[data-media-open]').addEventListener('click', () => open(mode));
    render(pageGrid, config.files);
  }

  function absolute(path) {
    return path.startsWith('https://') ? path : new URL(context.basePath + path, location.origin).href;
  }

  function promotesFirst() {
    return mode === 'main-image' || (mode === 'gallery-add' && context.type === 'product' &&
      document.getElementById('detail-image')?.dataset.imagePath === 'images/batoh.webp');
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
      if (file.uses?.includes('main')) card.classList.add('media-item--main');
      const image = document.createElement('img');
      image.src = absolute(file.thumb);
      image.alt = file.label.replaceAll('-', ' ');
      image.loading = 'lazy';
      const title = document.createElement('strong');
      title.textContent = file.label.replaceAll('-', ' ');
      const uses = document.createElement('span');
      uses.className = 'media-item-usage';
      const roleLabels = {main: 'Hlavní fotografie', gallery: 'V galerii', section: 'V bloku'};
      uses.textContent = file.uses?.length
        ? file.uses.map(role => roleLabels[role] || role).join(' · ')
        : 'V aktuální verzi nepoužitá';
      const buttons = document.createElement('div');
      buttons.className = 'media-item-actions';
      const choose = document.createElement('button');
      choose.type = 'button';
      choose.textContent = mode === 'main-image' ? 'Použít jako hlavní'
        : promotesFirst() ? 'Nastavit jako první fotografii'
        : mode === 'gallery-add' ? 'Přidat do galerie'
        : mode === 'gallery-set' ? 'Nahradit v galerii'
        : mode === 'section-image' ? 'Nahradit v bloku' : 'Vložit do popisu';
      if (mode === 'main-image' && file.uses?.includes('main')) {
        choose.textContent = 'Aktuální hlavní fotografie';
        choose.disabled = true;
      }
      choose.addEventListener('click', async () => {
        if (busy) return;
        try {
          busy = true;
          await attach(file.path);
          if (dialog.open) dialog.close();
        } catch (error) {
          status.textContent = error.message;
          if (!dialog.open && pageStatus) pageStatus.textContent = error.message;
        }
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
      card.append(image, title, uses, buttons);
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
    } catch (error) {
      status.textContent = error.message;
      if (pageStatus) pageStatus.textContent = error.message;
    }
  }

  function open(nextMode, nextIndex = null, current = '') {
    mode = nextMode;
    index = nextIndex;
    status.textContent = '';
    dialog.querySelector('[name="path"]').value = current;
    dialog.querySelector('.media-primary').textContent = promotesFirst()
      ? 'Nahrát · první jako hlavní' : mode === 'gallery-add'
        ? 'Nahrát do galerie' : mode === 'gallery-set'
          ? 'Nahrát · první nahradí snímek' : mode === 'section-image'
            ? 'Nahrát · první nahradí blok' : 'Nahrát do popisu';
    dialog.showModal();
    load();
  }

  async function attach(path) {
    const revision = await context.getRevision();
    const fields = new FormData();
    for (const [name, value] of Object.entries({action: 'media-attach', csrf: context.csrf,
      key: context.key, type: context.type, language: context.language,
      revision: String(revision), mode, path})) fields.set(name, value);
    if (index !== null) fields.set('index', String(index));
    const result = await request(fields);
    context.uploaded(result, 'media-attach');
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
        revision: String(revision), mode})) fields.set(name, value);
      if (index !== null) fields.set('index', String(index));
      const result = await request(fields);
      form.reset();
      dialog.close();
      context.uploaded(result, 'media-upload');
    } catch (error) { status.textContent = error.message; }
    finally { busy = false; }
  });

  document.getElementById('media-url-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    busy = true;
    try {
      await attach(event.currentTarget.elements.path.value.trim());
      dialog.close();
    } catch (error) { status.textContent = error.message; }
    finally { busy = false; }
  });
})();
