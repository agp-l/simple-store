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
<section class="product-admin-settings variant-manager" id="product-variants" aria-labelledby="variant-heading">
  <div class="product-admin-settings-heading"><strong id="variant-heading"><?= \SimpleStore\Rendering\Icon::render('package') ?> Varianty a sklad</strong><span class="editor-chip"><?= $variantInventory['enabled'] ? 'Sklad po variantách' : 'Společný sklad produktu' ?> · <?= count($variantRows) ?> variant</span></div>
  <?php if ($variantError !== ''): ?><p class="panel-error" role="alert"><?= $escape($variantError) ?></p><?php endif; ?>
  <?php if ($variantRows !== []): ?><p>Cena a fotografie mohou zůstat prázdné — použijí se údaje produktu. Vlastnosti varianty jsou stálé; jinou kombinaci přidej jako novou.</p>
  <div class="variant-table-wrap"><table class="variant-table"><thead><tr><th>Varianta</th><th>SKU</th><th>Volné kusy</th><th>Dostupnost</th><th>Cena Kč</th><th>Fotografie</th><th>Nabízet</th><th></th></tr></thead><tbody>
  <?php foreach ($variantRows as $variant):
      $formId = 'variant-' . $variant['id'];
      $rowForm = $variantFailedRows[$variant['id']] ?? $variantForm;
      $failedRow = ($rowForm['variant_id'] ?? '') === $variant['id'];
      $value = static fn (string $field, mixed $fallback): mixed => $failedRow ? ($rowForm[$field] ?? $fallback) : $fallback;
  ?>
    <tr data-variant-row="<?= $variant['id'] ?>" class="<?= $variant['enabled'] ? '' : 'variant-disabled' ?>"><th scope="row"><?= $escape(\SimpleStore\Product\ProductVariants::label($variant['choices'])) ?></th>
      <td><input form="<?= $formId ?>" name="sku" aria-label="SKU" maxlength="100" value="<?= $escape($value('sku', $variant['sku'])) ?>"></td>
      <td><input form="<?= $formId ?>" name="quantity" aria-label="Volné kusy" type="number" min="0" max="1000000" required value="<?= $escape($value('quantity', $variant['quantity'])) ?>"></td>
      <td><select form="<?= $formId ?>" name="stock_status" aria-label="Dostupnost"><?php foreach ($stockText as $code => $label): ?><option value="<?= $code ?>" <?= $value('stock_status', $variant['stock_status']) === $code ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></td>
      <td><input form="<?= $formId ?>" name="price_czk" aria-label="Cena Kč" type="number" min="1" max="10000000" placeholder="<?= (int) $product['price_czk'] ?>" value="<?= $escape($value('price_czk', $variant['price_czk'] ?? '')) ?>"></td>
      <td><select form="<?= $formId ?>" name="image_path" aria-label="Fotografie"><option value="">Společná</option><?php foreach (array_unique(array_merge([$product['image_path']], $details['gallery'], [$variant['image_path']])) as $image): if ($image === '') continue; ?><option value="<?= $escape($image) ?>" <?= $value('image_path', $variant['image_path']) === $image ? 'selected' : '' ?>><?= $escape(basename($image)) ?></option><?php endforeach; ?></select></td>
      <td><input form="<?= $formId ?>" type="checkbox" name="enabled" value="1" aria-label="Nabízet variantu" <?= ($failedRow ? ($rowForm['enabled'] ?? '') === '1' : $variant['enabled']) ? 'checked' : '' ?>></td>
      <td><form id="<?= $formId ?>" method="post" action="<?= $escape($siteRoot . 'admin.php') ?>">
        <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="row"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($failedRow ? ($variantForm['expected'] ?? '') : $variantInventory['expected']) ?>"><input type="hidden" name="variant_id" value="<?= $variant['id'] ?>">
        <button class="inline-small" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit</button>
      </form></td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
  <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>" id="variant-batch" hidden>
    <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="batch"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($variantForm['expected'] ?? $variantInventory['expected']) ?>"><input type="hidden" name="rows_json">
    <button class="inline-small" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit celou tabulku variant</button>
  </form>
  <script defer src="<?= $escape($basePath . 'assets/product-variant-editor.js?v=' . filemtime(__DIR__ . '/../../assets/product-variant-editor.js')) ?>"></script>
  <?php endif; ?>
  <details class="variant-create"><summary><?= \SimpleStore\Rendering\Icon::render('plus') ?> <?= $variantRows === [] ? 'Vytvořit varianty' : 'Přidat kombinace' ?></summary>
    <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>">
      <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="generate"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($variantInventory['expected']) ?>">
      <label>Vlastnosti a jejich hodnoty<textarea name="groups" rows="4" maxlength="16000" placeholder="Velikost: S | M | L&#10;Barva: Modrá | Červená" required><?= $escape($variantForm['groups'] ?? implode("\n", $variantGroups)) ?></textarea></label>
      <p>Každá vlastnost na nový řádek, hodnoty odděl znakem |. Pro jednu konkrétní kombinaci napiš u každé vlastnosti jen jednu hodnotu. Existující kombinace se nepřepíšou.</p>
      <label class="variant-confirm"><input type="checkbox" name="confirm" value="1" required> Zapnout sklad variant. Nové kombinace začnou s 0 kusy; dosavadní společný počet se nerozdělí a při přepnutí se vynuluje.</label>
      <button class="inline-small" type="submit"><?= \SimpleStore\Rendering\Icon::render('plus') ?> Vytvořit kombinace</button>
    </form>
  </details>
  <?php if ($variantRows !== []): ?><details class="variant-create"><summary>Změnit režim skladu</summary><form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>">
    <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="mode"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($variantInventory['expected']) ?>"><input type="hidden" name="enabled" value="<?= $variantInventory['enabled'] ? '0' : '1' ?>">
    <label class="variant-confirm"><input type="checkbox" name="confirm" value="1" required> Rozumím, že společný počet kusů se vynuluje. Zásoby variant zůstanou uložené.</label><button class="inline-small" type="submit"><?= $variantInventory['enabled'] ? 'Přejít na společný sklad' : 'Zapnout sklad variant' ?></button>
  </form></details><?php endif; ?>
  <?php require __DIR__ . '/product-variant-merge.php'; ?>
</section>
<?php endif; ?>
