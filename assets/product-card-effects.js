(() => {
  'use strict';

  const catalog = document.getElementById('catalog');
  if (!catalog) return;

  const preference = window.matchMedia('(min-width: 1024px) and (hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)');
  const properties = ['--card-tilt-x', '--card-tilt-y', '--card-light-x', '--card-light-y'];
  let active = null;
  let bounds = null;
  let frame = 0;

  function reset() {
    window.cancelAnimationFrame(frame);
    frame = 0;
    if (active) {
      active.classList.remove('card-effects-active');
      properties.forEach((property) => active.style.removeProperty(property));
    }
    active = null;
    bounds = null;
  }

  // Delegation also covers cards appended by the catalog's load-more feature.
  catalog.addEventListener('pointermove', (event) => {
    if (!preference.matches || event.pointerType !== 'mouse') {
      reset();
      return;
    }
    const card = event.target.closest('.product-card');
    if (!card || !catalog.contains(card)) {
      reset();
      return;
    }
    if (card !== active) {
      reset();
      active = card;
      // Keep an unchanging rectangle so the tilt cannot feed back into itself.
      bounds = card.getBoundingClientRect();
    }
    if (!bounds.width || !bounds.height) return;
    const x = Math.max(0, Math.min(1, (event.clientX - bounds.left) / bounds.width));
    const y = Math.max(0, Math.min(1, (event.clientY - bounds.top) / bounds.height));
    window.cancelAnimationFrame(frame);
    frame = window.requestAnimationFrame(() => {
      frame = 0;
      active.style.setProperty('--card-tilt-x', `${((0.5 - y) * 8).toFixed(2)}deg`);
      active.style.setProperty('--card-tilt-y', `${((x - 0.5) * 8).toFixed(2)}deg`);
      active.style.setProperty('--card-light-x', `${(x * 100).toFixed(1)}%`);
      active.style.setProperty('--card-light-y', `${(y * 100).toFixed(1)}%`);
      active.classList.add('card-effects-active');
    });
  }, { passive: true });

  catalog.addEventListener('pointerout', (event) => {
    if (active && (!event.relatedTarget || !active.contains(event.relatedTarget))) reset();
  }, { passive: true });
  catalog.addEventListener('pointerleave', reset, { passive: true });
  catalog.addEventListener('pointercancel', reset, { passive: true });
  catalog.addEventListener('focusin', reset);
  window.addEventListener('scroll', reset, { passive: true, capture: true });
  window.addEventListener('resize', reset, { passive: true });
  window.addEventListener('blur', reset);
  document.addEventListener('visibilitychange', reset);
  preference.addEventListener('change', reset);
})();
