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
      <svg class="header-routes" viewBox="0 0 1440 360" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
        <g fill="none" stroke="#bde18d" stroke-width="1.4" opacity=".14">
          <path d="M-70 104C17 82 85 113 107 163S120 274 222 299 310 371 271 423"/>
          <path d="M-71 130C5 107 63 133 77 176S94 294 188 322 269 382 239 432"/>
          <path d="M1120-43C1069 37 1115 86 1194 100S1342 102 1322 179 1317 260 1459 294"/>
          <path d="M1154-43C1107 28 1143 64 1210 77S1366 87 1351 163 1345 244 1480 266"/>
        </g>
        <path d="M-55 290C88 319 154 248 263 268S473 346 621 294 874 229 995 276 1224 337 1500 255"
              fill="none" stroke="#bde18d" stroke-opacity=".32" stroke-width="2"
              stroke-linecap="round" stroke-dasharray="9 12"/>
        <g fill="#252927" stroke="#bde18d" stroke-opacity=".42" stroke-width="1.5">
          <circle cx="263" cy="268" r="8"/><circle cx="995" cy="276" r="8"/>
        </g>
        <g fill="#e1c01f" opacity=".7">
          <circle cx="263" cy="268" r="2.3"/><circle cx="995" cy="276" r="2.3"/>
        </g>
   <path d="m1122 215 12-28 12 28-12-6z" fill="none" stroke="#e1c01f" stroke-opacity=".5" stroke-width="1.6" stroke-linejoin="round"/>
      </svg>
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
  </header>
  <?php if ($adminPreview !== null && $adminCreate === null): ?>
    <form class="visitor-preview-toggle" method="post" action="<?= $siteRoot ?>admin.php">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($adminPreview['csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <input type="hidden" name="enabled" value="<?= $adminPreview['active'] ? '0' : '1' ?>">
      <input type="hidden" name="return_to" value="<?= htmlspecialchars($adminPreview['return_to'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <button type="submit" name="action" value="visitor-preview"><?= $adminPreview['active'] ? 'Ukončit náhled' : 'Zobrazit jako návštěvník' ?></button>
    </form>
  <?php endif; ?>
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
        <?php if ($adminPreview !== null): ?><form class="site-admin-preview" method="post" action="<?= $siteRoot ?>admin.php"><input type="hidden" name="csrf" value="<?= htmlspecialchars($adminPreview['csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><input type="hidden" name="enabled" value="1"><input type="hidden" name="return_to" value="<?= htmlspecialchars($adminPreview['return_to'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><button type="submit" name="action" value="visitor-preview">Zobrazit jako návštěvník</button></form><?php endif; ?>
      </div>
    </nav>
  <?php endif; ?>
