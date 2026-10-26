<?php declare(strict_types=1); ?>
<div class="variant-table-wrap base-offer-table" id="product-base-offer">
  <table class="variant-table"><thead><tr><th>Provedení</th><th>Volné kusy</th><th>Dostupnost</th><th>Prodejní cena</th><th>Uložení</th></tr></thead>
    <tbody class="variant-table-group">
      <tr class="base-offer-row">
        <th scope="row"><?= $variantRows === [] ? 'Základní provedení' : 'Společná nabídka produktu' ?><small>Zákazník nevybírá skladovou variantu.</small></th>
        <td data-label="Volné kusy"><?php if ($stockReady ?? false): ?><input type="number" form="product-base-stock" name="quantity" aria-label="Volné kusy skladem" min="0" max="1000000" value="<?= (int) ($product['stock_quantity'] ?? 0) ?>" required inputmode="numeric"><?php else: ?>Nejprve aktualizuj databázi.<?php endif; ?></td>
        <td data-label="Dostupnost"><select data-editor-select="stock_status" aria-label="Dostupnost"><?php foreach ($stockText as $code => $text): ?><option value="<?= $code ?>" <?= $product['stock_status'] === $code ? 'selected' : '' ?>><?= $escape($text) ?></option><?php endforeach; ?></select><small>Uloží se po výběru</small></td>
        <td data-label="Prodejní cena"><a href="#product-price"><?= number_format((int) $product['price_czk'], 0, ',', ' ') ?> Kč</a><small>Upravit společnou cenu výše</small></td>
        <td data-label="Uložení"><?php if ($stockReady ?? false): ?>
          <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>" id="product-base-stock" class="inline-stock-form">
            <input type="hidden" name="action" value="set-product-stock"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
            <input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
            <input type="hidden" name="expected" value="<?= (int) ($product['stock_quantity'] ?? 0) ?>">
            <button type="submit" class="inline-small"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit sklad</button><small>Uloží pouze počet kusů</small>
          </form>
        <?php endif; ?></td>
      </tr>
      <?php if ($variantRows === []): ?><tr class="variant-partner-row"><td colspan="5"><?php $partnerSourceOnly = false; require __DIR__.'/product-suppliers.php'; ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
