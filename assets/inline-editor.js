(() => {
  const configNode = document.getElementById('inline-editor-config');
  if (!configNode) return;
  const config = JSON.parse(configNode.textContent);
  const status = document.getElementById('inline-status');
  const previousHtml = new WeakMap();
  const cancelled = new WeakSet();
  const scrollKey = `dobrodruzi-editor-scroll-${config.key}`;
  const statusKey = `dobrodruzi-editor-status-${config.key}`;
  let queue = Promise.resolve();
  let pending = 0;
  let failed = false;
  let refreshTimer;
  let nextUrl = location.href;

  try {
    const oldScroll = sessionStorage.getItem(scrollKey);
    if (oldScroll !== null) {
      sessionStorage.removeItem(scrollKey);
      requestAnimationFrame(() => window.scrollTo(0, Number(oldScroll)));
    }
    const saved = sessionStorage.getItem(statusKey);
    if (saved) { status.textContent = saved; sessionStorage.removeItem(statusKey); }
  } catch {}

  function refreshWhenReady() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => {
      if (pending || document.activeElement?.matches('[data-edit-operation], [data-editor-select], [data-editor-type]')) {
        refreshWhenReady();
        return;
      }
      try {
        sessionStorage.setItem(scrollKey, String(window.scrollY));
        sessionStorage.setItem(statusKey, `Uloženo · revize ${config.revision}`);
      } catch {}
      location.assign(nextUrl + (location.hash || ''));
    }, 380);
  }

  function save(operation, field = '', value = '', index = null) {
    if (failed) return;
    clearTimeout(refreshTimer);
    pending++;
    queue = queue.then(async () => {
      if (failed) return;
      status.textContent = 'Ukládám…';
      const body = new URLSearchParams({
        action: 'inline-product', csrf: config.csrf, key: config.key,
        language: config.language, revision: String(config.revision),
        operation, field, value
      });
      if (index !== null) body.set('index', String(index));
      const response = await fetch(config.endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
        body: body.toString()
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.revision) {
        throw new Error(result.error || 'Server úpravu nepotvrdil. Obnov stránku.');
      }
      config.revision = result.revision;
      nextUrl = result.url;
      pending--;
      status.textContent = `Uloženo · revize ${config.revision}`;
      if (pending === 0) refreshWhenReady();
    }).catch(error => {
      failed = true;
      pending = 0;
      clearTimeout(refreshTimer);
      status.textContent = `Nepodařilo se uložit: ${error.message}`;
      status.classList.add('inline-error');
    });
  }

  document.addEventListener('click', event => {
    const link = event.target.closest('[data-edit-operation] a');
    if (link) { event.preventDefault(); link.closest('[data-edit-operation]').focus(); }
  });
  document.addEventListener('focusin', event => {
    const target = event.target.closest('[data-edit-operation]');
    if (!target || failed) return;
    clearTimeout(refreshTimer);
    previousHtml.set(target, target.innerHTML);
    target.textContent = target.dataset.editValue;
    target.classList.add('inline-focused');
  });
  document.addEventListener('focusout', event => {
    const target = event.target.closest('[data-edit-operation]');
    if (!target || !previousHtml.has(target)) return;
    target.classList.remove('inline-focused');
    if (cancelled.has(target)) {
      cancelled.delete(target);
      target.innerHTML = previousHtml.get(target);
      return;
    }
    const original = target.dataset.editValue;
    let value = (target.innerText || target.textContent || '').replace(/\r/g, '').trim();
    if (target.dataset.editField === 'price_czk') value = value.replace(/[^0-9]/g, '');
    if (value === original || failed) {
      target.innerHTML = previousHtml.get(target);
      return;
    }
    save(target.dataset.editOperation, target.dataset.editField, value,
      target.hasAttribute('data-edit-index') ? Number(target.dataset.editIndex) : null);
  });
  document.addEventListener('paste', event => {
    if (!event.target.closest('[data-edit-operation]')) return;
    event.preventDefault();
    document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && event.target.matches('[data-edit-operation]')) {
      cancelled.add(event.target);
      event.target.blur();
    }
    if (event.key === 'Enter' && !event.shiftKey && event.target.matches('h1[data-edit-operation], h3[data-edit-operation], span[data-edit-operation], th[data-edit-operation], td[data-edit-operation]')) {
      event.preventDefault(); event.target.blur();
    }
  });

  document.addEventListener('change', event => {
    const field = event.target.dataset.editorSelect;
    if (field) { save('set', field, event.target.value); return; }
    if (event.target.dataset.editorType === 'section') {
      if (!confirm('Změna typu nahradí text tohoto bloku výchozím obsahem. Pokračovat?')) {
        location.reload(); return;
      }
      save('section.set', 'section_type', event.target.value, Number(event.target.dataset.editorIndex));
    }
  });

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-editor-action]');
    if (!button || failed) return;
    const action = button.dataset.editorAction;
    const index = button.hasAttribute('data-editor-index') ? Number(button.dataset.editorIndex) : null;
    if (action === 'choose-block') {
      const choices = button.parentElement.querySelector('.inline-block-types');
      choices.hidden = !choices.hidden;
      return;
    }
    if (action === 'option-values') {
      const editable = button.closest('.inline-option').querySelector('.inline-choices');
      editable.hidden = false;
      editable.focus();
      return;
    }
    if (action === 'main-image' || action === 'gallery-add' || action === 'gallery-set' || action === 'section-image') {
      const old = action === 'main-image' ? document.getElementById('detail-image').dataset.imagePath :
        (button.dataset.editorValue || 'images/batoh.webp');
      const value = prompt('Cesta v images/ nebo HTTPS adresa fotografie:', old);
      if (value === null || value.trim() === old) return;
      if (action === 'main-image') save('set', 'image_path', value.trim());
      else if (action === 'gallery-add') save('gallery.add', '', value.trim());
      else if (action === 'gallery-set') save('gallery.set', '', value.trim(), index);
      else save('section.set', 'section_body', value.trim(), index);
      return;
    }
    if (action === 'publish') {
      if (button.dataset.value === '1' && !confirm('Před zveřejněním zkontroluj ukázkové texty, cenu i fotografie. Publikovat produkt?')) return;
      save('set', 'published', button.dataset.value);
      return;
    }
    if (action === 'restore') {
      if (confirm(`Načíst revizi ${button.dataset.editorValue} jako novou revizi?`)) {
        save('restore', '', button.dataset.editorValue);
      }
      return;
    }
    if (action.endsWith('-remove')) {
      if (confirm('Odebrat položku? Předchozí verze zůstane v historii.')) {
        save(action.replace('-', '.'), '', '', index);
      }
      return;
    }
    if (action === 'section-up' || action === 'section-down') {
      save('section.move', '', action === 'section-up' ? 'up' : 'down', index);
      return;
    }
    if (action === 'section-add') save('section.add', '', button.dataset.editorValue, index);
    if (action === 'option-add') save('option.add');
    if (action === 'spec-add') save('spec.add');
  });

  window.addEventListener('beforeunload', event => {
    if (!pending || failed) return;
    event.preventDefault();
    event.returnValue = '';
  });
})();
