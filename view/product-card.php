<?php
use SimpleStore\Product\ProductDetails;
use SimpleStore\Category\CategoryPath;
use SimpleStore\Media\MediaPath;

// This view uses a published product row from the catalog query.
$productLink = $basePath . $language . '/produkt/' . rawurlencode($product['slug']);
$cardPath = MediaPath::variant($product['image_path'], 'card');
$productImage = str_starts_with($cardPath, 'images/')
    ? $basePath . $cardPath : $cardPath;
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$categoryPath = CategoryPath::fromProduct($product);
$categoryLabel = $categoryLabels[$categoryPath] ?? $categoryLabels[explode('/', $categoryPath)[0]] ?? 'Vybavení';
$stockText = ['in_stock' => 'Skladem', 'on_order' => 'Na objednávku', 'out_of_stock' => 'Není skladem'];
$hasOptions = ProductDetails::decode($product['details_json'] ?? null, $product['sizes'] ?? '')['options'] !== [];
?>
      <article class="product-card" data-price="<?= (int) $product['price_czk'] ?>" data-name="<?= $escape($product['name']) ?>">
        <div class="product-media">
          <a href="<?= $escape($productLink) ?>" aria-label="Zobrazit <?= $escape($product['name']) ?>">
            <img class="product-image" src="<?= $escape($productImage) ?>" alt="<?= $escape($product['name']) ?>" loading="lazy" decoding="async">
          </a>
          <span class="media-label"><?= $escape($categoryLabel) ?></span>
        </div>
        <div class="product-body">
          <p class="product-brand"><?= $escape($product['brand']) ?></p>
          <h3><a href="<?= $escape($productLink) ?>"><?= $escape($product['name']) ?></a></h3>
          <?php if ($product['summary'] !== ''): ?><p><?= $escape($product['summary']) ?></p><?php endif; ?>
          <div class="product-meta"><span class="stock <?= $product['stock_status'] === 'in_stock' ? '' : 'stock-wait' ?>"><span class="stock-dot" aria-hidden="true"></span><?= $escape($stockText[$product['stock_status']] ?? '') ?></span></div>
          <div class="product-action"><strong><?= number_format((int) $product['price_czk'], 0, ',', ' ') ?> Kč</strong>
            <?php if ($hasOptions): ?>
              <a class="add-button" href="<?= $escape($productLink) ?>" aria-label="Zobrazit produkt <?= $escape($product['name']) ?>"><?= $product['stock_status'] === 'out_of_stock' ? 'Zobrazit detail →' : 'Vybrat možnosti →' ?></a>
            <?php else: ?>
              <button class="add-button" type="button" data-add <?= $product['stock_status'] === 'out_of_stock' ? 'disabled' : '' ?> aria-label="Přidat <?= $escape($product['name']) ?> do ukázkového košíku"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" /></svg><span><?= $product['stock_status'] === 'out_of_stock' ? 'Není skladem' : 'Do košíku' ?></span></button>
            <?php endif; ?>
          </div>
        </div>
      </article>
