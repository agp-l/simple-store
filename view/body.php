<?php
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$productCount = count($products);
$productWord = $productCount === 1 ? 'produkt' : ($productCount >= 2 && $productCount <= 4 ? 'produkty' : 'produktů');
$filterLinks = $categoryMenu;
if ($manualCategoryMenu) {
    $filterLinks = [];
    $addFilters = static function (array $links, int $depth) use (&$addFilters, &$filterLinks): void {
        foreach ($links as $link) {
            $filterLinks[] = $link + ['depth' => $depth];
            $addFilters($link['children'], $depth + 1);
        }
    };
    $addFilters($categoryMenu, 0);
}
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
        <?php if ($canManageCatalog): ?><p class="catalog-admin-links"><a href="<?= $escape($categoryAdminUrl) ?>">Upravit kategorie</a><?php if ($newSubcategoryUrl !== ''): ?><a href="<?= $escape($newSubcategoryUrl) ?>">＋ Přidat podkategorii</a><?php endif; ?><a href="<?= $escape($menuAdminUrl) ?>">Upravit menu</a></p><?php endif; ?>
      </div><span class="result-count" id="result-count" aria-live="polite">Načteno <?= $productCount . ' ' . $productWord ?></span>
    </div>
    <div class="tools">
      <?php if ($categoryMenuRoot !== null && $filterLinks !== []): ?>
        <nav class="subcategory-filters" aria-label="Podkategorie <?= $escape($categoryMenuRoot['title']) ?>">
          <?php foreach ($filterLinks as $link): ?>
            <?php $isCurrent = isset($link['path']) && ($currentCategory['path'] ?? '') === $link['path']; ?>
            <a class="filter<?= $link['active'] ? ' active' : '' ?>" href="<?= $escape($link['href'] . (isset($link['path']) ? '#produkty' : '')) ?>" <?= $isCurrent ? 'aria-current="page"' : '' ?>><?php if (($link['depth'] ?? 0) > 0): ?><span aria-hidden="true">↳ </span><?php endif; ?><?= $escape($link['label']) ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <label class="sort-wrap">Řadit podle
        <select id="sort" aria-label="Řadit produkty">
          <option value="default" <?= $sortChoice === 'default' ? 'selected' : '' ?>>Nejnovější</option>
          <option value="price-asc" <?= $sortChoice === 'price-asc' ? 'selected' : '' ?>>Od nejlevnějšího</option>
          <option value="price-desc" <?= $sortChoice === 'price-desc' ? 'selected' : '' ?>>Od nejdražšího</option>
          <option value="name" <?= $sortChoice === 'name' ? 'selected' : '' ?>>Podle názvu</option>
        </select></label>
    </div>
    <section class="catalog" id="catalog" aria-label="Nabídka produktů">
      <?php if ($products !== []): ?>
        <?php foreach ($products as $product): require __DIR__ . '/product-card.php'; endforeach; ?>
      <?php endif; ?>
    </section>
    <div class="empty" id="empty" <?= $products !== [] ? 'hidden' : '' ?>><?= $searchTerm !== '' ? 'Pro zadaný výraz jsme nic nenašli.' : 'Zatím tu nejsou zveřejněné produkty. Nabídku připravujeme.' ?></div>
    <?php if ($nextUrl !== ''): ?><div class="load-more-wrap"><a class="load-more" data-load-more data-target="catalog" href="<?= $escape($nextUrl) ?>">Načíst další produkty</a></div><?php endif; ?>
    <section class="category-panel" id="kategorie" aria-labelledby="category-title">
      <div class="category-panel-copy">
        <h2 id="category-title">Kam dál?</h2>
        <p>Zvolte si směr a vyberte výbavu pro další cestu.</p>
      </div>
      <div class="category-list"><?php foreach ($primaryMenu as $link): ?><a href="<?= $escape($link['href'] . ($manualPrimaryMenu ? '' : '#produkty')) ?>"><?= $escape($link['label']) ?></a><?php endforeach; ?></div>
    </section>
  </main>
