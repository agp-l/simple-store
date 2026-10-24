(() => {
  const form = document.querySelector('[data-mail-form]');
  if (!form) return;
  let dirty = form.dataset.dirty === 'true';
  let submitting = false;
  const state = form.querySelector('[data-mail-state]');
  const markDirty = () => { dirty = true; state.textContent = 'Rozepsané změny nejsou uložené'; };
  form.addEventListener('input', event => { if (!event.target.matches('[data-mail-search], [name="preview_code"], [name="preview_mode"]')) markDirty(); });
  form.addEventListener('change', event => { if (!event.target.matches('[name="preview_code"], [name="preview_mode"]')) markDirty(); });
  form.addEventListener('submit', () => { submitting = true; });
  form.addEventListener('invalid', event => { event.target.closest('details')?.setAttribute('open', ''); }, true);
  window.addEventListener('beforeunload', event => { if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; } });
  form.querySelectorAll('[data-mail-reset]').forEach(button => {
    button.hidden = false;
    button.addEventListener('click', () => {
      button.closest('[data-mail-group]').querySelectorAll('[data-mail-default]').forEach(field => { field.value = field.dataset.mailDefault; });
      markDirty();
      state.textContent = 'Výchozí texty obnoveny v rozepsaném formuláři. Pro použití je uložte.';
    });
  });
  form.querySelectorAll('[data-mail-preview-code]').forEach(button => {
    button.hidden = false;
    button.addEventListener('click', () => {
      form.elements.preview_code.value = button.dataset.mailPreviewCode;
      form.requestSubmit(form.querySelector('button[value="preview-mail"]'));
    });
  });
  const search = form.querySelector('[data-mail-search]');
  form.querySelector('[data-mail-search-wrap]').hidden = false;
  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('cs');
  search.addEventListener('input', () => {
    const query = normalize(search.value.trim());
    let found = 0;
    form.querySelectorAll('[data-mail-group]').forEach(group => {
      const values = Array.from(group.querySelectorAll('input,textarea')).map(el => el.value).join(' ');
      const matches = !query || normalize(group.textContent + ' ' + values).includes(query);
      if (!query && group.dataset.mailSearchOpen !== undefined) { group.open = group.dataset.mailSearchOpen === 'true'; delete group.dataset.mailSearchOpen; }
      if (query && group.dataset.mailSearchOpen === undefined) group.dataset.mailSearchOpen = String(group.open);
      group.hidden = !matches;
      if (query && matches) group.open = true;
      if (matches) found++;
    });
    form.querySelector('[data-mail-empty]').hidden = found !== 0;
  });
  const deviceButtons = document.querySelector('.mail-device-buttons');
  if (deviceButtons) {
    deviceButtons.hidden = false;
    deviceButtons.querySelectorAll('[data-mail-width]').forEach(button => button.addEventListener('click', () => {
      document.querySelector('.mail-preview-stage').classList.toggle('is-mobile', button.dataset.mailWidth === 'mobile');
      deviceButtons.querySelectorAll('button').forEach(other => other.setAttribute('aria-pressed', String(other === button)));
    }));
  }
})();
