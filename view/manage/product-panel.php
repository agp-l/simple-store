<?php if ($canEdit): ?>
<section class="content-admin-panel product-editor-workspace" aria-label="Správa produktu">
    <?php if ($canEdit && !$editing): ?>
      <div class="product-admin-read"><span>Produkt prohlížíš bez úprav.</span><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . $language . '/produkt/' . rawurlencode($product['slug']) . '?edit=1') ?>"><?= \SimpleStore\Rendering\Icon::render('edit') ?> Upravit produkt</a></div>
    <?php endif; ?>
    <?php if ($editing): ?>
      <div class="inline-toolbar product-edit-toolbar" id="inline-toolbar">
        <div><strong><?= \SimpleStore\Rendering\Icon::render('package') ?> Úprava produktu <span class="product-edit-state<?= !empty($product['published']) ? ' is-public' : '' ?>"><?= !empty($product['published']) ? 'Zveřejněný' : 'Skrytý koncept' ?></span></strong></div>
        <span class="inline-status" id="inline-status" role="status" aria-live="polite">Revize <?= (int) $product['revision_number'] ?></span>
        <div class="product-edit-toolbar-actions">
          <button type="button" class="inline-small" data-editor-action="publish" data-value="<?= $product['published'] ? '0' : '1' ?>"><?= \SimpleStore\Rendering\Icon::render('eye') ?> <?= $product['published'] ? 'Skrýt produkt' : 'Publikovat produkt' ?></button>
          <button type="button" class="inline-small" data-editor-action="media-library"><?= \SimpleStore\Rendering\Icon::render('image') ?> Fotografie</button>
          <a class="inline-small" href="<?= \SimpleStore\Navigation\PanelUrl::escape($mediaLibraryUrl) ?>"><?= \SimpleStore\Rendering\Icon::render('image') ?> Knihovna fotografií</a>
          <a class="inline-small" href="<?= !empty($product['variant_inventory']['items']) ? '#variant-supplier-links' : '#supplier-links' ?>"><?= \SimpleStore\Rendering\Icon::render('external') ?> Partnerské odkazy</a>
          <a class="inline-small" href="<?= \SimpleStore\Navigation\PanelUrl::escape($basePath . 'admin/?' . http_build_query(['section' => 'product-import', 'language' => $language, 'key' => $product['product_key']])) ?>"><?= \SimpleStore\Rendering\Icon::render('document') ?> JSON pro AI</a>
          <?php if ($product['published']): ?><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . $language . '/produkt/' . rawurlencode($product['slug'])) ?>"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit bez úprav</a><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php if ($editing): ?>
  <nav class="product-editor-nav" aria-label="Části editoru produktu">
    <a href="#product-settings"><?= \SimpleStore\Rendering\Icon::render('settings') ?> Základní údaje</a>
    <a href="#product-sale"><?= \SimpleStore\Rendering\Icon::render('users') ?> Prodej a partner</a>
    <a href="#product-variants"><?= \SimpleStore\Rendering\Icon::render('package') ?> Nabídka, sklad a odkazy</a>
    <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot.'admin/?'.http_build_query(['section'=>'supplier-prices','q'=>$product['name']]).'#price-history') ?>"><?= \SimpleStore\Rendering\Icon::render('search') ?> Kontroly cen tohoto produktu</a>
  </nav>
  <p class="editor-save-guide"><?= \SimpleStore\Rendering\Icon::render('save') ?> Každý rámeček uvádí, jak se ukládá. Tlačítko uloží jen svou část; změny v ostatních částech je potřeba uložit zvlášť.</p>
  <?php endif; ?>
  <div class="editor-panel-sections<?= $editing ? '' : ' is-readonly' ?>">
    <?php if ($editing): ?>
      <?php if (($_GET['stock_saved'] ?? '') === '1'): ?><p class="inline-stock-notice" role="status">Počet kusů skladem byl uložen.</p><?php endif; ?>
      <section class="product-admin-settings editor-basics" id="product-settings" aria-labelledby="product-settings-title"><div class="product-admin-settings-heading"><strong id="product-settings-title"><?= \SimpleStore\Rendering\Icon::render('settings') ?> Základní údaje</strong><div class="editor-chips" aria-label="Přehled produktu">
        <span class="editor-chip editor-chip--category"><?= \SimpleStore\Rendering\Icon::render('folder') ?> <?= $escape($categoryLabel) ?></span>
        <span class="editor-chip editor-chip--<?= ['in_stock' => 'available', 'on_order' => 'waiting', 'out_of_stock' => 'empty'][$availability] ?? 'waiting' ?>"><?= \SimpleStore\Rendering\Icon::render(['in_stock' => 'check', 'on_order' => 'clock', 'out_of_stock' => 'cancel'][$availability] ?? 'clock') ?> <?= $escape($stockText[$availability] ?? '') ?></span>
        <?php if ($stockReady ?? false): ?><span class="editor-chip editor-chip--<?= (int) ($product['stock_quantity'] ?? 0) > 0 ? 'quantity' : 'empty' ?>"><?= \SimpleStore\Rendering\Icon::render('package') ?> Volné: <?= (int) ($product['stock_quantity'] ?? 0) ?> ks</span><?php endif; ?>
      </div></div>
      <div class="inline-settings" aria-label="Nastavení produktu">
        <label><?= \SimpleStore\Rendering\Icon::render('folder') ?> Kategorie <select data-editor-select="category_path">
          <?php foreach ($editorCategories as $option): ?><option value="<?= $escape($option['path']) ?>" <?= $categoryPath === $option['path'] ? 'selected' : '' ?>><?= $escape($option['title']) ?></option><?php endforeach; ?>
        </select></label>
        <label class="product-admin-address">Adresa produktu <span class="inline-address-prefix">/<?= $escape($language) ?>/produkt/</span><input type="text" data-editor-input="slug" value="<?= $escape($product['slug']) ?>" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="190" required spellcheck="false" autocomplete="off"></label>
      </div>
      <p class="editor-save-hint">Kategorie se uloží po výběru. Adresa se uloží po opuštění pole. Stav uložení vidíš nahoře vedle názvu editoru.</p>
      </section>
    <?php endif; ?>
    <?php require __DIR__ . '/product-sale.php'; ?>
    <?php require __DIR__ . '/product-variants.php'; ?>
    <?php require __DIR__ . '/product-selection.php'; ?>
    <?php require __DIR__ . '/product-relations.php'; ?>
  </div>
  <?php if ($editing): ?>
      <details class="inline-delete-product"><summary><?= \SimpleStore\Rendering\Icon::render('delete') ?> Odstranit produkt</summary>
        <p>Smazání odstraní produkt a všechny jeho revize v tomto jazyce. Nahrané obrázky zůstanou na disku, protože mohou mít zkopírované odkazy.</p>
        <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>">
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
