(() => {
  const link = document.querySelector('[data-load-more]');
  if (!link) return;
  const list = link.dataset.target === 'catalog'
    ? document.getElementById('catalog') : document.querySelector('.cms-post-list');
  let loading = false;

  link.addEventListener('click', async event => {
    event.preventDefault();
    if (loading) return;
    loading = true;
    link.setAttribute('aria-disabled', 'true');
    link.textContent = 'Načítám…';
    try {
      const url = new URL(link.href);
      url.searchParams.set('partial', '1');
      const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
      if (!response.ok) throw new Error('Načtení se nezdařilo.');
      const page = await response.json();
      if (typeof page.html !== 'string' || typeof page.nextUrl !== 'string') {
        throw new Error('Neplatná odpověď serveru.');
      }
      const cards = document.createElement('template');
      cards.innerHTML = page.html;
      list.append(cards.content);
      list.dispatchEvent(new Event('catalog:more'));
      if (page.nextUrl) {
        link.href = page.nextUrl;
        link.textContent = link.dataset.target === 'catalog' ? 'Načíst další produkty' : 'Načíst další články';
      } else {
        link.parentElement.remove();
      }
    } catch (error) {
      link.textContent = 'Nepodařilo se načíst. Zkusit znovu';
    } finally {
      loading = false;
      link.removeAttribute('aria-disabled');
    }
  });
})();
