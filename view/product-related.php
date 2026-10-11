<?php if ($relatedProducts !== []): ?>
<section class="product-related" aria-labelledby="product-related-title">
  <h2 id="product-related-title">Související produkty</h2>
  <div class="related-product-grid">
  <?php foreach ($relatedProducts as $related): ?>
    <article class="related-product-card">
      <a class="related-product-link" href="<?= $escape($siteRoot . $language . '/produkt/' . rawurlencode($related['slug'])) ?>">
        <img src="<?= $escape($imageUrl(\SimpleStore\Media\MediaPath::variant($related['image_path'], 'card'))) ?>" alt="<?= $escape($related['name']) ?>" loading="lazy" decoding="async">
        <h3><?= $escape($related['name']) ?></h3>
      </a>
      <?php if ($related['note'] !== ''): ?><p><?= $escape($related['note']) ?></p><?php endif; ?>
      <strong><?= number_format((int) $related['price_czk'], 0, ',', ' ') ?> Kč</strong>
      <?php $relatedBtc = $priceDisplay instanceof \SimpleStore\Pricing\BitcoinPriceDisplay ? $priceDisplay->bitcoin((int) $related['price_czk']) : null; ?>
      <?php if ($relatedBtc !== null): ?><small><?= $escape($relatedBtc) ?></small><?php endif; ?>
      <a class="add-button" href="<?= $escape($siteRoot . $language . '/produkt/' . rawurlencode($related['slug'])) ?>">Přejít na detail →</a>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
