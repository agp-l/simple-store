  <main class="wrap cms-content" id="produkty">
    <div class="cms-article">
      <?php if ($page === 'not-found'): ?>
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
