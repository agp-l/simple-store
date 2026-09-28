<?php $editingProduct = ($productForm['product_key'] ?? '') !== ''; ?>
      <div class="admin-intro"><div><p class="admin-eyebrow">Obchod</p><h1>Produkty</h1>
        <p>Fotografie je běžná adresa v <code>&lt;img src&gt;</code>. Každé uložení uchová celou předchozí verzi produktu.</p></div>
        <div class="admin-quick"><a href="<?= $escape($adminUrl) ?>">Stránky a články</a><a href="<?= $escape($adminUrl . '?section=products') ?>">+ Nový produkt</a></div>
      </div>
      <?php if ($productNotice !== ''): ?><p class="admin-notice" role="status"><?= $escape($productNotice) ?></p><?php endif; ?>
      <?php if ($productError !== ''): ?><p class="admin-error" role="alert"><?= $escape($productError) ?></p><?php endif; ?>
      <div class="admin-grid">
        <section class="admin-panel admin-list" aria-labelledby="product-list-title">
          <h2 id="product-list-title">Produkty <span><?= count($productRows) ?></span></h2>
          <?php if ($productRows === []): ?><p class="admin-empty">Zatím žádné. Na veřejné stránce zůstávají ukázkové karty.</p><?php endif; ?>
          <?php foreach ($productRows as $row): ?>
            <?php $editLink = $adminUrl . '?' . http_build_query(['section' => 'products', 'key' => $row['product_key'], 'language' => $row['language']]); ?>
            <a class="admin-document" href="<?= $escape($editLink) ?>"><strong><?= $escape($row['name']) ?></strong>
              <span><?= $escape($row['slug']) ?> · <?= $escape($row['language']) ?> · <?= number_format((int) $row['price_czk'], 0, ',', ' ') ?> Kč</span>
              <small><?= $row['published'] ? 'Publikováno' : 'Koncept' ?> · revize <?= $escape($row['revision_number']) ?></small>
            </a>
          <?php endforeach; ?>
        </section>
        <div class="admin-workspace">
          <section class="admin-panel" aria-labelledby="product-editor-title">
            <div class="admin-section-heading"><p class="admin-eyebrow"><?= $editingProduct ? 'Úprava produktu' : 'Nový produkt' ?></p>
              <h2 id="product-editor-title"><?= $editingProduct ? $escape($currentProduct['name'] ?? $productForm['name'] ?? '') : 'Založit produkt' ?></h2></div>
            <?php if ($editingProduct && !empty($currentProduct['published'])): ?>
              <p><a href="<?= $escape($basePath . $language . '/produkt/' . $currentProduct['slug']) ?>" target="_blank" rel="noopener">Otevřít produkt v obchodě ↗</a></p>
            <?php endif; ?>
            <form method="post" action="<?= $escape($adminUrl) ?>" class="admin-editor">
              <input type="hidden" name="action" value="save-product">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
              <input type="hidden" name="key" value="<?= $escape($productForm['product_key'] ?? '') ?>">
              <input type="hidden" name="revision" value="<?= $escape($productForm['revision_number'] ?? '') ?>">
              <div class="admin-fields-two">
                <label>Jazyk
                  <?php if ($editingProduct): ?>
                    <input value="<?= $escape($language) ?>" disabled><input type="hidden" name="language" value="<?= $escape($language) ?>">
                  <?php else: ?>
                    <select name="language" required>
                      <?php foreach ($site['languages'] as $code): ?><option value="<?= $escape($code) ?>" <?= ($productForm['language'] ?? '') === $code ? 'selected' : '' ?>><?= $escape($code) ?></option><?php endforeach; ?>
                    </select>
                  <?php endif; ?>
                </label>
                <label>Kategorie
                  <select name="category" required>
                    <?php foreach (['batohy' => 'Batohy', 'stany' => 'Stany', 'spacaky' => 'Spacáky', 'vybaveni' => 'Vybavení', 'obleceni' => 'Oblečení', 'boty' => 'Boty'] as $code => $label): ?>
                      <option value="<?= $escape($code) ?>" <?= ($productForm['category'] ?? '') === $code ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>
              <label>Název<input name="name" maxlength="255" value="<?= $escape($productForm['name'] ?? '') ?>" required></label>
              <div class="admin-fields-two">
                <label>Značka<input name="brand" maxlength="120" value="<?= $escape($productForm['brand'] ?? '') ?>"></label>
                <label>Adresa (slug)<input name="slug" maxlength="190" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="lehky-batoh" value="<?= $escape($productForm['slug'] ?? '') ?>" required></label>
              </div>
              <label>Krátký popis<textarea name="summary" rows="3"><?= $escape($productForm['summary'] ?? '') ?></textarea></label>
              <label>Popis produktu<textarea name="description" rows="8"><?= $escape($productForm['description'] ?? '') ?></textarea></label>
              <label>Cesta k obrázku nebo HTTPS adresa<input name="image_path" maxlength="1000" placeholder="images/batoh.webp" value="<?= $escape($productForm['image_path'] ?? '') ?>" required>
                <small>Například <code>images/batoh.webp</code>. Obrázek nahraj do složky <code>images/</code>; cesta pak bude přímo v <code>&lt;img src&gt;</code> karty.</small></label>
              <div class="admin-fields-two">
                <label>Cena v Kč<input type="number" name="price_czk" min="1" max="10000000" value="<?= $escape($productForm['price_czk'] ?? '') ?>" required></label>
                <label>Dostupnost<select name="stock_status"><option value="in_stock" <?= ($productForm['stock_status'] ?? '') === 'in_stock' ? 'selected' : '' ?>>Skladem</option><option value="on_order" <?= ($productForm['stock_status'] ?? '') === 'on_order' ? 'selected' : '' ?>>Na objednávku</option><option value="out_of_stock" <?= ($productForm['stock_status'] ?? '') === 'out_of_stock' ? 'selected' : '' ?>>Není skladem</option></select></label>
              </div>
              <div class="admin-fields-two">
                <label>Batohy: objemová skupina
                  <select name="subcategory"><option value="">Bez podkategorie</option>
                    <?php foreach (['do-25' => 'Batohy do 25 l', '25-50' => 'Batohy 25–50 l', 'nad-50' => 'Batohy nad 50 l', 'prislusenstvi' => 'Příslušenství'] as $code => $label): ?>
                      <option value="<?= $escape($code) ?>" <?= ($productForm['subcategory'] ?? '') === $code ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                  </select><small>Pro ostatní kategorie ponech „Bez podkategorie“.</small>
                </label>
                <label>Velikosti<input name="sizes" maxlength="255" placeholder="42 EU, 43 EU, 44 EU" value="<?= $escape($productForm['sizes'] ?? '') ?>"><small>Volitelné; více velikostí odděl čárkou.</small></label>
              </div>
              <label class="admin-checkbox"><input type="checkbox" name="published" value="1" <?= !empty($productForm['published']) ? 'checked' : '' ?>> Publikovat na webu</label>
              <p class="admin-form-note">Po vydání prvního produktu se ukázkové karty nahradí zveřejněnými produkty.</p>
              <button class="admin-button" type="submit">Uložit novou revizi</button>
            </form>
          </section>
          <?php if ($editingProduct && $productHistory !== []): ?>
            <section class="admin-panel admin-history" aria-labelledby="product-history-title">
              <h2 id="product-history-title">Historie produktu</h2><p>Načtenou starší verzi můžeš upravit a uložit jako další revizi.</p>
              <?php foreach ($productHistory as $row): ?>
                <?php $restoreLink = $adminUrl . '?' . http_build_query(['section' => 'products', 'key' => $row['product_key'], 'language' => $row['language'], 'restore' => $row['revision_number']]); ?>
                <div class="admin-revision"><div><strong>Revize <?= $escape($row['revision_number']) ?></strong>
                  <span><?= $escape($row['saved_at']) ?> · <?= $escape($row['name']) ?> · <?= number_format((int) $row['price_czk'], 0, ',', ' ') ?> Kč</span></div>
                  <?php if ($row['active_product_key'] === null): ?><a href="<?= $escape($restoreLink) ?>">Načíst verzi</a><?php else: ?><small>Aktuální</small><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </section>
          <?php endif; ?>
        </div>
      </div>
