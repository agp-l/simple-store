    <?php if ($canEdit): ?>
      <section class="supplier-links" id="supplier-links" aria-label="Soukromé odkazy na dodavatele">
        <h2><?= \SimpleStore\Rendering\Icon::render('external') ?> Dodavatelé <small>Jen pro správce</small></h2>
        <?php if (!$supplierLinksReady): ?>
          <p>Pro odkazy na dodavatele nejprve <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
        <?php else: ?>
          <?php if (($_GET['supplier_saved'] ?? '') === '1'): ?><p class="inline-stock-notice" role="status">Odkazy na dodavatele byly aktualizovány.</p><?php endif; ?>
          <?php if (isset($_GET['supplier_error']) && is_string($_GET['supplier_error']) && $_GET['supplier_error'] !== 'missing'): ?><p class="supplier-error" role="alert"><?= $escape($_GET['supplier_error']) ?></p><?php endif; ?>
          <?php if ($supplierLinks === []): ?><p>Pro tento produkt zatím nejsou uložené odkazy.</p><?php endif; ?>
          <ul class="supplier-list">
            <?php foreach ($supplierLinks as $supplier): ?>
              <li>
                <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($supplier['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($supplier['label']) ?> ↗</a>
                <span><?= $escape($supplier['url']) ?></span>
                <details><summary><?= \SimpleStore\Rendering\Icon::render('edit') ?> Upravit nebo odstranit</summary>
                  <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>" class="supplier-form">
                    <input type="hidden" name="action" value="product-supplier-link"><input type="hidden" name="operation" value="save">
                    <input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>">
                    <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="id" value="<?= (int) $supplier['id'] ?>">
                    <label>Název dodavatele <input name="label" maxlength="120" required value="<?= $escape($supplier['label']) ?>"></label>
                    <label>Webová adresa <input type="url" name="url" maxlength="1000" required value="<?= $escape($supplier['url']) ?>"></label>
                    <button type="submit" class="inline-small"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit odkaz</button>
                  </form>
                  <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>">
                    <input type="hidden" name="action" value="product-supplier-link"><input type="hidden" name="operation" value="remove">
                    <input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>">
                    <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="id" value="<?= (int) $supplier['id'] ?>">
                    <button type="submit" class="inline-small"><?= \SimpleStore\Rendering\Icon::render('delete') ?> Odstranit odkaz</button>
                  </form>
                </details>
              </li>
            <?php endforeach; ?>
          </ul>
          <details class="supplier-add"><summary><?= \SimpleStore\Rendering\Icon::render('plus') ?> Přidat odkaz na dodavatele</summary>
            <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>" class="supplier-form">
              <input type="hidden" name="action" value="product-supplier-link"><input type="hidden" name="operation" value="save">
              <input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>">
              <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="id" value="0">
              <label>Název dodavatele <input name="label" maxlength="120" placeholder="Např. Partnerský e-shop" required></label>
              <label>Webová adresa <input type="url" name="url" maxlength="1000" placeholder="https://..." required></label>
              <button type="submit" class="inline-small"><?= \SimpleStore\Rendering\Icon::render('plus') ?> Přidat odkaz</button>
            </form>
          </details>
        <?php endif; ?>
      </section>
    <?php endif; ?>
