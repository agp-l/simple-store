(() => {
  const form = document.querySelector('[data-product-sale-form]');
  if (!form) return;
  const field = name => form.elements.namedItem(name);
  const assistance = form.querySelector('[data-sale-assistance]');
  const partner = field('partner_id');
  const partnerEdit = form.querySelector('[data-sale-partner-edit]');
  const partnerStatus = form.querySelector('[data-sale-partner-status]');
  const readiness = form.querySelector('[data-sale-readiness]');
  const state = form.querySelector('[data-sale-save-state]');
  const newPartner = form.querySelector('[data-sale-new]');
  const newFields = Array.from(newPartner.querySelectorAll('input'));
  const variants = Array.from(form.querySelectorAll('[data-sale-variant]'));
  const money = value => new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 }).format(value) + ' Kč';
  const price = input => /^\d+$/.test(input.value) && Number(input.value) >= 1 && Number(input.value) <= 9999999 ? Number(input.value) : null;
  const hasNew = () => newFields.some(input => input.type !== 'checkbox' && input.value.trim() !== '');
  const newReady = () => {
    try {
      const terms = new URL(field('partner_terms_url').value);
      return field('partner_name').value.trim() !== '' && /^\d{8}$/.test(field('partner_ico').value.trim()) &&
        field('partner_address').value.trim() !== '' && field('partner_email').value !== '' && field('partner_email').validity.valid &&
        terms.protocol === 'https:' && !terms.username && !terms.password && field('partner_confirmed').checked;
    } catch { return false; }
  };
  const initial = new URLSearchParams(new FormData(form)).toString();
  let basePrice = Number(form.dataset.basePrice);
  let recovered = form.dataset.recovered === '1';
  let submitted = false;
  let previousPartner = partner.value;

  const cancelNew = document.createElement('button');
  cancelNew.type = 'button'; cancelNew.className = 'sale-button sale-button--secondary';
  cancelNew.textContent = 'Zrušit nového';
  newPartner.querySelector('.sale-disclosure-body').append(cancelNew);
  cancelNew.addEventListener('click', () => {
    for (const input of newFields) {
      if (input.type === 'checkbox') input.checked = false;
      else input.value = '';
    }
    if (partner.value === '0') partner.value = previousPartner;
    newPartner.open = false;
    update();
    partner.focus();
  });

  function update() {
    const assisted = field('mode').value === 'assisted';
    assistance.hidden = !assisted && !recovered;
    const cost = price(field('supplier_price_czk'));
    const fee = form.querySelector('[data-sale-fee]');
    fee.textContent = cost === null ? '—' : money(basePrice - cost);
    fee.classList.toggle('sale-negative', cost !== null && cost > basePrice);
    form.querySelector('[data-sale-base-price]').textContent = money(basePrice);
    const selected = partner.selectedOptions[0];
    partnerEdit.hidden = partner.value === '0';
    if (partner.value !== '0') {
      const editUrl = new URL(partnerEdit.dataset.editBase, location.href);
      editUrl.searchParams.set('id', partner.value); editUrl.hash = 'partner-form';
      partnerEdit.href = editUrl.href;
    }
    const creating = hasNew();
    cancelNew.hidden = !creating && !newFields.some(input => input.checked);
    partnerStatus.textContent = partner.value === '0' && creating ? (newReady() ? 'Nový profil je připravený k uložení.' : 'Nový profil se uloží jako rozpracovaný.') :
      (selected?.dataset.missing || 'Údaje prodávajícího jsou kompletní.');
    let missingCost = false, invalidCost = false, inheritedTooHigh = false;
    for (const row of variants) {
      const input = row.querySelector('input');
      const effectiveCost = input.value === '' ? cost : price(input);
      const retail = row.dataset.base === '1' ? basePrice : Number(row.dataset.price);
      const output = row.querySelector('output');
      row.querySelector('[data-sale-variant-retail]').textContent = money(retail);
      output.textContent = effectiveCost === null ? '—' : money(retail - effectiveCost);
      output.classList.toggle('sale-negative', effectiveCost !== null && effectiveCost > retail);
      const invalid = (input.value !== '' && price(input) === null) || (effectiveCost !== null && effectiveCost > retail);
      input.setAttribute('aria-invalid', invalid ? 'true' : 'false');
      if (row.dataset.enabled === '1') {
        missingCost ||= effectiveCost === null;
        invalidCost ||= invalid;
        inheritedTooHigh ||= input.value === '' && effectiveCost !== null && effectiveCost > retail;
      }
    }
    if (!variants.length) { missingCost = cost === null; invalidCost = cost !== null && cost > basePrice; }
    const badBase = field('supplier_price_czk').value !== '' && cost === null;
    field('supplier_price_czk').setAttribute('aria-invalid', badBase || inheritedTooHigh || (!variants.length && invalidCost) ? 'true' : 'false');
    const inheritPrices = form.querySelector('[data-sale-inherit-prices]');
    if (inheritPrices) inheritPrices.disabled = !variants.some(row => row.querySelector('input').value !== '');
    const missing = [];
    if (missingCost) missing.push('nákupní cenu');
    if (partner.value === '0' && (!creating || !newReady())) missing.push(creating ? 'úplný a ověřený profil nového prodávajícího' : 'prodávajícího');
    else if (selected?.dataset.missing) missing.push('údaje prodávajícího');
    let notice = '';
    if (creating && partner.value !== '0') notice = 'Máš rozepsaného nového prodávajícího. Použij „Uložit nového prodávajícího a nastavení“, nebo „Zrušit nového“.';
    else if (badBase || invalidCost) notice = 'Zkontroluj nákupní ceny: musí být v celých Kč a nesmí převýšit konečnou cenu nabízeného produktu či varianty.';
    else if (missing.length) notice = 'Rozpracováno: doplň ' + missing.join(', ') + '. Uložit můžeš i nyní; objednávka bude možná po doplnění.';
    readiness.textContent = notice;
    readiness.hidden = !notice || !assisted;
    const dirty = recovered || new URLSearchParams(new FormData(form)).toString() !== initial;
    form.dataset.dirty = dirty ? 'true' : 'false';
    state.textContent = dirty ? 'Neuložené změny' : 'Nastavení odpovídá uloženým údajům';
  }
  form.addEventListener('input', event => {
    if (newFields.includes(event.target) && hasNew()) {
      if (partner.value !== '0') previousPartner = partner.value;
      partner.value = '0';
    }
    update();
  });
  form.addEventListener('change', event => {
    if (event.target.name === 'mode') recovered = false;
    update();
  });
  const inheritPrices = form.querySelector('[data-sale-inherit-prices]');
  inheritPrices?.addEventListener('click', event => {
    event.preventDefault();
    variants.forEach(row => { row.querySelector('input').value = ''; });
    update();
  });
  form.addEventListener('submit', () => { submitted = true; });
  window.addEventListener('pageshow', () => { submitted = false; update(); });
  window.addEventListener('beforeunload', event => {
    if (!submitted && form.dataset.dirty === 'true') { event.preventDefault(); event.returnValue = ''; }
  });
  document.addEventListener('product-price-saved', event => {
    if (Number.isInteger(event.detail?.price) && event.detail.price > 0) { basePrice = event.detail.price; update(); }
  });
  update();
})();
