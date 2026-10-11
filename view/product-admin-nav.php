<?php
declare(strict_types=1);

// Shared navigation for the catalog and the administrator's product detail.
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$catalogRoot = $basePath . $language;
$adminRoot = $basePath . 'admin.php?';
$categoryLink = $categoryAdminUrl !== '' ? $categoryAdminUrl : $adminRoot . http_build_query([
    'section' => 'categories', 'language' => $language,
]);
$menuLink = $menuAdminUrl !== '' ? $menuAdminUrl : $adminRoot . http_build_query([
    'section' => 'menus', 'language' => $language, 'slot' => 'primary',
]);
$views = [
    'home' => ['Úvodní stránka', $catalogRoot . '#produkty'],
    'manage' => ['Produkty a koncepty', $catalogRoot . '?manage=1#produkty'],
    'selection' => ['Výběr na úvodní stránku', $catalogRoot . '?homepage_edit=1#homepage-editor'],
    'catalog' => ['Veřejný katalog', $catalogRoot . '?all=1#produkty'],
];
$titles = [
    'home' => 'Úvodní stránka', 'manage' => 'Produkty a koncepty',
    'selection' => 'Výběr na úvodní stránku', 'catalog' => 'Veřejný katalog',
    'product' => 'Produkt',
];
?>
    <section class="product-admin-nav" aria-label="Správa produktů">
      <?php if ($productAdminMode !== 'product'): ?><div class="product-admin-nav-head">
        <div><span><?= \SimpleStore\Rendering\Icon::render('package') ?> Správa produktů</span><strong><?= $escape($titles[$productAdminMode] ?? 'Katalog') ?></strong></div>
      </div>
      <?php endif; ?>
      <nav class="product-admin-nav-tabs" aria-label="Pohledy na produkty">
        <?php foreach ($views as $mode => [$label, $href]): ?>
          <a href="<?= $escape($href) ?>" <?= $productAdminMode === $mode ? 'aria-current="page"' : '' ?>><?= $escape($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="product-admin-nav-tools">
        <a href="<?= $escape($adminRoot . http_build_query(['section' => 'product-import', 'language' => $language])) ?>"><?= \SimpleStore\Rendering\Icon::render('upload') ?> Import a export</a>
        <?php if ($productAdminMode === 'product' && isset($product['product_key'])): ?><a href="<?= $escape($adminRoot . http_build_query(['section' => 'product-import', 'language' => $language, 'key' => $product['product_key']])) ?>"><?= \SimpleStore\Rendering\Icon::render('document') ?> JSON pro AI</a><?php endif; ?>
        <span>Nastavení:</span>
        <a href="<?= $escape($categoryLink) ?>"><?= \SimpleStore\Rendering\Icon::render('folder') ?> Kategorie</a>
        <?php if ($newSubcategoryUrl !== ''): ?><a href="<?= $escape($newSubcategoryUrl) ?>"><?= \SimpleStore\Rendering\Icon::render('plus') ?> Podkategorie</a><?php endif; ?>
        <a href="<?= $escape($menuLink) ?>"><?= \SimpleStore\Rendering\Icon::render('list') ?> Menu</a>
      </div>
    </section>
