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
$variantGroups = [];
foreach ($details['options'] as $group) $variantGroups[] = $group['name'] . ': ' . implode(' | ', $group['values']);
?>
<section class="product-admin-settings variant-manager" id="product-variants" aria-labelledby="variant-heading" data-variant-manager data-base-price="<?= (int) $product['price_czk'] ?>" data-recovered="<?= $variantError !== '' && in_array($variantForm['operation'] ?? '', ['row', 'batch', 'inherit-prices'], true) ? '1' : '0' ?>">
  <div class="product-admin-settings-heading"><strong id="variant-heading"><?= \SimpleStore\Rendering\Icon::render('package') ?> Varianty a sklad</strong><span class="editor-chip"><?= $variantInventory['enabled'] ? 'Sklad po variantách' : 'Společný sklad produktu' ?> · <?= count($variantRows) ?> variant</span></div>
  <?php if (($_GET['variants_saved'] ?? '') === '1' && $variantError === ''): ?><p class="inline-stock-notice" role="status">Varianty a sklad byly uloženy.</p><?php endif; ?>
  <?php if ($variantError !== '' && !in_array($variantForm['operation'] ?? '', ['merge', 'merge-preview'], true)): ?><p class="panel-error" role="alert"><?= $escape($variantError) ?></p><?php endif; ?>
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
      <button class="inline-small" type="submit" form="variant-batch" data-variant-save-top hidden>Uložit změny variant</button>
    </form>
    <?php else: ?><p>Produkt můžeš prodávat i bez variant. Varianty vytvářej, jen když zákazník vybírá například velikost nebo barvu.</p><?php endif; ?>
  </div>
  <?php if ($variantRows !== []): ?>
  <p data-variant-save-state role="status" hidden></p>
  <p>SKU je nepovinný interní kód varianty, například BUNDA-M-MODRA. Prázdná fotografie přebírá obrázek produktu. Vlastnosti varianty jsou stálé; jinou kombinaci přidej jako novou.</p>
  <div class="variant-table-wrap"><table class="variant-table"><thead><tr><th>Varianta</th><th>SKU</th><th>Volné kusy</th><th>Dostupnost</th><th>Vlastní cena Kč</th><th>Fotografie</th><th>Nabízet</th><th></th></tr></thead><tbody>
  <?php foreach ($variantRows as $variant):
      $formId = 'variant-' . $variant['id'];
      $rowForm = $variantFailedRows[$variant['id']] ?? $variantForm;
      $failedRow = ($rowForm['variant_id'] ?? '') === $variant['id'];
      $value = static fn (string $field, mixed $fallback): mixed => $failedRow ? ($rowForm[$field] ?? $fallback) : $fallback;
  ?>
    <tr data-variant-row="<?= $variant['id'] ?>" class="<?= $variant['enabled'] ? '' : 'variant-disabled' ?>"><th scope="row"><?= $escape(\SimpleStore\Product\ProductVariants::label($variant['choices'])) ?></th>
      <td data-label="SKU"><input form="<?= $formId ?>" name="sku" aria-label="SKU" maxlength="100" value="<?= $escape($value('sku', $variant['sku'])) ?>"></td>
      <td data-label="Volné kusy"><input form="<?= $formId ?>" name="quantity" aria-label="Volné kusy" type="number" min="0" max="1000000" required value="<?= $escape($value('quantity', $variant['quantity'])) ?>"></td>
      <td data-label="Dostupnost"><select form="<?= $formId ?>" name="stock_status" aria-label="Dostupnost"><?php foreach ($stockText as $code => $label): ?><option value="<?= $code ?>" <?= $value('stock_status', $variant['stock_status']) === $code ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></td>
      <td data-label="Vlastní cena Kč"><input form="<?= $formId ?>" name="price_czk" aria-label="Vlastní cena Kč" aria-describedby="variant-price-note-<?= $variant['id'] ?>" type="number" min="1" max="10000000" placeholder="<?= (int) $product['price_czk'] ?>" value="<?= $escape($value('price_czk', $variant['price_czk'] ?? '')) ?>"><small id="variant-price-note-<?= $variant['id'] ?>" data-variant-price-note><?= (string) $value('price_czk', $variant['price_czk'] ?? '') === '' ? 'Společná cena' : 'Vlastní cena' ?></small></td>
      <td data-label="Fotografie"><select form="<?= $formId ?>" name="image_path" aria-label="Fotografie"><option value="">Společná</option><?php foreach (array_unique(array_merge([$product['image_path']], $details['gallery'], [$variant['image_path']])) as $variantImage): if ($variantImage === '') continue; ?><option value="<?= $escape($variantImage) ?>" <?= $value('image_path', $variant['image_path']) === $variantImage ? 'selected' : '' ?>><?= $escape(basename($variantImage)) ?></option><?php endforeach; ?></select></td>
      <td data-label="Nabízet"><input form="<?= $formId ?>" type="checkbox" name="enabled" value="1" aria-label="Nabízet variantu" <?= ($failedRow ? ($rowForm['enabled'] ?? '') === '1' : $variant['enabled']) ? 'checked' : '' ?>></td>
      <td data-label="Akce"><form id="<?= $formId ?>" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>">
        <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="row"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($failedRow ? ($variantForm['expected'] ?? '') : $variantInventory['expected']) ?>"><input type="hidden" name="variant_id" value="<?= $variant['id'] ?>">
        <button class="inline-small" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit</button>
      </form></td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
  <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>" id="variant-batch" hidden>
    <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="batch"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($variantForm['expected'] ?? $variantInventory['expected']) ?>"><input type="hidden" name="rows_json">
    <button class="inline-small" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit celou tabulku variant</button>
  </form>
  <?php endif; ?>
  <details class="variant-create" id="variant-create" <?= $variantRows === [] || ($variantForm['operation'] ?? '') === 'generate' ? 'open' : '' ?>><summary><?= \SimpleStore\Rendering\Icon::render('plus') ?> <?= $variantRows === [] ? 'Vytvořit varianty' : 'Přidat kombinace' ?></summary>
    <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>" data-variant-generate>
      <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="generate"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($variantInventory['expected']) ?>">
      <label>1. Vlastnosti a jejich hodnoty<textarea name="groups" rows="4" maxlength="16000" aria-describedby="variant-groups-help variant-generate-error" placeholder="Velikost: S | M | L&#10;Barva: Modrá | Červená" required><?= $escape($variantForm['groups'] ?? implode("\n", $variantGroups)) ?></textarea></label>
      <p id="variant-groups-help">Například <code>Velikost: S | M | L</code> vytvoří tři varianty. Každou další vlastnost dej na nový řádek. Text v šedé ukázce je potřeba nahradit vlastními hodnotami. Existující kombinace se nepřepíšou.</p>
      <p class="variant-generate-preview" data-variant-generate-preview role="status" aria-live="polite" hidden></p>
      <?php if (!$variantInventory['enabled']): ?><label class="variant-confirm"><input type="checkbox" name="confirm" value="1" required <?= ($variantForm['operation'] ?? '') === 'generate' && ($variantForm['confirm'] ?? '') === '1' ? 'checked' : '' ?>> 2. Potvrzuji zapnutí skladu variant. Nové kombinace začnou s 0 kusy; dosavadní společný počet se nerozdělí a při přepnutí se vynuluje.</label>
      <?php else: ?><input type="hidden" name="confirm" value="1"><p>Nové varianty začnou s 0 kusy a společnou cenou produktu. Sklad a ceny existujících variant zůstanou zachované.</p><?php endif; ?>
      <p class="panel-error" id="variant-generate-error" data-variant-generate-error role="alert" hidden></p>
      <button class="inline-small" type="submit"><?= \SimpleStore\Rendering\Icon::render('plus') ?> Vytvořit kombinace</button>
    </form>
  </details>
  <?php if ($variantRows !== []): ?><details class="variant-create"><summary>Změnit režim skladu</summary><form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>">
    <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="mode"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($variantInventory['expected']) ?>"><input type="hidden" name="enabled" value="<?= $variantInventory['enabled'] ? '0' : '1' ?>">
    <label class="variant-confirm"><input type="checkbox" name="confirm" value="1" required> Rozumím, že společný počet kusů se vynuluje. Zásoby variant zůstanou uložené.</label><button class="inline-small" type="submit"><?= $variantInventory['enabled'] ? 'Přejít na společný sklad' : 'Zapnout sklad variant' ?></button>
  </form></details><?php endif; ?>
  <?php require __DIR__ . '/product-variant-merge.php'; ?>
  <script defer src="<?= $escape($basePath . 'assets/product-variant-editor.js?v=' . filemtime(__DIR__ . '/../../assets/product-variant-editor.js')) ?>"></script>
</section>
<?php endif; ?>
