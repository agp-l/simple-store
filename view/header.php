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
        <nav class="utility-right" aria-label="Stránky a blog">
          <?php foreach ($utilityMenu as $link): ?>
            <a href="<?= htmlspecialchars($link['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $link['active'] ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($link['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          <?php endforeach; ?>
        </nav>
      </div>
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
