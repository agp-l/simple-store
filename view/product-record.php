<?php
use SimpleStore\Product\ProductDetails;
use SimpleStore\Category\CategoryPath;
use SimpleStore\Product\ProductText;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$image = str_starts_with($product['image_path'], 'images/')
    ? $basePath . $product['image_path'] : $product['image_path'];
$details = ProductDetails::decode($product['details_json'] ?? null, $product['sizes'] ?? '');
$imageUrl = static fn (string $path): string => str_starts_with($path, 'images/') ? $basePath . $path : $path;
$categoryPath = CategoryPath::fromProduct($product);
$categoryLabel = $categoryLabels[$categoryPath] ?? $categoryLabels[explode('/', $categoryPath)[0]] ?? 'Vybavení';
$stockText = ['in_stock' => 'Skladem', 'on_order' => 'Na objednávku', 'out_of_stock' => 'Není skladem'];
?>
  <main class="wrap detail-page" id="produkty">
    <nav class="breadcrumbs" aria-label="Drobečková navigace"><a href="<?= $escape($siteRoot . $language) ?>">Úvod</a>
      <?php foreach ($categoryTrail as $crumb): ?><span>/</span><a href="<?= $escape($siteRoot . $language . '/kategorie-produktu/' . $crumb['path']) ?>"><?= $escape($crumb['title']) ?></a><?php endforeach; ?>
      <span>/</span><span><?= $escape($product['name']) ?></span></nav>
    <div class="product-detail">
      <div class="detail-media"><div class="detail-gallery<?= $product['category'] === 'boty' ? ' detail-gallery--footwear' : '' ?>"><img id="detail-image" src="<?= $escape($image) ?>" alt="<?= $escape($product['name']) ?>" width="1200" height="1200"></div>
        <?php if ($details['gallery'] !== []): ?><div class="detail-thumbs" aria-label="Fotografie produktu">
          <?php foreach (array_merge([$product['image_path']], $details['gallery']) as $i => $path): ?>
            <button type="button" class="detail-thumb" data-gallery-image="<?= $escape($imageUrl($path)) ?>" aria-label="Zobrazit fotografii <?= $i + 1 ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><img src="<?= $escape($imageUrl($path)) ?>" alt="" loading="lazy"></button>
          <?php endforeach; ?></div><?php endif; ?></div>
      <div class="detail-info">
        <p class="product-brand"><?= $escape($product['brand']) ?> · <?= $escape($categoryLabel) ?></p>
        <h1><?= $escape($product['name']) ?></h1>
        <?php if ($product['summary'] !== ''): ?><p class="detail-lead"><?= $escape($product['summary']) ?></p><?php endif; ?>
        <?php foreach ($details['options'] as $i => $group): ?>
          <label class="field-label" for="product-option-<?= $i ?>"><?= $escape($group['name']) ?></label>
          <select id="product-option-<?= $i ?>" class="product-option" data-option-name="<?= $escape($group['name']) ?>" required><option value="">Vyberte <?= $escape($group['name']) ?></option>
            <?php foreach ($group['values'] as $value): ?><option value="<?= $escape($value) ?>"><?= $escape($value) ?></option><?php endforeach; ?>
          </select>
        <?php endforeach; ?>
        <div class="detail-price"><strong><?= number_format((int) $product['price_czk'], 0, ',', ' ') ?> Kč</strong><span class="stock <?= $product['stock_status'] === 'in_stock' ? '' : 'stock-wait' ?>"><span class="stock-dot" aria-hidden="true"></span><?= $escape($stockText[$product['stock_status']] ?? '') ?></span></div>
        <div class="buy-row"><div class="quantity" aria-label="Počet kusů"><button type="button" id="minus" aria-label="Ubrat kus">−</button><output id="qty">1</output><button type="button" id="plus" aria-label="Přidat kus">+</button></div>
          <button class="detail-add" type="button" id="detail-add" data-name="<?= $escape($product['name']) ?>" data-price="<?= (int) $product['price_czk'] ?>" <?= $product['stock_status'] === 'out_of_stock' ? 'disabled' : '' ?>><?= $product['stock_status'] === 'out_of_stock' ? 'Není skladem' : 'Přidat do košíku' ?></button></div>
        <p class="detail-feedback" id="detail-feedback" role="status" aria-live="polite"></p>
      </div>
    </div>
    <?php if ($product['description'] !== '' || $details['sections'] !== [] || $details['specifications'] !== []): ?>
      <div class="product-extra<?= $product['description'] === '' && $details['sections'] === [] ? ' specs-only' : '' ?>">
        <?php if ($product['description'] !== '' || $details['sections'] !== []): ?><section class="story" aria-label="Popis produktu"><h2>O produktu</h2>
        <?php if ($product['description'] !== ''): ?><div class="cms-text product-intro"><?= nl2br($escape($product['description'])) ?></div><?php endif; ?>
        <?php foreach ($details['sections'] as $section): ?>
          <section class="product-content-block">
            <?php if ($section['heading'] !== ''): ?><h3><?= $escape($section['heading']) ?></h3><?php endif; ?>
            <?php if ($section['type'] === 'list'): ?><ul class="feature-list">
              <?php foreach (preg_split('/\R/u', $section['body']) ?: [] as $line): ?>
                <?php if (trim($line) !== ''): ?><li><?= ProductText::inline(trim($line)) ?></li><?php endif; ?>
              <?php endforeach; ?></ul>
            <?php elseif ($section['type'] === 'table'): ?><div class="table-scroll"><table class="spec-table"><tbody>
              <?php foreach (preg_split('/\R/u', $section['body']) ?: [] as $line): ?>
                <?php if (trim($line) !== ''): ?>
                  <?php [$label, $value] = array_map('trim', explode('|', $line, 2)); ?>
                  <tr><th scope="row"><?= $escape($label) ?></th><td><?= ProductText::inline($value) ?></td></tr>
                <?php endif; ?>
              <?php endforeach; ?></tbody></table></div>
            <?php elseif ($section['type'] === 'image'): ?>
              <figure class="product-story-image"><img src="<?= $escape($imageUrl($section['body'])) ?>" alt="<?= $escape($section['heading'] !== '' ? $section['heading'] : $product['name']) ?>" loading="lazy"></figure>
            <?php else: ?>
              <?php foreach (preg_split('/\R\s*\R/u', $section['body']) ?: [] as $paragraph): ?><p><?= nl2br(ProductText::inline(trim($paragraph))) ?></p><?php endforeach; ?>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </section><?php endif; ?>
      <?php if ($details['specifications'] !== []): ?><aside class="product-specs"><h2>Technické údaje</h2><div class="table-scroll"><table class="spec-table"><tbody>
        <?php foreach ($details['specifications'] as $spec): ?><tr><th scope="row"><?= $escape($spec['name']) ?></th><td><?= $escape($spec['value']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></aside><?php endif; ?></div>
    <?php endif; ?>
    <p><a href="<?= $escape($siteRoot . $language . ($categoryTrail !== [] ? '/kategorie-produktu/' . $categoryPath : '')) ?>#produkty">← Zpět na produkty</a></p>
  </main>
