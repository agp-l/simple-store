<?php
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$image = str_starts_with($product['image_path'], 'images/')
    ? $basePath . $product['image_path'] : $product['image_path'];
$sizes = array_filter(array_map('trim', explode(',', $product['sizes'] ?? '')), 'strlen');
$labels = ['batohy' => 'Batohy', 'stany' => 'Stany', 'spacaky' => 'Spacáky',
    'vybaveni' => 'Vybavení', 'obleceni' => 'Oblečení', 'boty' => 'Boty'];
$stockText = ['in_stock' => 'Skladem', 'on_order' => 'Na objednávku', 'out_of_stock' => 'Není skladem'];
?>
  <main class="wrap detail-page" id="produkty">
    <nav class="breadcrumbs" aria-label="Drobečková navigace"><a href="<?= $siteRoot ?>index.php">Úvod</a><span>/</span><a href="<?= $siteRoot ?>index.php?category=<?= $escape($product['category']) ?>#produkty"><?= $escape($labels[$product['category']] ?? 'Vybavení') ?></a><span>/</span><span><?= $escape($product['name']) ?></span></nav>
    <div class="product-detail">
      <div class="detail-gallery"><img src="<?= $escape($image) ?>" alt="<?= $escape($product['name']) ?>" width="1200" height="1200"></div>
      <div class="detail-info">
        <p class="product-brand"><?= $escape($product['brand']) ?> · <?= $escape($labels[$product['category']] ?? 'Vybavení') ?></p>
        <h1><?= $escape($product['name']) ?></h1>
        <?php if ($product['summary'] !== ''): ?><p class="detail-lead"><?= $escape($product['summary']) ?></p><?php endif; ?>
        <?php if ($sizes !== []): ?>
          <label class="field-label" for="shoe-size">Velikost produktu</label>
          <select id="shoe-size" name="size" required><option value="">Vyberte velikost</option>
            <?php foreach ($sizes as $size): ?><option value="<?= $escape($size) ?>"><?= $escape($size) ?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>
        <div class="detail-price"><strong><?= number_format((int) $product['price_czk'], 0, ',', ' ') ?> Kč</strong><span class="stock <?= $product['stock_status'] === 'in_stock' ? '' : 'stock-wait' ?>"><span class="stock-dot" aria-hidden="true"></span><?= $escape($stockText[$product['stock_status']] ?? '') ?></span></div>
        <div class="buy-row"><div class="quantity" aria-label="Počet kusů"><button type="button" id="minus" aria-label="Ubrat kus">−</button><output id="qty">1</output><button type="button" id="plus" aria-label="Přidat kus">+</button></div>
          <button class="detail-add" type="button" id="detail-add" data-name="<?= $escape($product['name']) ?>" data-price="<?= (int) $product['price_czk'] ?>" <?= $product['stock_status'] === 'out_of_stock' ? 'disabled' : '' ?>><?= $product['stock_status'] === 'out_of_stock' ? 'Není skladem' : 'Přidat do košíku' ?></button></div>
        <p class="detail-feedback" id="detail-feedback" role="status" aria-live="polite"></p>
      </div>
    </div>
    <?php if ($product['description'] !== ''): ?>
      <section class="story"><h2>O produktu</h2><div class="cms-text"><?= nl2br($escape($product['description'])) ?></div></section>
    <?php endif; ?>
    <p><a href="<?= $siteRoot ?>index.php#produkty">← Zpět na všechny produkty</a></p>
  </main>
