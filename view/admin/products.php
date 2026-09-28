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
                <label>Adresa (slug)<input name="slug" maxlength="190" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="Vytvoří se z názvu" value="<?= $escape($productForm['slug'] ?? '') ?>"><small>Vyplní se automaticky. Můžeš ji upravit; u existujícího produktu zůstane stejná.</small></label>
              </div>
              <label>Krátký popis<textarea name="summary" rows="3"><?= $escape($productForm['summary'] ?? '') ?></textarea></label>
              <label>Krátký úvodní text v detailu<textarea name="description" rows="4"><?= $escape($productForm['description'] ?? '') ?></textarea></label>
              <label>Cesta k obrázku nebo HTTPS adresa<input name="image_path" maxlength="1000" placeholder="images/batoh.webp" value="<?= $escape($productForm['image_path'] ?? '') ?>" required>
                <small>Například <code>images/batoh.webp</code>. Obrázek nahraj do složky <code>images/</code>; cesta pak bude přímo v <code>&lt;img src&gt;</code> karty.</small></label>
              <label>Další fotografie v galerii<textarea name="gallery" rows="3" placeholder="images/batoh-bok.webp&#10;images/batoh-zada.webp"><?= $escape($productForm['gallery'] ?? '') ?></textarea><small>Každá adresa na samostatný řádek; první hlavní fotografie se zadává nahoře.</small></label>
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
              </div>
              <section class="admin-builder" aria-labelledby="options-title">
                <div class="admin-builder-title"><div><h3 id="options-title">Výběr při nákupu</h3><p>Vytvoř třeba Barva, Velikost, Pozice zipu nebo Délka. Každý produkt může mít jiné položky.</p></div><button type="button" class="admin-small-button" data-add-row="options">+ Přidat výběr</button></div>
                <div data-rows="options">
                  <?php foreach (($productForm['option_name'] ?: ['']) as $i => $optionName): ?>
                    <div class="admin-builder-row" data-row><label>Název výběru<input name="option_name[]" placeholder="Barva" value="<?= $escape($optionName) ?>"></label><label>Možnosti (každá na nový řádek)<textarea name="option_values[]" rows="3" placeholder="Grey / Clay&#10;Black / Grey"><?= $escape($productForm['option_values'][$i] ?? '') ?></textarea></label><button type="button" class="admin-remove" data-remove-row>Odebrat</button></div>
                  <?php endforeach; ?>
                </div>
              </section>
              <section class="admin-builder" aria-labelledby="specs-title">
                <div class="admin-builder-title"><div><h3 id="specs-title">Technické parametry</h3><p>Údaje pro přehlednou tabulku pod produktem (hmotnost, materiál, drop…).</p></div><button type="button" class="admin-small-button" data-add-row="specs">+ Přidat parametr</button></div>
                <div data-rows="specs">
                  <?php foreach (($productForm['spec_name'] ?: ['']) as $i => $specName): ?>
                    <div class="admin-builder-row admin-pair" data-row><label>Parametr<input name="spec_name[]" placeholder="Hmotnost" value="<?= $escape($specName) ?>"></label><label>Hodnota<input name="spec_value[]" placeholder="292 g" value="<?= $escape($productForm['spec_value'][$i] ?? '') ?>"></label><button type="button" class="admin-remove" data-remove-row>Odebrat</button></div>
                  <?php endforeach; ?>
                </div>
              </section>
              <section class="admin-builder" aria-labelledby="sections-title">
                <div class="admin-builder-title"><div><h3 id="sections-title">Obsah produktu</h3><p>Skládej odstavce, seznamy, tabulky i fotografie. V textu funguje **tučné** a [odkaz](https://priklad.cz).</p></div><button type="button" class="admin-small-button" data-add-row="sections">+ Přidat blok</button></div>
                <div data-rows="sections">
                  <?php foreach (($productForm['section_type'] ?: ['text']) as $i => $sectionType): ?>
                    <div class="admin-builder-row" data-row><div class="admin-fields-two"><label>Typ bloku<select name="section_type[]"><option value="text" <?= $sectionType === 'text' ? 'selected' : '' ?>>Odstavce</option><option value="list" <?= $sectionType === 'list' ? 'selected' : '' ?>>Seznam</option><option value="table" <?= $sectionType === 'table' ? 'selected' : '' ?>>Tabulka</option><option value="image" <?= $sectionType === 'image' ? 'selected' : '' ?>>Fotografie</option></select></label><label>Nadpis (volitelný)<input name="section_heading[]" placeholder="Vlastnosti" value="<?= $escape($productForm['section_heading'][$i] ?? '') ?>"></label></div><label>Text bloku<textarea name="section_body[]" rows="5" placeholder="Odstavce odděl prázdným řádkem. Seznam: jedna položka na řádek. Tabulka: Název | Hodnota. Fotografie: images/nazev.webp"><?= $escape($productForm['section_body'][$i] ?? '') ?></textarea></label><button type="button" class="admin-remove" data-remove-row>Odebrat</button></div>
                  <?php endforeach; ?>
                </div>
              </section>
              <template data-template="options"><div class="admin-builder-row" data-row><label>Název výběru<input name="option_name[]" placeholder="Velikost"></label><label>Možnosti (každá na nový řádek)<textarea name="option_values[]" rows="3" placeholder="42 EU&#10;43 EU"></textarea></label><button type="button" class="admin-remove" data-remove-row>Odebrat</button></div></template>
              <template data-template="specs"><div class="admin-builder-row admin-pair" data-row><label>Parametr<input name="spec_name[]" placeholder="Hmotnost"></label><label>Hodnota<input name="spec_value[]" placeholder="292 g"></label><button type="button" class="admin-remove" data-remove-row>Odebrat</button></div></template>
              <template data-template="sections"><div class="admin-builder-row" data-row><div class="admin-fields-two"><label>Typ bloku<select name="section_type[]"><option value="text">Odstavce</option><option value="list">Seznam</option><option value="table">Tabulka</option><option value="image">Fotografie</option></select></label><label>Nadpis (volitelný)<input name="section_heading[]" placeholder="Vlastnosti"></label></div><label>Text bloku<textarea name="section_body[]" rows="5" placeholder="Odstavce, položky seznamu, Název | Hodnota nebo cesta k obrázku"></textarea></label><button type="button" class="admin-remove" data-remove-row>Odebrat</button></div></template>
              <p class="admin-form-note">Cena a dostupnost zatím platí pro všechny možnosti produktu stejně; košík uchová vybranou kombinaci. Nezadávej různé ceny nebo skladové stavy jednotlivých kombinací.</p>
              <label class="admin-checkbox"><input type="checkbox" name="published" value="1" <?= !empty($productForm['published']) ? 'checked' : '' ?>> Publikovat na webu</label>
              <p class="admin-form-note">Po vydání prvního produktu se ukázkové karty nahradí zveřejněnými produkty.</p>
              <button class="admin-button" type="submit" <?= $productSchemaReady ? '' : 'disabled' ?>>Uložit novou revizi</button>
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
