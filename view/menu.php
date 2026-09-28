      <div class="nav-band">
        <nav class="wrap nav-inner" id="main-nav" aria-label="Kategorie obchodu">
          <a href="<?= $siteRoot ?>index.php?category=batohy#produkty" data-filter="batohy">Batohy</a>
          <a href="<?= $siteRoot ?>index.php?category=stany#produkty" data-filter="stany">Stany</a>
          <a href="<?= $siteRoot ?>index.php?category=spacaky#produkty" data-filter="spacaky">Spacáky</a>
          <a href="<?= $siteRoot ?>index.php?category=vybaveni#produkty" data-filter="vybaveni">Vybavení</a>
          <a href="<?= $siteRoot ?>index.php?category=obleceni#produkty" data-filter="obleceni">Oblečení</a>
          <a href="<?= $siteRoot ?>index.php?category=boty#produkty" data-filter="boty">Boty</a>
          <?php foreach ($menuLinks as $link): ?>
            <a href="<?= htmlspecialchars($link['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($link['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          <?php endforeach; ?>
        </nav>
      </div>
