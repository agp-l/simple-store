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
    <?php if ($canManageCatalog): ?><?php require __DIR__ . '/product-admin-nav.php'; ?><?php endif; ?>
    <?php if ($productDeleted): ?><p class="catalog-manage-notice" role="status">Produkt byl odstraněn. Nahrané soubory zůstaly zachované kvůli starším odkazům.</p><?php endif; ?>
    <nav class="breadcrumbs" aria-label="Drobečková navigace">
      <a href="<?= $escape($siteRoot . $language) ?>">Úvod</a>
      <?php foreach ($categoryTrail as $i => $crumb): ?>
        <span aria-hidden="true">/</span>
        <?php if ($i === count($categoryTrail) - 1): ?><span><?= $escape($crumb['title']) ?></span>
        <?php else: ?><a href="<?= $escape($siteRoot . $language . '/kategorie-produktu/' . $crumb['path']) ?>"><?= $escape($crumb['title']) ?></a><?php endif; ?>
      <?php endforeach; ?>
      <?php if ($categoryTrail === []): ?><span aria-hidden="true">/</span><span>Vybavení do přírody</span><?php endif; ?>
    </nav>
    <?php if ($homepageEditing): ?><?php require __DIR__ . '/homepage-editor.php'; ?><?php endif; ?>
    <div class="section-heading">
      <div>
        <h2 id="section-title"><?= $managingCatalog ? 'Správa produktů' : $escape($homepageSelectionActive ? $siteCopy['catalog_home_title'] : ($currentCategory['title'] ?? $siteCopy['catalog_title'])) ?></h2>
        <p id="section-description"><?= $managingCatalog ? 'Prohlížej zveřejněné i skryté produkty v jejich skutečných kategoriích. Otevři kartu a uprav produkt přímo na jeho stránce.' : $escape($siteCopy[$homepageSelectionActive ? 'catalog_home_intro' : ($currentCategory === null ? 'catalog_all_intro' : 'catalog_category_intro')]) ?></p>
        <?php if (!$canManageCatalog && $currentCategory === null && $homepageConfigured && !$managingCatalog && !$homepageEditing): ?><p class="catalog-public-links"><?php if ($homepageSelectionActive): ?><a href="<?= $escape($siteRoot . $language . '?all=1#produkty') ?>">Prohlédnout veškeré vybavení →</a><?php else: ?><a href="<?= $escape($siteRoot . $language . '#produkty') ?>">Zpět na výběr pro úvodní stránku →</a><?php endif; ?></p><?php endif; ?>
      </div><span class="result-count" id="result-count" aria-live="polite">Načteno <?= $productCount . ' ' . $productWord ?></span>
    </div>
    <?php if ($managingCatalog): ?>
      <nav class="catalog-manage-filter" aria-label="Stav produktů">
        <?php foreach (['all' => 'Všechny', 'draft' => 'Skryté a koncepty', 'published' => 'Zveřejněné'] as $state => $label): ?>
          <a href="<?= $escape($searchAction . '?' . http_build_query(array_filter(['manage' => '1', 'visibility' => $state === 'all' ? '' : $state, 'search' => $searchTerm], 'strlen'))) ?>" <?= $catalogVisibility === $state ? 'aria-current="page"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
      </nav>
      <?php if ($managementCategories !== []): ?><details class="catalog-manage-categories"><summary>Vybrat kategorii<?= $currentCategory === null ? '' : ': ' . $escape($currentCategory['title']) ?></summary>
        <nav aria-label="Kategorie spravovaných produktů">
          <a href="<?= $escape($siteRoot . $language . '?manage=1') ?>">Všechny kategorie</a>
          <?php foreach ($managementCategories as $category): ?>
            <a href="<?= $escape($siteRoot . $language . '/kategorie-produktu/' . $category['path'] . '?' . http_build_query(array_filter(['manage' => '1', 'visibility' => $catalogVisibility === 'all' ? '' : $catalogVisibility, 'search' => $searchTerm], 'strlen'))) ?>"><?= $escape($category['title']) ?></a>
          <?php endforeach; ?>
        </nav>
      </details><?php endif; ?>
    <?php endif; ?>
    <div class="tools">
      <?php if (!$managingCatalog && $categoryMenuRoot !== null && $filterLinks !== []): ?>
        <nav class="subcategory-filters" aria-label="Podkategorie <?= $escape($categoryMenuRoot['title']) ?>">
          <?php foreach ($filterLinks as $link): ?>
            <?php $isCurrent = isset($link['path']) && ($currentCategory['path'] ?? '') === $link['path']; ?>
            <?php $filterHref = $managingCatalog && isset($link['path'])
                ? $siteRoot . $language . '/kategorie-produktu/' . $link['path'] . '?' . http_build_query(array_filter(['manage' => '1', 'visibility' => $catalogVisibility === 'all' ? '' : $catalogVisibility, 'search' => $searchTerm], 'strlen')) . '#produkty'
                : $link['href'] . (isset($link['path']) ? '#produkty' : ''); ?>
            <a class="filter<?= $link['active'] ? ' active' : '' ?>" href="<?= $escape($filterHref) ?>" <?= $isCurrent ? 'aria-current="page"' : '' ?>><?php if (($link['depth'] ?? 0) > 0): ?><span aria-hidden="true">↳ </span><?php endif; ?><?= $escape($link['label']) ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <?php if (!$managingCatalog && !$homepageSelectionActive): ?><label class="sort-wrap">Řadit podle
        <select id="sort" aria-label="Řadit produkty">
          <option value="default" <?= $sortChoice === 'default' ? 'selected' : '' ?>>Nejnovější</option>
          <option value="price-asc" <?= $sortChoice === 'price-asc' ? 'selected' : '' ?>>Od nejlevnějšího</option>
          <option value="price-desc" <?= $sortChoice === 'price-desc' ? 'selected' : '' ?>>Od nejdražšího</option>
          <option value="name" <?= $sortChoice === 'name' ? 'selected' : '' ?>>Podle názvu</option>
        </select></label><?php endif; ?>
    </div>
    <section class="catalog" id="catalog" aria-label="Nabídka produktů">
      <?php if ($products !== []): ?>
        <?php foreach ($products as $product): require __DIR__ . '/product-card.php'; endforeach; ?>
      <?php endif; ?>
    </section>
    <div class="empty" id="empty" <?= $products !== [] ? 'hidden' : '' ?>><?= $searchTerm !== '' ? 'Pro zadaný výraz jsme nic nenašli.' : ($managingCatalog ? 'V tomto výběru zatím nejsou žádné produkty.' : ($homepageSelectionActive ? 'Vybrané produkty zatím nejsou zveřejněné. Prohlédněte si celé vybavení v kategoriích.' : 'Zatím tu nejsou zveřejněné produkty. Nabídku připravujeme.')) ?></div>
    <?php if ($nextUrl !== ''): ?><div class="load-more-wrap"><a class="load-more" data-load-more data-target="catalog" href="<?= $escape($nextUrl) ?>">Načíst další produkty</a></div><?php endif; ?>
    <?php if (!$managingCatalog): ?><section class="category-panel" id="kategorie" aria-labelledby="category-title">
      <div class="category-panel-copy">
        <h2 id="category-title">Kam dál?</h2>
        <p>Zvolte si směr a vyberte výbavu pro další cestu.</p>
      </div>
      <div class="category-list"><?php foreach ($primaryMenu as $link): ?><a href="<?= $escape($link['href'] . ($manualPrimaryMenu ? '' : '#produkty')) ?>"><?= $escape($link['label']) ?></a><?php endforeach; ?></div>
    </section><?php endif; ?>
  </main>
