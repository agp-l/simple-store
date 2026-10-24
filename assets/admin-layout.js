(() => {
  const body = document.body;
  const toggle = document.querySelector('[data-admin-menu]');
  const sidebar = document.getElementById('admin-sidebar');
  const close = sidebar?.querySelector('[data-admin-close]');
  const backdrop = document.querySelector('[data-admin-backdrop]');
  const header = document.querySelector('.admin-header');
  const main = document.querySelector('.panel-main');
  if (!toggle || !sidebar || !close || !backdrop || !header || !main) return;
  const mobile = window.matchMedia('(max-width: 1080px)');
  let opened = false;
  const setOpen = (value, restoreFocus = true) => {
    opened = value && mobile.matches;
    body.classList.toggle('admin-menu-open', opened);
    toggle.setAttribute('aria-expanded', String(opened));
    backdrop.hidden = !opened;
    main.inert = header.inert = opened;
    sidebar.inert = mobile.matches && !opened;
    if (opened) {
      sidebar.setAttribute('role', 'dialog');
      sidebar.setAttribute('aria-modal', 'true');
      document.querySelectorAll('.admin-header details[open]').forEach(item => { item.open = false; });
      close.focus({ preventScroll: true });
    } else {
      sidebar.removeAttribute('role');
      sidebar.removeAttribute('aria-modal');
      if (restoreFocus && mobile.matches) toggle.focus();
    }
  };
  toggle.hidden = close.hidden = false;
  body.classList.add('admin-nav-ready');
  setOpen(false, false);
  toggle.addEventListener('click', () => setOpen(!opened));
  close.addEventListener('click', () => setOpen(false));
  backdrop.addEventListener('click', () => setOpen(false));
  sidebar.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setOpen(false, false)));
  mobile.addEventListener('change', () => {
    const menuHadFocus = sidebar.contains(document.activeElement);
    setOpen(false, menuHadFocus);
    if (!mobile.matches && document.activeElement === close) sidebar.querySelector('a')?.focus();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      if (opened) { event.preventDefault(); setOpen(false); }
      document.querySelectorAll('.admin-header details[open]').forEach(item => { item.open = false; item.querySelector('summary').focus(); });
    }
    if (!opened || event.key !== 'Tab') return;
    const focusable = [...sidebar.querySelectorAll('a[href], button:not(:disabled), input:not([type="hidden"]):not(:disabled)')]
      .filter(item => item.getClientRects().length > 0);
    const first = focusable[0], last = focusable.at(-1);
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  });
  document.addEventListener('click', event => {
    document.querySelectorAll('.admin-header details[open]').forEach(item => {
      if (!item.contains(event.target)) item.open = false;
    });
  });
  // Keep the selected tab visible without moving the page vertically.
  document.querySelectorAll('.panel-settings-nav, .panel-accounting-nav, .panel-order-filters, .panel-order-tabs').forEach(nav => {
    const active = nav.querySelector('[aria-current=page]');
    if (active && nav.scrollWidth > nav.clientWidth) {
      nav.scrollLeft = active.offsetLeft - nav.offsetLeft - 12;
    }
  });
})();
