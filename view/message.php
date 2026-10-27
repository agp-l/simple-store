  <main class="wrap cms-content" id="produkty">
    <div class="cms-article">
      <?php if ($page === 'not-found' && is_array($removedProduct ?? null)): ?>
        <p class="cms-eyebrow">Prodej ukončen</p>
        <h1>Produkt už není v nabídce</h1>
        <p class="cms-lead"><?= htmlspecialchars($removedProduct['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></p>
        <p>Tento produkt jsme z nabídky odstranili. Podívejte se na další vybavení pro vaše cesty.</p>
      <?php elseif ($page === 'not-found'): ?>
        <p class="cms-eyebrow">404</p>
        <h1>Stránka nenalezena</h1>
        <p class="cms-lead">Zkuste se vrátit na úvodní stránku.</p>
      <?php else: ?>
        <p class="cms-eyebrow">Obsah dočasně nedostupný</p>
        <h1>Stránku se teď nepodařilo načíst</h1>
        <p class="cms-lead">Zkuste to prosím později.</p>
      <?php endif; ?>
      <a href="<?= $siteRoot . htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Zpět na úvod</a>
    </div>
  </main>
