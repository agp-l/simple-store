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
  catalog.addEventListener('catalog:more', updateCount);
})();
