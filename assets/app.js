(() => {
  const menuToggle = document.getElementById('menu-toggle');
  const menuBand = document.querySelector('.nav-band');
  const closeMenu = () => {
    menuBand.classList.remove('is-open');
    document.querySelectorAll('.nav-dropdown[open]').forEach(dropdown => { dropdown.open = false; });
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

  const catalog = document.getElementById('catalog');
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

  if (!catalog) return;

  const sort = document.getElementById('sort');
  function updateCount() {
    const count = catalog.querySelectorAll('.product-card').length;
    document.getElementById('result-count').textContent =
      `Načteno ${count} ${count === 1 ? 'produkt' : count > 1 && count < 5 ? 'produkty' : 'produktů'}`;
    document.getElementById('empty').hidden = count !== 0;
  }
  sort?.addEventListener('change', () => {
    const next = new URL(location.href);
    next.searchParams.delete('offset');
    if (sort.value === 'default') next.searchParams.delete('sort');
    else next.searchParams.set('sort', sort.value);
    next.hash = 'produkty';
    location.assign(next.href);
  });
  catalog.addEventListener('click', event => {
    const button = event.target.closest('[data-add]');
    if (!button || button.disabled) return;
    const card = button.closest('.product-card');
    window.DobrodruziCart.add({ name: card.dataset.name, price: Number(card.dataset.price) });
  });
  catalog.addEventListener('catalog:more', updateCount);
})();
