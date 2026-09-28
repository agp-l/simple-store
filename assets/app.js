(() => {
  const menuToggle = document.getElementById('menu-toggle');
  const menuBand = document.querySelector('.nav-band');
  const closeMenu = () => {
    menuBand.classList.remove('is-open');
    menuToggle.setAttribute('aria-expanded', 'false');
    menuToggle.setAttribute('aria-label', 'Otevřít nabídku');
  };
  menuToggle.addEventListener('click', () => {
    const open = menuBand.classList.toggle('is-open');
    menuToggle.setAttribute('aria-expanded', String(open));
    menuToggle.setAttribute('aria-label', open ? 'Zavřít nabídku' : 'Otevřít nabídku');
  });
  menuBand.querySelectorAll('a').forEach(link => link.addEventListener('click', closeMenu));
  document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenu(); });
  window.matchMedia('(min-width: 761px)').addEventListener('change', event => {
    if (event.matches) closeMenu();
  });

  const cards = [...document.querySelectorAll('.product-card')];
  const catalog = document.getElementById('catalog');
  const search = document.getElementById('search-input');
  const form = document.getElementById('search-form');
  const format = n => new Intl.NumberFormat('cs-CZ').format(n) + ' Kč';
  const dialog = document.getElementById('cart-dialog');
  let cart = [];
  try {
    cart = JSON.parse(localStorage.getItem('dobrodruzi-cart') || '[]');
    if (!Array.isArray(cart)) cart = [];
  } catch { cart = []; }

  function drawCart() {
    const list = document.getElementById('cart-list');
    list.replaceChildren();
    cart.forEach((item, index) => {
      const li = document.createElement('li');
      const label = document.createElement('span');
      label.textContent = item.name + ' · ' + format(item.price);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.textContent = 'Odebrat';
      remove.setAttribute('aria-label', 'Odebrat ' + item.name);
      remove.addEventListener('click', () => { cart.splice(index, 1); drawCart(); });
      li.append(label, remove);
      list.append(li);
    });
    document.getElementById('cart-count').textContent = cart.length;
    document.getElementById('cart-empty').hidden = cart.length !== 0;
    document.getElementById('cart-total').textContent =
      format(cart.reduce((sum, item) => sum + Number(item.price), 0));
    try { localStorage.setItem('dobrodruzi-cart', JSON.stringify(cart)); } catch {}
  }
  window.DobrodruziCart = {
    add(item, count = 1) {
      for (let i = 0; i < count; i++) cart.push(item);
      drawCart();
      dialog.showModal();
    }
  };
  document.getElementById('cart-open').addEventListener('click', () => dialog.showModal());
  document.getElementById('cart-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  drawCart();

  if (!catalog) {
    form.addEventListener('submit', event => {
      event.preventDefault();
      const catalogUrl = document.querySelector('.identity').getAttribute('href');
      location.href = catalogUrl + '?search=' + encodeURIComponent(search.value.trim()) + '#produkty';
    });
    return;
  }

  const sort = document.getElementById('sort');
  function update() {
    const term = search.value.trim().toLocaleLowerCase('cs');
    let count = 0;
    cards.forEach(card => {
      card.hidden = Boolean(term) && !card.textContent.toLocaleLowerCase('cs').includes(term);
      if (!card.hidden) count++;
    });
    document.getElementById('result-count').textContent =
      `Zobrazeno ${count} ${count === 1 ? 'produkt' : count > 1 && count < 5 ? 'produkty' : 'produktů'}`;
    document.getElementById('empty').hidden = count !== 0;
  }
  form.addEventListener('submit', event => {
    event.preventDefault();
    document.getElementById('produkty').scrollIntoView({ behavior: 'smooth' });
    update();
  });
  search.addEventListener('input', update);
  sort.addEventListener('change', () => {
    const ordered = [...cards];
    if (sort.value === 'price-asc') ordered.sort((a, b) => +a.dataset.price - +b.dataset.price);
    else if (sort.value === 'price-desc') ordered.sort((a, b) => +b.dataset.price - +a.dataset.price);
    else if (sort.value === 'name') ordered.sort((a, b) => a.dataset.name.localeCompare(b.dataset.name, 'cs'));
    else ordered.sort((a, b) => cards.indexOf(a) - cards.indexOf(b));
    ordered.forEach(card => catalog.append(card));
  });
  document.querySelectorAll('[data-add]').forEach(button => button.addEventListener('click', () => {
    const card = button.closest('.product-card');
    window.DobrodruziCart.add({ name: card.dataset.name, price: Number(card.dataset.price) });
  }));
  const params = new URLSearchParams(location.search);
  if (params.has('search')) { search.value = params.get('search'); update(); }
})();
