  <a class="skip" href="#<?= htmlspecialchars($skipTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Přejít na obsah</a>
  <header id="nahoru"<?= $compactHeader ? ' class="compact-header"' : '' ?>>
    <div class="brand-row">
      <div class="wrap brand-inner">
        <a class="identity" href="<?= $siteRoot . htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" aria-label="Dobrodruzi.cz – nahoru">
          <svg class="logo-mark" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 20 10 5l3 7 2-4 6 12Z" /></svg>
          <span class="logo">dobrodruzi</span>
        </a>
        <button class="menu-toggle" type="button" id="menu-toggle" aria-controls="main-nav" aria-expanded="false" aria-label="Otevřít nabídku"><span></span><span></span><span></span></button>
        <form class="search" id="search-form" role="search" method="get" action="<?= htmlspecialchars($searchAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?php if ($managingCatalog ?? false): ?><input type="hidden" name="manage" value="1"><?php if ($catalogVisibility !== 'all'): ?><input type="hidden" name="visibility" value="<?= htmlspecialchars($catalogVisibility, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?php endif; ?><?php endif; ?><svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="10.5" cy="10.5" r="6.5" />
            <path d="m15.5 15.5 5 5" />
          </svg><input type="search" id="search-input" name="search" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" aria-label="Hledat produkty" placeholder="Co hledáte do výbavy?"
            autocomplete="off"><button type="submit">Hledat</button></form>
        <div class="header-actions"><a class="account" href="<?= $siteRoot ?>account.php" aria-label="Přihlásit se nebo otevřít můj účet"><span class="account-desktop">Přihlášení</span><span class="account-mobile" aria-hidden="true">Účet</span></a><a class="account account-register" href="<?= $siteRoot ?>account.php?mode=register">Registrace</a><a class="cart-button"
            href="<?= htmlspecialchars($cartUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" aria-label="Otevřít košík"><svg viewBox="0 0 24 24"
              aria-hidden="true">
              <path d="M3 4h2l2 11h11l3-8H6M9 20h.01M18 20h.01" />
            </svg><span>Košík</span><span class="cart-count" id="cart-count"><?= (int) $cartCount ?></span></a></div>
      </div>
    </div>
    <div class="masthead">
      <div class="wrap utility">
        <p></p>
        <nav class="utility-right hero-tag" aria-label="Stránky a blog">
          <?php foreach ($utilityMenu as $link): ?>
            <?php if ($manualUtilityMenu && $link['children'] !== []): ?><?php require __DIR__ . '/nav-dropdown.php'; ?>
            <?php else: ?><a href="<?= htmlspecialchars($link['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $link['active'] ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($link['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a><?php endif; ?>
          <?php endforeach; ?>
          <?php if ($canManageMenu && !($canManageCatalog ?? false) && !($canEditProduct ?? false)): ?><a class="menu-admin-shortcut" href="<?= htmlspecialchars($menuAdminUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">✎ Upravit menu</a><?php endif; ?>
        </nav>
      </div>
      <?php require __DIR__ . '/menu.php'; ?>
      <div class="wrap hero">
        <div class="hero-copy">
          <h1><?= htmlspecialchars($heroTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
          <p><?= htmlspecialchars($heroSubtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
      </div>
    </div>
    <div class="mountain-edge" aria-hidden="true">
      <svg viewBox="0 0 900 220" preserveAspectRatio="xMaxYMax slice" focusable="false">
        <g class="mountain-constellation">
          <path class="mountain-star-line" d="M612 55 650 43 693 56 733 49 756 20 808 31 795 70 733 49"/>
          <path d="M612 51v8m-4-4h8M733 45v8m-4-4h8M808 27v8m-4-4h8"/>
          <g class="mountain-star-points">
            <circle cx="650" cy="43" r="2"/><circle cx="693" cy="56" r="2"/>
            <circle cx="756" cy="20" r="2"/><circle cx="795" cy="70" r="2"/>
          </g>
        </g>
        <g transform="translate(0 29) scale(1 .87)">
          <path d="M0 220C62 211 117 203 165 192L294 112 338 148 465 16 553 129 682 68 792 164 900 220Z"/>
          <path class="mountain-ridge" d="M465 16 432 58 465 48 490 80M682 68 653 106 683 96 710 120M294 112 272 142 294 137"/>
        </g>
      </svg>
    </div>
  </header>
  <?php if ($adminCreate !== null): ?>
    <nav class="site-admin-bar" aria-label="Správa webu">
      <div class="wrap site-admin-inner"><strong>Správa webu</strong>
        <?php if (!in_array($page, ['catalog', 'product-record'], true)): ?><form method="post" action="<?= $siteRoot ?>admin.php"><input type="hidden" name="csrf" value="<?= htmlspecialchars($adminCreate['csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><input type="hidden" name="language" value="<?= htmlspecialchars($adminCreate['language'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><button type="submit" name="action" value="create-product">＋ Produkt</button></form><?php endif; ?>
        <form method="post" action="<?= $siteRoot ?>admin.php"><input type="hidden" name="csrf" value="<?= htmlspecialchars($adminCreate['csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><input type="hidden" name="language" value="<?= htmlspecialchars($adminCreate['language'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><input type="hidden" name="type" value="page"><button type="submit" name="action" value="create-content">＋ Stránka</button></form>
        <form method="post" action="<?= $siteRoot ?>admin.php"><input type="hidden" name="csrf" value="<?= htmlspecialchars($adminCreate['csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><input type="hidden" name="language" value="<?= htmlspecialchars($adminCreate['language'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><input type="hidden" name="type" value="post"><button type="submit" name="action" value="create-content">＋ Článek</button></form>
        <?php if (!in_array($page, ['catalog', 'product-record'], true)): ?><a href="<?= $siteRoot . htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>?manage=1">Produkty a koncepty</a>
        <a href="<?= $siteRoot . htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>?homepage_edit=1#homepage-editor">Výběr na úvodní stránku</a><?php endif; ?>
        <a href="<?= $siteRoot . htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/blog?manage=1">Blog</a>
        <a href="<?= $siteRoot ?>admin.php">Další obsah a nastavení</a>
      </div>
    </nav>
  <?php endif; ?>
