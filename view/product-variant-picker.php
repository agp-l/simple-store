<label class="field-label" for="product-variant">Vyberte variantu</label>
<select id="product-variant" name="variant_key" class="product-option" required>
  <option value="">Velikost, barva nebo jiné provedení…</option>
  <?php foreach ($product['variant_inventory']['items'] as $variant): if (!$variant['enabled']) continue;
    $state = \SimpleStore\Product\ProductVariants::availability($variant);
    $price = $variant['price_czk'] ?? (int) $product['price_czk'];
  ?>
  <option value="<?= $variant['id'] ?>" <?= $state === 'out_of_stock' ? 'disabled' : '' ?> data-price="<?= $price ?>" data-btc="<?= $escape($priceDisplay instanceof \SimpleStore\Pricing\BitcoinPriceDisplay ? ($priceDisplay->bitcoin($price) ?? '') : '') ?>" data-image="<?= $escape($imageUrl($variant['image_path'] ?: $product['image_path'])) ?>" data-stock="<?= $escape($stockText[$state]) ?>"><?= $escape(\SimpleStore\Product\ProductVariants::label($variant['choices'])) ?> — <?= number_format($price, 0, ',', ' ') ?> Kč — <?= $escape($stockText[$state]) ?></option>
  <?php endforeach; ?>
</select>
<p class="variant-purchase-note">Cena a dostupnost jsou uvedeny u jednotlivých variant.</p>
