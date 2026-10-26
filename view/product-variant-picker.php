<label class="field-label" for="product-variant">Vyberte variantu</label>
<select id="product-variant" name="variant_key" class="product-option" required data-color-name="<?= $escape(\SimpleStore\Product\VariantImages::color($product['variant_inventory']['items'][0]['choices'] ?? [])['name'] ?? '') ?>" data-base-price="<?= $escape($basePriceText) ?>" data-base-stock="<?= $escape($stockText[$baseAvailability] ?? '') ?>" data-base-state="<?= $escape($baseAvailability) ?>" data-base-btc="<?= $escape($priceDisplay instanceof \SimpleStore\Pricing\BitcoinPriceDisplay ? ($priceDisplay->bitcoin($baseOfferPrice) ?? '') : '') ?>" data-published="<?= empty($product['published']) ? '0' : '1' ?>" data-base-image="<?= $escape($imageUrl($product['image_path'])) ?>">
  <option value="">Velikost, barva nebo jiné provedení…</option>
  <?php foreach ($product['variant_inventory']['items'] as $variant): if (!$variant['enabled']) continue;
    $state = \SimpleStore\Product\ProductVariants::availability($variant);
    $price = $variant['price_czk'] ?? (int) $product['price_czk'];
  ?>
  <option value="<?= $variant['id'] ?>" <?= ($selectedVariant['id'] ?? '') === $variant['id'] ? 'selected' : '' ?> <?= $state === 'out_of_stock' ? 'disabled' : '' ?> data-choices="<?= $escape(json_encode($variant['choices'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>" data-state="<?= $state ?>" data-price="<?= $price ?>" data-btc="<?= $escape($priceDisplay instanceof \SimpleStore\Pricing\BitcoinPriceDisplay ? ($priceDisplay->bitcoin($price) ?? '') : '') ?>" data-image="<?= $escape($imageUrl(\SimpleStore\Product\VariantImages::resolve($variant, $product['variant_inventory']['items'], $product['image_path']))) ?>" data-stock="<?= $escape($stockText[$state]) ?>"><?= $escape(\SimpleStore\Product\ProductVariants::label($variant['choices'])) ?> — <?= number_format($price, 0, ',', ' ') ?> Kč — <?= $escape($stockText[$state]) ?></option>
  <?php endforeach; ?>
</select>
<p class="variant-purchase-note">Cena a dostupnost jsou uvedeny u jednotlivých variant.</p>
