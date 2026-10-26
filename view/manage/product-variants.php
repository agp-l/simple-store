<?php if ($editing):
$variantInventory = $product['variant_inventory'] ?? \SimpleStore\Product\ProductVariantRepository::empty();
$variantRows = $variantInventory['items'];
$variantForm = $variantForm ?? [];
$variantError = $variantError ?? '';
$variantFailedRows = [];
if (($variantForm['operation'] ?? '') === 'batch' && is_string($variantForm['rows_json'] ?? null)) {
    $submitted = json_decode($variantForm['rows_json'], true);
    if (is_array($submitted)) foreach ($submitted as $submittedRow) {
        if (is_array($submittedRow) && is_string($submittedRow['variant_id'] ?? null)) $variantFailedRows[$submittedRow['variant_id']] = $submittedRow;
    }
}
$variantLinksById = [];
foreach ($variantSupplierLinks as $link) $variantLinksById[$link['variant_key']][] = $link;
$variantPhotoPaths = array_values(array_unique(array_filter(array_merge([$product['image_path']], $details['gallery'], array_column($variantRows, 'image_path')))));
$variantGroups = [];
foreach ($details['options'] as $group) $variantGroups[] = $group['name'] . ': ' . implode(' | ', $group['values']);
?>
<section class="product-admin-settings variant-manager" id="product-variants" aria-labelledby="variant-heading" data-variant-manager data-product-key="<?= $escape($product['product_key']) ?>" data-expected="<?= $escape($variantForm['expected'] ?? $variantInventory['expected']) ?>" data-base-price="<?= (int) $product['price_czk'] ?>" data-recovered="<?= $variantError !== '' && in_array($variantForm['operation'] ?? '', ['row', 'batch', 'inherit-prices'], true) ? '1' : '0' ?>">
  <div class="product-admin-settings-heading"><strong id="variant-heading"><?= \SimpleStore\Rendering\Icon::render('package') ?> Varianty a sklad</strong><span class="editor-chip"><?= $variantInventory['enabled'] ? 'Sklad po variantách' : 'Společný sklad produktu' ?> · <?= count($variantRows) ?> variant</span></div>
  <?php if(!empty($supplierVariantPricing)):?><p class="inline-stock-notice" role="status">Ceny těchto sloučených variant se automaticky počítají z jejich vlastních nabídek dodavatele a nastaveného zaokrouhlení. Ruční změna ceny v tabulce automatiku pro tento produkt vypne.</p><?php endif;?>
  <?php if (($_GET['variants_saved'] ?? '') === '1' && $variantError === ''): ?><p class="inline-stock-notice" role="status"><?= $variantInventory['enabled'] ? 'Varianty a sklad byly uloženy.' : 'Varianty jsou uložené. Společný sklad zůstal zachovaný.' ?></p><?php endif; ?>
  <?php if ($variantError !== '' && !in_array($variantForm['operation'] ?? '', ['merge', 'merge-preview'], true)): ?><p class="panel-error" role="alert"><?= $escape($variantError) ?></p><?php endif; ?>
  <?php if ($variantRows !== [] && !$variantInventory['enabled']): ?>
  <div class="variant-mode-note" role="status"><strong>Varianty jsou připravené · stále se používá společný sklad</strong>
    <p>Prodej dál používá společnou cenu a společný počet <?= (int) ($product['stock_quantity'] ?? 0) ?> ks. Údaje v tabulce variant se zákazníkům projeví až po zapnutí skladu variant.</p>
    <p>Doplň skutečné volné kusy a dostupnost jednotlivých variant, ulož tabulku a potom použij <a href="#variant-stock-mode">Zapnout sklad variant</a>. Původní kusy se mezi varianty automaticky nerozdělují.</p>
  </div>
  <?php endif; ?>
  <div class="variant-pricing">
    <div id="product-price"><strong><?= $variantRows === [] ? 'Prodejní cena produktu' : 'Společná cena produktu' ?></strong><div class="variant-shared-price"><span data-variant-base-price<?= $editable('price_czk', (string) $product['price_czk']) ?>><?= number_format((int) $product['price_czk'], 0, ',', ' ') ?></span> Kč <button type="button" class="inline-small" data-product-price-focus><?= \SimpleStore\Rendering\Icon::render('edit') ?> Upravit cenu</button></div>
      <p>Klikni na cenu, napiš částku v celých Kč a potvrď klávesou Enter nebo kliknutím mimo pole.<?= $variantRows === [] ? ' Tuto cenu zaplatí zákazník.' : ' Varianty bez vlastní ceny ji přebírají automaticky.' ?></p>
    </div>
    <?php if ($variantRows !== []): ?>
    <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>" data-variant-inherit>
      <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="inherit-prices"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($variantForm['expected'] ?? $variantInventory['expected']) ?>">
      <button class="inline-small" type="submit">Použít společnou cenu u všech variant</button>
      <button class="inline-small" type="button" data-variant-prices-undo hidden>Vrátit původní ceny</button>
      <p data-variant-price-count>Jinou cenu vyplň jen u variant, které mají stát jinak. Prázdné pole znamená společnou cenu.</p>

    </form>
    <?php else: ?><p>Produkt můžeš prodávat i bez variant. Varianty vytvářej, jen když zákazník vybírá například velikost nebo barvu.</p><?php endif; ?>
  </div>
  <?php if ($variantRows === []): $partnerSourceOnly = false; require __DIR__.'/product-suppliers.php'; endif; ?>
  <?php if ($variantRows !== []): ?>
  <div class="variant-save-bar" data-variant-save-bar hidden>
    <div><strong>Hromadné uložení variant</strong><p>Uloží fotografie, SKU, kusy, dostupnost, vlastní ceny a přepínače Nabízet ve všech změněných řádcích. Partnerské odkazy mají vlastní uložení.</p><p data-variant-save-state role="status" aria-live="polite">Všechny změny variant jsou uložené.</p></div>
    <button class="inline-small variant-save-primary" type="submit" form="variant-batch" data-variant-save-top>Uložit změny všech variant</button>
  </div>
  <noscript><p>Bez JavaScriptu použij u každého řádku „Uložit jen tuto variantu“.</p></noscript>
  <p>SKU je nepovinný interní kód varianty, například BUNDA-M-MODRA. Prázdná fotografie přebírá snímek stejné barvy, případně obrázek produktu. Tlačítkem „Použít pro tuto barvu“ připravíš fotografii pro všechny její velikosti; potom vše uložíš hromadně. Vlastnosti varianty jsou stálé; jinou kombinaci přidej jako novou.</p>
  <div class="variant-partner-tools" id="variant-supplier-links">
    <p>Fotografii porovnej s otevřenou nabídkou partnera. Stejně pojmenované barvy mohou v různých obchodech vypadat jinak.</p>
    <?php if (!$variantSupplierReady): ?>
    <p>Pro ukládání odkazů variant <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/?section=database') ?>">aktualizuj databázi</a>.</p>
    <?php else: ?>
    <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>">
      <input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="action" value="product-supplier-link"><input type="hidden" name="operation" value="inherit">
      <input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
      <button class="inline-small" type="submit">Doplnit chybějící společné odkazy k variantám</button>
    </form>
    <?php endif; ?>
    <?php if (($_GET['supplier_variant'] ?? '') === 'all'): ?>
      <?php if (($_GET['supplier_saved'] ?? '') === '1'): ?><p role="status">Partnerské odkazy variant byly aktualizovány.</p><?php endif; ?>
      <?php if (isset($_GET['supplier_error']) && is_string($_GET['supplier_error'])): ?><p class="supplier-error" role="alert"><?= $escape($_GET['supplier_error']) ?></p><?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="variant-table-wrap"><table class="variant-table"><thead><tr><th>Varianta</th><th>SKU</th><th>Volné kusy</th><th>Dostupnost</th><th>Vlastní cena Kč</th><th>Fotografie</th><th>Nabízet</th><th>Uložení</th></tr></thead>
  <?php foreach ($variantRows as $variant):
      $formId = 'variant-' . $variant['id'];
      $rowForm = $variantFailedRows[$variant['id']] ?? $variantForm;
      $failedRow = ($rowForm['variant_id'] ?? '') === $variant['id'];
      $value = static fn (string $field, mixed $fallback): mixed => $failedRow ? ($rowForm[$field] ?? $fallback) : $fallback;
  ?>
    <tbody class="variant-table-group">
    <tr data-variant-row="<?= $variant['id'] ?>" data-variant-color="<?= $escape(json_encode(\SimpleStore\Product\VariantImages::color($variant['choices']), JSON_THROW_ON_ERROR)) ?>" class="<?= $variant['enabled'] ? '' : 'variant-disabled' ?>"><th scope="row" id="variant-label-<?= $variant['id'] ?>"><?= $escape(\SimpleStore\Product\ProductVariants::label($variant['choices'])) ?>
        <?php if ($variantMode && $variant['enabled']): ?><a class="variant-public-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . $language . '/produkt/' . rawurlencode($product['slug']) . '?variant=' . $variant['id']) ?>" target="_blank" rel="noopener noreferrer">Otevřít variantu ↗</a><?php endif; ?>
      </th>
      <td data-label="SKU"><input form="<?= $formId ?>" name="sku" aria-label="SKU" maxlength="100" value="<?= $escape($value('sku', $variant['sku'])) ?>"></td>
      <td data-label="Volné kusy"><input form="<?= $formId ?>" name="quantity" aria-label="Volné kusy" type="number" min="0" max="1000000" required value="<?= $escape($value('quantity', $variant['quantity'])) ?>"></td>
      <td data-label="Dostupnost"><select form="<?= $formId ?>" name="stock_status" aria-label="Dostupnost"><?php foreach ($stockText as $code => $label): ?><option value="<?= $code ?>" <?= $value('stock_status', $variant['stock_status']) === $code ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></td>
      <td data-label="Vlastní cena Kč"><input form="<?= $formId ?>" name="price_czk" aria-label="Vlastní cena Kč" aria-describedby="variant-price-note-<?= $variant['id'] ?>" type="number" min="1" max="10000000" placeholder="<?= (int) $product['price_czk'] ?>" value="<?= $escape($value('price_czk', $variant['price_czk'] ?? '')) ?>"><small id="variant-price-note-<?= $variant['id'] ?>" data-variant-price-note><?= (string) $value('price_czk', $variant['price_czk'] ?? '') === '' ? 'Společná cena' : 'Vlastní cena' ?></small></td>
      <td data-label="Fotografie"><select form="<?= $formId ?>" name="image_path" aria-label="Fotografie"><option value="" data-photo-full="<?= $escape($imageUrl($product['image_path'])) ?>" data-photo-preview="<?= $escape($imageUrl(\SimpleStore\Media\MediaPath::variant($product['image_path'], 'thumb'))) ?>">Podle barvy / společná</option><?php foreach ($variantPhotoPaths as $variantImage): if ($variantImage === '') continue; ?><option value="<?= $escape($variantImage) ?>" data-photo-full="<?= $escape($imageUrl($variantImage)) ?>" data-photo-preview="<?= $escape($imageUrl(\SimpleStore\Media\MediaPath::variant($variantImage, 'thumb'))) ?>" <?= $value('image_path', $variant['image_path']) === $variantImage ? 'selected' : '' ?>><?= $escape(basename($variantImage)) ?></option><?php endforeach; ?></select><button type="button" class="inline-small variant-photo-copy" data-variant-photo-copy hidden>Použít pro tuto barvu</button></td>
      <td data-label="Nabízet"><input form="<?= $formId ?>" type="checkbox" name="enabled" value="1" aria-label="Nabízet variantu" <?= ($failedRow ? ($rowForm['enabled'] ?? '') === '1' : $variant['enabled']) ? 'checked' : '' ?>></td>
      <td data-label="Akce"><form id="<?= $formId ?>" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>">
        <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="row"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($failedRow ? ($variantForm['expected'] ?? '') : $variantInventory['expected']) ?>"><input type="hidden" name="variant_id" value="<?= $variant['id'] ?>">
        <button class="inline-small" type="submit" data-variant-row-save><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit jen tuto variantu</button><span class="variant-row-state" data-variant-row-state hidden>Uloženo</span>
      </form></td>
    </tr>
    <?php if ($variantSupplierReady): ?>
    <tr class="variant-partner-row" id="variant-suppliers-<?= $variant['id'] ?>"><td colspan="8">
      <?php require __DIR__ . '/product-variant-suppliers.php'; ?>
    </td></tr>
    <?php endif; ?>
    </tbody>
  <?php endforeach; ?></table></div>
  <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>" id="variant-batch" hidden>
    <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="batch"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($variantForm['expected'] ?? $variantInventory['expected']) ?>"><input type="hidden" name="rows_json">

  </form>
  <details class="partner-source-links" <?= (isset($_GET['supplier_error']) || isset($_GET['supplier_saved'])) && !isset($_GET['supplier_variant']) ? 'open' : '' ?>>
    <summary>Společné zdroje pro kontrolu cen (<?= count($supplierLinks) ?>)</summary>
    <p>Tyto původní stránky ještě používá cenový automat pro načítání nabídek. Přesné adresy barev a velikostí upravuj v tabulce výše.</p>
    <?php $partnerSourceOnly = true; require __DIR__.'/product-suppliers.php'; ?>
  </details>
  <?php endif; ?>
  <details class="variant-create" id="variant-create" <?= $variantRows === [] || ($variantForm['operation'] ?? '') === 'generate' ? 'open' : '' ?>><summary><?= \SimpleStore\Rendering\Icon::render('plus') ?> <?= $variantRows === [] ? 'Vytvořit varianty' : 'Přidat kombinace' ?></summary>
    <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>" data-variant-generate data-recovered="<?= $variantError !== '' && ($variantForm['operation'] ?? '') === 'generate' ? '1' : '0' ?>">
      <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="generate"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($variantInventory['expected']) ?>">
      <label>Vlastnosti a jejich hodnoty<textarea name="groups" rows="4" maxlength="16000" aria-describedby="variant-groups-help variant-generate-error" placeholder="Velikost: S | M | L&#10;Barva: Modrá | Červená" required><?= $escape($variantForm['groups'] ?? implode("\n", $variantGroups)) ?></textarea></label>
      <p id="variant-groups-help">Například <code>Velikost: S | M | L</code> vytvoří tři varianty. Každou další vlastnost dej na nový řádek. Text v šedé ukázce je potřeba nahradit vlastními hodnotami. Existující kombinace se nepřepíšou.</p>
      <p class="variant-generate-preview" data-variant-generate-preview role="status" aria-live="polite" hidden></p>
      <p>Nové varianty začnou s 0 kusy a přebírají společnou cenu produktu. Existující varianty, sklad i objednávky zůstanou zachované.<?= !$variantInventory['enabled'] ? ' Vytvoření kombinací nezapíná sklad variant; ten zapneš samostatně po přípravě tabulky.' : '' ?></p>
      <p class="panel-error" id="variant-generate-error" data-variant-generate-error role="alert" hidden></p>
      <button class="inline-small" type="submit"><?= \SimpleStore\Rendering\Icon::render('plus') ?> Vytvořit kombinace</button>
    </form>
  </details>
  <?php if ($variantRows !== []): ?><details class="variant-create" id="variant-stock-mode" <?= !$variantInventory['enabled'] || ($variantForm['operation'] ?? '') === 'mode' ? 'open' : '' ?>><summary><?= $variantInventory['enabled'] ? 'Změnit režim skladu' : 'Zapnout sklad variant' ?></summary>
    <?php if ($variantModeBlocked): ?><div class="variant-mode-note variant-mode-note--blocked" role="status"><strong>Přepnutí skladu nyní blokují rezervace v objednávkách</strong><p>Varianty můžeš vytvářet a upravovat. Režim skladu půjde změnit, až se vyřídí objednávky rezervující tento produkt. Jejich rezervace i dosavadní sklad zůstávají zachované. <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/?section=orders') ?>">Otevřít objednávky</a></p></div><?php endif; ?>
    <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>">
    <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="mode"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($variantInventory['expected']) ?>"><input type="hidden" name="enabled" value="<?= $variantInventory['enabled'] ? '0' : '1' ?>">
    <label class="variant-confirm"><input type="checkbox" name="confirm" value="1" required <?= $variantModeBlocked ? 'disabled' : '' ?>> <?= $variantInventory['enabled'] ? 'Potvrzuji návrat ke společnému skladu. Jeho počet kusů nastavím po přepnutí; zásoby variant zůstanou uložené.' : 'Potvrzuji, že mám u variant uložené správné volné kusy a dostupnost. Společný počet kusů se při přepnutí vynuluje.' ?></label><button class="inline-small" type="submit" <?= $variantModeBlocked ? 'disabled' : '' ?>><?= $variantInventory['enabled'] ? 'Přejít na společný sklad' : 'Zapnout sklad variant' ?></button>
  </form></details><?php endif; ?>
  <?php require __DIR__ . '/product-variant-merge.php'; ?>
  <script defer src="<?= $escape($basePath . 'assets/product-variant-editor.js?v=' . filemtime(__DIR__ . '/../../assets/product-variant-editor.js')) ?>"></script>
</section>
<?php endif; ?>
