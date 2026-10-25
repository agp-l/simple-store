(() => {
  const picker = document.getElementById('product-variant');
  const price = document.querySelector('[data-variant-price]');
  const stock = document.querySelector('[data-variant-stock]');
  const btc = document.querySelector('[data-variant-btc]');
  if (!picker || !stock) return;
  const editing = Boolean(picker.closest('.is-editing'));
  const mainImage = document.getElementById('detail-image');
  const initialImage = picker.dataset.baseImage || mainImage?.getAttribute('src');
  const selectImage = image => {
    const thumbnail = Array.from(document.querySelectorAll('[data-gallery-image]'))
      .find(button => button.dataset.galleryImage === image);
    thumbnail?.click();
  };
  // The editable base price must stay intact while previewing a variant photo.
  picker.addEventListener('change', () => {
    selectImage(picker.selectedOptions[0]?.dataset.image || initialImage);
  });
  if (editing || !price) return;
  const buy = document.getElementById('detail-add');
  const initial = { price: picker.dataset.basePrice, stock: picker.dataset.baseStock, btc: picker.dataset.baseBtc, waiting: picker.dataset.baseState !== 'in_stock' };
  picker.addEventListener('change', () => {
    const option = picker.selectedOptions[0];
    if (buy) {
      buy.disabled = picker.dataset.published !== '1' || option?.dataset.state === 'out_of_stock' || (!option?.value && picker.dataset.baseState === 'out_of_stock');
      buy.textContent = picker.dataset.published !== '1' ? 'Neveřejný koncept' : (buy.disabled ? 'Není skladem' : 'Přidat do košíku');
    }
    if (!option?.value) {
      price.textContent = initial.price; stock.textContent = initial.stock;
      if (btc) btc.textContent = initial.btc;
      stock.parentElement.classList.toggle('stock-wait', initial.waiting);
      return;
    }
    price.textContent = new Intl.NumberFormat('cs-CZ').format(Number(option.dataset.price));
    stock.textContent = option.dataset.stock;
    stock.parentElement.classList.toggle('stock-wait', option.dataset.state !== 'in_stock');
    if (btc) btc.textContent = option.dataset.btc || '';
  });
  // Enhance the complete PHP picker with one accessible select per attribute.
  const options = Array.from(picker.options).filter(option => option.value);
  const variants = options.map(option => ({ option, choices: JSON.parse(option.dataset.choices) }));
  if (variants.length === 0) return;
  const names = Object.keys(variants[0].choices);
  const container = document.createElement('div'); container.className = 'variant-pickers';
  const selectors = names.map((name, index) => {
    const label = document.createElement('label'); label.className = 'field-label'; label.textContent = name;
    const select = document.createElement('select'); select.className = 'product-option'; select.required = true;
    select.id = 'variant-choice-' + index; label.htmlFor = select.id;
    const empty = document.createElement('option'); empty.value = ''; empty.textContent = 'Vyberte ' + name.toLocaleLowerCase('cs'); select.append(empty);
    const values = [...new Set(variants.map(variant => variant.choices[name]))];
    values.forEach(value => { const option = document.createElement('option'); option.value = value; option.textContent = value; select.append(option); });
    label.append(select); container.append(label); return select;
  });
  const refresh = (preserveUnavailable = false) => {
    selectors.forEach((select, index) => {
      const allowed = variants.filter(variant => !variant.option.disabled && names.slice(0, index).every((name, previous) => !selectors[previous].value || variant.choices[name] === selectors[previous].value));
      Array.from(select.options).forEach(option => { option.disabled = option.value !== '' && !allowed.some(variant => variant.choices[names[index]] === option.value); });
      if (!preserveUnavailable && select.selectedOptions[0]?.disabled) select.value = '';
    });
    const selected = variants.find(variant => (preserveUnavailable || !variant.option.disabled) && names.every((name, index) => variant.choices[name] === selectors[index].value));
    picker.value = selected?.option.value || '';
    picker.dispatchEvent(new Event('change', { bubbles: true }));
  };
  const setAddress = () => {
    const url = new URL(location.href);
    if (picker.value) url.searchParams.set('variant', picker.value); else url.searchParams.delete('variant');
    if (url.href !== location.href) history.pushState(null, '', url);
  };
  const restore = id => {
    const variant = variants.find(row => row.option.value === id);
    selectors.forEach((select, i) => { select.value = variant?.choices[names[i]] || ''; });
    refresh(true);
  };
  selectors.forEach(select => select.addEventListener('change', () => { refresh(); setAddress(); }));
  window.addEventListener('popstate', () => restore(new URL(location.href).searchParams.get('variant')));
  picker.before(container); picker.hidden = true; picker.required = false;
  const label = document.querySelector('label[for="product-variant"]'); if (label) label.hidden = true;
  const note = document.querySelector('.variant-purchase-note'); if (note) note.hidden = true;
  restore(picker.value);
})();
