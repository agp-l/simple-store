  <a class="skip" href="#produkty">Přejít na obsah</a>
  <header id="nahoru">
    <div class="brand-row">
      <div class="wrap brand-inner">
        <a class="identity" href="<?= $siteRoot ?>index.php" aria-label="Dobrodruzi.cz – nahoru">
          <svg class="logo-mark" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 20 10 5l3 7 2-4 6 12Z" /></svg>
          <span class="logo">dobrodruzi</span>
        </a>
        <button class="menu-toggle" type="button" id="menu-toggle" aria-controls="main-nav" aria-expanded="false" aria-label="Otevřít nabídku"><span></span><span></span><span></span></button>
        <form class="search" id="search-form" role="search"><svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="10.5" cy="10.5" r="6.5" />
            <path d="m15.5 15.5 5 5" />
          </svg><input type="search" id="search-input" aria-label="Hledat produkty" placeholder="Co hledáte do výbavy?"
            autocomplete="off"><button type="submit">Hledat</button></form>
        <div class="header-actions"><span class="account">Proxy e-shop</span><button class="cart-button"
            type="button" id="cart-open" aria-label="Otevřít ukázkový košík"><svg viewBox="0 0 24 24"
              aria-hidden="true">
              <path d="M3 4h2l2 11h11l3-8H6M9 20h.01M18 20h.01" />
            </svg><span>Košík</span><span class="cart-count" id="cart-count">0</span></button></div>
      </div>
    </div>
    <div class="masthead">
      <div class="wrap utility">
        <p class="hero-tag">Paralelní společnost</p>
        <span class="utility-right"><svg class="bitcoin-mark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" aria-hidden="true">
  <circle cx="32" cy="32" r="32" fill="#F7931A"/>
  <path d="M44.6 26.8c.5-3.6-2.2-5.5-6-6.8l1.2-5-3-0.7-1.2 4.9c-.8-.2-1.6-.4-2.4-.6l1.2-5-3-.7-1.2 4.9c-.7-.2-1.3-.3-2-.5l0 0-4.2-1-0.8 3.3s2.3.5 2.2.6c1.2.3 1.5 1.1 1.4 1.8l-1.4 5.6c.1 0 .2.2.3.2l-0.3-.1-2.2 9c-.2.4-.5 1.1-1.4.9 0 0-2.2-.6-2.2-.6l-1.5 3.5 4 1c.7.2 1.5.4 2.2.6l-1.3 5.1 3 .7 1.2-5c.8.2 1.6.4 2.4.6l-1.2 5 3 .7 1.2-5.1c5.1 1 9 0.6 10.6-4 1.3-3.8-.1-5.9-2.8-7.3 2-0.5 3.5-1.9 3.9-4.8zm-7 10.5c-1 3.9-7.4 1.8-9.4 1.3l1.7-6.7c2 .5 8.7 1.5 7.7 5.4zm1-10.6c-.9 3.6-6.2 1.8-7.9 1.3l1.5-6.1c1.7.4 7.3 1.3 6.4 4.8z" fill="#FFF"/>
</svg>Přijímáme pouze bitcoin</span></div>
      <?php require __DIR__ . '/menu.php'; ?>
      <div class="wrap hero">
        <div class="hero-copy">
          <h1>Vybavení na každou cestu.</h1>
          <p>Výběr toho nejlepšího ultralehkého vybavení pro nomády a cestovatele.</p>
        </div>
      </div>
    </div>
    <div class="mountain-edge" aria-hidden="true">
      <svg viewBox="0 0 900 220" preserveAspectRatio="xMaxYMax slice" focusable="false">
        <path d="M0 220C62 211 117 203 165 192L294 112 338 148 465 16 553 129 682 68 792 164 900 220Z"/>
        <path class="mountain-ridge" d="M465 16 432 58 465 48 490 80M682 68 653 106 683 96 710 120M294 112 272 142 294 137"/>
      </svg>
    </div>
  </header>
