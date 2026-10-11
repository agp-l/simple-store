<?php if ($canEdit): ?>
<section class="content-admin-panel" aria-label="Správa produktu">
    <?php if ($canEdit && !$editing): ?>
      <div class="product-admin-read"><span>Produkt prohlížíš bez úprav.</span><a href="<?= $escape($siteRoot . $language . '/produkt/' . rawurlencode($product['slug']) . '?edit=1') ?>"><?= \SimpleStore\Rendering\Icon::render('edit') ?> Upravit produkt</a></div>
    <?php endif; ?>
    <?php if ($editing): ?>
      <div class="inline-toolbar product-edit-toolbar" id="inline-toolbar">
        <div><strong><?= \SimpleStore\Rendering\Icon::render('package') ?> Úprava produktu <span class="product-edit-state<?= !empty($product['published']) ? ' is-public' : '' ?>"><?= !empty($product['published']) ? 'Zveřejněný' : 'Skrytý koncept' ?></span></strong><p>Klikni do textu; úprava se uloží při opuštění pole.</p></div>
        <span class="inline-status" id="inline-status" role="status" aria-live="polite">Revize <?= (int) $product['revision_number'] ?></span>
        <div class="product-edit-toolbar-actions">
          <button type="button" class="inline-small" data-editor-action="publish" data-value="<?= $product['published'] ? '0' : '1' ?>"><?= \SimpleStore\Rendering\Icon::render('eye') ?> <?= $product['published'] ? 'Skrýt produkt' : 'Publikovat produkt' ?></button>
          <button type="button" class="inline-small" data-editor-action="media-library"><?= \SimpleStore\Rendering\Icon::render('image') ?> Fotografie</button>
          <a class="inline-small" href="<?= $escape($mediaLibraryUrl) ?>"><?= \SimpleStore\Rendering\Icon::render('image') ?> Knihovna fotografií</a>
          <a class="inline-small" href="#supplier-links"><?= \SimpleStore\Rendering\Icon::render('external') ?> Dodavatelé</a>
          <a class="inline-small" href="<?= $escape($basePath . 'admin.php?' . http_build_query(['section' => 'product-import', 'language' => $language, 'key' => $product['product_key']])) ?>"><?= \SimpleStore\Rendering\Icon::render('document') ?> JSON pro AI</a>
          <?php if ($product['published']): ?><a href="<?= $escape($siteRoot . $language . '/produkt/' . rawurlencode($product['slug'])) ?>"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit bez úprav</a><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <div class="editor-panel-sections<?= $editing ? '' : ' is-readonly' ?>">
    <?php if ($editing): ?>
      <?php if (($_GET['stock_saved'] ?? '') === '1'): ?><p class="inline-stock-notice" role="status">Počet kusů skladem byl uložen.</p><?php endif; ?>
      <section class="product-admin-settings" aria-labelledby="product-settings-title"><div class="product-admin-settings-heading"><strong id="product-settings-title"><?= \SimpleStore\Rendering\Icon::render('settings') ?> Nastavení produktu</strong><span><?= $escape($categoryLabel) ?> · <?= $escape($stockText[$product['stock_status']] ?? '') ?><?= ($stockReady ?? false) ? ' · Volné: ' . (int) ($product['stock_quantity'] ?? 0) . ' ks' : '' ?></span></div>
      <div class="inline-settings" aria-label="Nastavení produktu">
        <label><?= \SimpleStore\Rendering\Icon::render('folder') ?> Kategorie <select data-editor-select="category_path">
          <?php foreach ($editorCategories as $option): ?><option value="<?= $escape($option['path']) ?>" <?= $categoryPath === $option['path'] ? 'selected' : '' ?>><?= $escape($option['title']) ?></option><?php endforeach; ?>
        </select></label>
        <label>Dostupnost <select data-editor-select="stock_status">
          <?php foreach ($stockText as $code => $text): ?><option value="<?= $code ?>" <?= $product['stock_status'] === $code ? 'selected' : '' ?>><?= $escape($text) ?></option><?php endforeach; ?>
        </select></label>
        <?php if ($stockReady ?? false): ?>
          <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>" class="inline-stock-form">
            <input type="hidden" name="action" value="set-product-stock"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
            <input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
            <input type="hidden" name="expected" value="<?= (int) ($product['stock_quantity'] ?? 0) ?>">
            <label>Volné kusy skladem <input type="number" name="quantity" min="0" max="1000000" value="<?= (int) ($product['stock_quantity'] ?? 0) ?>" required inputmode="numeric"></label>
            <button type="submit" class="inline-small"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit sklad</button>
          </form>
        <?php else: ?><a href="<?= $escape($siteRoot . 'admin.php?section=database') ?>">Pro počet kusů aktualizuj SQL tabulky ↗</a><?php endif; ?>
        <label class="product-admin-address">Adresa produktu <span class="inline-address-prefix">/<?= $escape($language) ?>/produkt/</span><input type="text" data-editor-input="slug" value="<?= $escape($product['slug']) ?>" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="190" required spellcheck="false" autocomplete="off"></label>
      </div>
      <p class="inline-stock-note">Počet je společný pro všechny varianty a jazyky. Zákazníci vidí jen dostupnost. „Na objednávku“ umožňuje objednání i bez kusů skladem.</p>
      <p class="inline-stock-note">Při prvním přejmenování nového produktu se adresa vytvoří podle názvu. U zveřejněného produktu změna adresy zneplatní dosavadní odkaz.</p>
      </section>
    <?php endif; ?>
    <?php require __DIR__ . '/product-suppliers.php'; ?>
  </div>
  <details class="editor-context-navigation"><summary><?= \SimpleStore\Rendering\Icon::render('list') ?> Katalog a organizace produktů</summary>
    <?php require __DIR__ . '/../product-admin-nav.php'; ?>
  </details>
  <?php if ($editing): ?>
      <details class="inline-delete-product"><summary><?= \SimpleStore\Rendering\Icon::render('delete') ?> Odstranit produkt</summary>
        <p>Smazání odstraní produkt a všechny jeho revize v tomto jazyce. Nahrané obrázky zůstanou na disku, protože mohou mít zkopírované odkazy.</p>
        <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>">
          <input type="hidden" name="action" value="delete-product"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
          <input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
          <input type="hidden" name="revision" value="<?= (int) $product['revision_number'] ?>">
          <label><input type="checkbox" name="confirm" value="1" required> Rozumím, že revize produktu už nepůjdou obnovit.</label>
          <button type="submit" class="inline-small"><?= \SimpleStore\Rendering\Icon::render('delete') ?> Smazat produkt</button>
        </form>
      </details>
  <?php endif; ?>
</section>
<?php endif; ?>
