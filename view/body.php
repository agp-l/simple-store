<?php
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$productCount = count($products);
$productWord = $productCount === 1 ? 'produkt' : ($productCount >= 2 && $productCount <= 4 ? 'produkty' : 'produktů');
?>
  <main class="wrap" id="produkty">
    <nav class="breadcrumbs" aria-label="Drobečková navigace">
      <a href="<?= $escape($siteRoot . $language) ?>">Úvod</a>
      <?php foreach ($categoryTrail as $i => $crumb): ?>
        <span aria-hidden="true">/</span>
        <?php if ($i === count($categoryTrail) - 1): ?><span><?= $escape($crumb['title']) ?></span>
        <?php else: ?><a href="<?= $escape($siteRoot . $language . '/kategorie-produktu/' . $crumb['path']) ?>"><?= $escape($crumb['title']) ?></a><?php endif; ?>
      <?php endforeach; ?>
      <?php if ($categoryTrail === []): ?><span aria-hidden="true">/</span><span>Vybavení do přírody</span><?php endif; ?>
    </nav>
    <div class="section-heading">
      <div>
        <h2 id="section-title"><?= $escape($currentCategory['title'] ?? 'Objevte vybavení') ?></h2>
        <p id="section-description"><?= $currentCategory === null ? 'Poctivý výběr pro pohodlí na stezce i mimo ni.' : 'Vybavení na každou cestu. Vyberte si z nabídky níže.' ?></p>
      </div><span class="result-count" id="result-count" aria-live="polite">Zobrazeno <?= $productCount . ' ' . $productWord ?></span>
    </div>
    <div class="tools">
      <?php if ($categoryMenuRoot !== null && $categoryMenu !== []): ?>
        <nav class="subcategory-filters" aria-label="Podkategorie <?= $escape($categoryMenuRoot['title']) ?>">
          <?php foreach ($categoryMenu as $link): ?><a class="filter<?= $link['active'] ? ' active' : '' ?>" href="<?= $escape($link['href']) ?>#produkty" <?= ($currentCategory['path'] ?? '') === $link['path'] ? 'aria-current="page"' : '' ?>><?= $escape($link['label']) ?></a><?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <label class="sort-wrap">Řadit podle
        <select id="sort">
          <option value="default">Doporučené</option>
          <option value="price-asc">Od nejlevnějšího</option>
          <option value="price-desc">Od nejdražšího</option>
          <option value="name">Podle názvu</option>
        </select></label>
    </div>
    <section class="catalog" id="catalog" aria-label="Nabídka produktů">
      <?php if ($products !== []): ?>
        <?php foreach ($products as $product): require __DIR__ . '/product-card.php'; endforeach; ?>
      <?php endif; ?>
    </section>
    <div class="empty" id="empty" <?= $products !== [] ? 'hidden' : '' ?>>Zatím tu nejsou zveřejněné produkty. Nabídku připravujeme.</div>
    <section class="category-panel" id="kategorie" aria-labelledby="category-title">
      <div class="category-panel-copy">
        <h2 id="category-title">Kam dál?</h2>
        <p>Zvolte si směr a vyberte výbavu pro další cestu.</p>
      </div>
      <div class="category-list"><?php foreach ($primaryMenu as $link): ?><a href="<?= $escape($link['href']) ?>#produkty"><?= $escape($link['label']) ?></a><?php endforeach; ?></div>
    </section>
  </main>
