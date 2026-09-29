(() => {
  const configNode = document.getElementById('content-editor-config');
  if (!configNode) return;
  const config = JSON.parse(configNode.textContent);
  const status = document.getElementById('content-editor-status');
  const previousHtml = new WeakMap();
  const cancelled = new WeakSet();
  const scrollKey = `dobrodruzi-content-scroll-${config.key}`;
  const statusKey = `dobrodruzi-content-status-${config.key}`;
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
      if (pending || document.getElementById('media-picker')?.open ||
          document.activeElement?.matches('[data-edit-operation], [data-content-select], [data-content-input], [data-content-type]')) {
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

  window.SimpleStoreMediaContext = {
    ...config, basePath: new URL('.', new URL(config.endpoint, location.href)).pathname,
    getRevision: async () => { await queue; if (failed) throw new Error('Nejdřív obnov stránku po chybě uložení.'); return config.revision; },
    choose: (path, mode, index) => {
      if (mode === 'section-image') save('section.set', 'section_body', path, index);
      else save('section.image.add', '', path, index);
    },
    uploaded: result => {
      config.revision = result.revision;
      nextUrl = result.url;
      status.textContent = `Uloženo · revize ${config.revision}`;
      refreshWhenReady();
    }
  };

  function save(operation, field = '', value = '', index = null) {
    if (failed) return;
    clearTimeout(refreshTimer);
    pending++;
    queue = queue.then(async () => {
      if (failed) return;
      status.textContent = 'Ukládám…';
      const body = new URLSearchParams({
        action: 'inline-content', csrf: config.csrf, key: config.key,
        type: config.type, language: config.language, revision: String(config.revision),
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
    const value = (target.innerText || target.textContent || '').replace(/\r/g, '').trim();
    if (value === target.dataset.editValue || failed) {
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
      cancelled.add(event.target); event.target.blur();
    }
    if (event.key === 'Enter' && !event.shiftKey && event.target.matches('h1[data-edit-operation], h2[data-edit-operation], span[data-edit-operation]')) {
      event.preventDefault(); event.target.blur();
    }
  });

  document.addEventListener('change', event => {
    if (event.target.dataset.contentSelect) {
      save('set', event.target.dataset.contentSelect, event.target.value);
    } else if (event.target.dataset.contentInput) {
      save('set', event.target.dataset.contentInput, event.target.value);
    } else if (event.target.hasAttribute('data-content-type')) {
      if (!confirm('Změna typu nahradí text bloku ukázkovým obsahem. Pokračovat?')) {
        event.target.value = event.target.querySelector('option[selected]')?.value || 'text';
        return;
      }
      save('section.set', 'section_type', event.target.value, Number(event.target.dataset.index));
    }
  });

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-content-action]');
    if (!button || failed) return;
    const action = button.dataset.contentAction;
    const index = button.hasAttribute('data-index') ? Number(button.dataset.index) : null;
    if (action === 'choose-block') {
      const choices = button.parentElement.querySelector('.inline-block-types');
      choices.hidden = !choices.hidden;
      return;
    }
    if (action === 'section-image') {
      window.SimpleStoreMedia.open('section-image', index, button.dataset.value);
      return;
    }
    if (action === 'media-library' || action === 'section-add-image') {
      window.SimpleStoreMedia.open('section-add-image', index);
      return;
    }
    if (action === 'publish') {
      if (button.dataset.value === '1' && !confirm('Před zveřejněním zkontroluj ukázkové texty, tabulku i fotografii. Publikovat?')) return;
      save('set', 'published', button.dataset.value);
      return;
    }
    if (action === 'restore') {
      if (confirm(`Načíst revizi ${button.dataset.value} jako novou revizi?`)) {
        save('restore', '', button.dataset.value);
      }
      return;
    }
    if (action === 'section-remove') {
      if (confirm('Odebrat blok? Předchozí verze zůstane v historii.')) {
        save('section.remove', '', '', index);
      }
      return;
    }
    if (action === 'section-up' || action === 'section-down') {
      save('section.move', '', action === 'section-up' ? 'up' : 'down', index);
      return;
    }
    if (action === 'section-add') {
      if (button.dataset.value === 'image') window.SimpleStoreMedia.open('section-add-image', index);
      else save('section.add', '', button.dataset.value, index);
    }
  });

  window.addEventListener('beforeunload', event => {
    if (!pending || failed) return;
    event.preventDefault(); event.returnValue = '';
  });
})();
