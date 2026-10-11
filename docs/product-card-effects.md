# Optional desktop product-card effects

The experimental storefront enhancement adds a pointer-following tilt (maximum
4 degrees), a green border and a subtle neutral white light reflection over product
photos. It is an independent implementation inspired by the Halloween cards at
https://github.mn/agp-l/halloween, without copying their assets or application code.

## Enable or disable

Change `enabled` in `config/product-card-effects.php` to `true` or `false`.
Disabled means neither of the additional assets is included. No database change
is required. The existing `style.css` and product-card markup are unchanged.

The module uses `assets/product-card-effects.css` and
`assets/product-card-effects.js`, included by `view/head.php` and `view/shell.php`.
To remove it entirely, remove those conditional inclusions and the
`$productCardEffects` configuration in the shell, then delete its config and assets.

## Scope and accessibility

- Only public catalog/homepage cards, including dynamically loaded cards.
- No effects in product management, homepage selection, checkout or admin panels.
- Requires a viewport of at least 1024 px, a fine primary pointer and hover support.
- Requires the system's reduced-motion preference to be off; only mouse events act.
- No automatic idle animation, dependency or network request to another service.
- Decorative overlays do not intercept clicks. Links and cart forms are unchanged.
- Movement resets on leaving the card, keyboard focus, scroll, resize, tab visibility
  changes, window blur and changes to motion/device preferences.

For visual acceptance, compare enabled/disabled cards on desktop, check product
links and cart buttons, load more products, then check mobile and reduced motion.
