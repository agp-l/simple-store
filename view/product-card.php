<?php
use SimpleStore\Product\ProductDetails;
use SimpleStore\Category\CategoryPath;
use SimpleStore\Media\MediaPath;

// This view uses a published product row from the catalog query.
$productLink = $basePath . $language . '/produkt/' . rawurlencode($product['slug']) . (($managingCatalog ?? false) ? '?edit=1' : '');
$cardPath = MediaPath::variant($product['image_path'], 'card');
$productImage = str_starts_with($cardPath, 'images/')
    ? $basePath . $cardPath : $cardPath;
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$categoryPath = CategoryPath::fromProduct($product);
$categoryLabel = $categoryLabels[$categoryPath] ?? $categoryLabels[explode('/', $categoryPath)[0]] ?? 'Vybavení';
$stockText = ['in_stock' => 'Skladem', 'on_order' => 'Na objednávku', 'out_of_stock' => 'Není skladem'];
$availability = $product['availability_status'] ?? $product['stock_status'];
$hasOptions = ProductDetails::decode($product['details_json'] ?? null, $product['sizes'] ?? '')['options'] !== [];
?>
      <article class="product-card" data-price="<?= (int) $product['price_czk'] ?>" data-name="<?= $escape($product['name']) ?>">
        <div class="product-media">
          <a href="<?= $escape($productLink) ?>" aria-label="Zobrazit <?= $escape($product['name']) ?>">
            <img class="product-image" src="<?= $escape($productImage) ?>" alt="<?= $escape($product['name']) ?>" loading="lazy" decoding="async">
          </a>
          <a class="media-label" href="<?= $escape($basePath . $language . '/kategorie-produktu/' . $categoryPath) ?>" aria-label="Prohlédnout kategorii <?= $escape($categoryLabel) ?>"><?= $escape($categoryLabel) ?></a>
          <?php if ($managingCatalog ?? false): ?><span class="manage-product-state <?= $product['published'] ? 'is-public' : '' ?>"><?= $product['published'] ? 'Zveřejněný' : 'Skrytý koncept' ?></span><?php endif; ?>
        </div>
        <div class="product-body">
          <p class="product-brand"><?= $escape($product['brand']) ?></p>
          <h3><a href="<?= $escape($productLink) ?>"><?= $escape($product['name']) ?></a></h3>
          <?php if ($product['summary'] !== ''): ?><p class="product-summary"><?= $escape($product['summary']) ?></p><?php endif; ?>
          <div class="product-meta"><span class="stock <?= $availability === 'in_stock' ? '' : 'stock-wait' ?>"><span class="stock-dot" aria-hidden="true"></span><?= $escape($stockText[$availability] ?? '') ?></span><?php if (($managingCatalog ?? false) && isset($product['stock_quantity'])): ?> <small>Volné: <?= (int) $product['stock_quantity'] ?> ks</small><?php endif; ?></div>
          <div class="product-action"><span class="product-prices"><strong><?= number_format((int) $product['price_czk'], 0, ',', ' ') ?> Kč</strong><?php $btcPrice = $priceDisplay instanceof \SimpleStore\Pricing\BitcoinPriceDisplay ? $priceDisplay->bitcoin((int) $product['price_czk']) : null; ?><?php if ($btcPrice !== null): ?><small><?= $escape($btcPrice) ?></small><?php endif; ?></span>
            <?php if ($managingCatalog ?? false): ?>
              <a class="add-button" href="<?= $escape($productLink) ?>">Upravit produkt →</a>
            <?php elseif ($hasOptions): ?>
              <a class="add-button" href="<?= $escape($productLink) ?>" aria-label="Zobrazit produkt <?= $escape($product['name']) ?>">Přejít na detail</a>
            <?php else: ?>
              <form method="post" action="<?= $escape($cartUrl) ?>">
                <input type="hidden" name="action" value="add"><input type="hidden" name="csrf" value="<?= $escape($cartToken) ?>">
                <input type="hidden" name="product_key" value="<?= $escape($product['product_key'] ?? '') ?>">
                <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="quantity" value="1">
                <button class="add-button" type="submit" <?= $availability === 'out_of_stock' ? 'disabled' : '' ?> aria-label="Přidat <?= $escape($product['name']) ?> do košíku"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" /></svg><span><?= $availability === 'out_of_stock' ? 'Není skladem' : 'Do košíku' ?></span></button>
              </form>
            <?php endif; ?>
          </div>
          <?php if (($canManageCatalog ?? false) && !($managingCatalog ?? false)): ?><a class="card-edit-link" href="<?= $escape($productLink . '?edit=1') ?>">✎ Upravit produkt</a><?php endif; ?>
        </div>
      </article>
