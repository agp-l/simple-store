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

  const categoryButtons = [...document.querySelectorAll('.filters .filter')];
  const subcategoryButtons = [...document.querySelectorAll('.backpack-filters .filter')];
  const subcategoryMenu = document.getElementById('backpack-filters');
  const categoryMenu = document.querySelector('.filters');
  const sort = document.getElementById('sort');
  const categories = {
    all: ['Vybavení do přírody', 'Objevte vybavení', 'Poctivý výběr pro pohodlí na stezce i mimo ni.'],
    batohy: ['Batohy', 'Batohy na cesty', 'Vyberte si batoh podle velikosti a využití.'],
    stany: ['Stany', 'Stany na cesty', 'Vybavení pro noc venku.'],
    spacaky: ['Spacáky', 'Spacáky', 'Výbava pro pohodlný spánek venku.'],
    vybaveni: ['Vybavení', 'Drobné vybavení', 'Užitečné věci na cesty.'],
    obleceni: ['Oblečení', 'Oblečení na cesty', 'Vrstvy pro pohyb venku.'],
    boty: ['Boty', 'Boty na cesty', 'Obuv pro stezky i delší putování.']
  };
  const validSubcategories = new Set(['all', 'do-25', '25-50', 'nad-50', 'prislusenstvi']);
  let activeCategory = 'all';
  let activeSubcategory = 'all';

  function setQuery() {
    try {
      const url = new URL(location.href);
      if (activeCategory === 'all') url.searchParams.delete('category');
      else url.searchParams.set('category', activeCategory);
      if (activeCategory === 'batohy' && activeSubcategory !== 'all')
        url.searchParams.set('subcategory', activeSubcategory);
      else url.searchParams.delete('subcategory');
      history.replaceState(null, '', url);
    } catch {}
  }
  function update() {
    const term = search.value.trim().toLocaleLowerCase('cs');
    let count = 0;
    cards.forEach(card => {
      const categoryMatches = activeCategory === 'all' || card.dataset.category === activeCategory;
      const subcategoryMatches = activeCategory !== 'batohy' || activeSubcategory === 'all' ||
        card.dataset.subcategory === activeSubcategory;
      const searchMatches = !term || card.textContent.toLocaleLowerCase('cs').includes(term);
      card.hidden = !(categoryMatches && subcategoryMatches && searchMatches);
      if (!card.hidden) count++;
    });
    document.getElementById('result-count').textContent =
      `Zobrazeny ${count} ${count === 1 ? 'produkt' : count > 1 && count < 5 ? 'produkty' : 'produktů'}`;
    document.getElementById('empty').hidden = count !== 0;
  }
  function updateButtons() {
    categoryButtons.forEach(button => {
      const selected = button.dataset.filter === activeCategory;
      button.classList.toggle('active', selected);
      button.setAttribute('aria-pressed', String(selected));
    });
    subcategoryButtons.forEach(button => {
      const selected = button.dataset.subcategory === activeSubcategory;
      button.classList.toggle('active', selected);
      button.setAttribute('aria-pressed', String(selected));
    });
    categoryMenu.hidden = activeCategory === 'batohy';
    subcategoryMenu.hidden = activeCategory !== 'batohy';
    const [crumb, title, description] = categories[activeCategory];
    document.getElementById('breadcrumb-category').textContent = crumb;
    document.getElementById('section-title').textContent = title;
    document.getElementById('section-description').textContent = description;
  }
  function chooseCategory(category, save = true) {
    if (!(category in categories)) return;
    activeCategory = category;
    activeSubcategory = 'all';
    updateButtons();
    update();
    if (save) setQuery();
  }
  function chooseSubcategory(subcategory, save = true) {
    if (activeCategory !== 'batohy' || !validSubcategories.has(subcategory)) return;
    activeSubcategory = subcategory;
    updateButtons();
    update();
    if (save) setQuery();
  }
  document.querySelectorAll('[data-filter]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    chooseCategory(link.dataset.filter);
    document.getElementById('produkty').scrollIntoView({ behavior: 'smooth' });
  }));
  subcategoryButtons.forEach(button => button.addEventListener('click', () =>
    chooseSubcategory(button.dataset.subcategory)));
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
    const card = cards[Number(button.dataset.add)];
    window.DobrodruziCart.add({ name: card.querySelector('h3').textContent, price: Number(card.dataset.price) });
  }));
  const params = new URLSearchParams(location.search);
  if (params.has('category')) chooseCategory(params.get('category'), false);
  if (params.has('subcategory')) chooseSubcategory(params.get('subcategory'), false);
  if (params.has('search')) { search.value = params.get('search'); update(); }
})();
