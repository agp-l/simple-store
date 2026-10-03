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
    'product' => 'Detail produktu',
];
$createToken = (string) ($adminCreate['csrf'] ?? $editToken ?? '');
?>
    <section class="product-admin-nav" aria-label="Správa produktů">
      <div class="product-admin-nav-head">
        <div><span>Správa produktů</span><strong><?= $escape($titles[$productAdminMode] ?? 'Katalog') ?></strong></div>
        <?php if ($createToken !== ''): ?><form method="post" action="<?= $escape($basePath . 'admin.php') ?>">
          <input type="hidden" name="csrf" value="<?= $escape($createToken) ?>">
          <input type="hidden" name="language" value="<?= $escape($language) ?>">
          <button type="submit" name="action" value="create-product">＋ Nový produkt</button>
        </form><?php endif; ?>
      </div>
      <nav class="product-admin-nav-tabs" aria-label="Pohledy na produkty">
        <?php foreach ($views as $mode => [$label, $href]): ?>
          <a href="<?= $escape($href) ?>" <?= $productAdminMode === $mode ? 'aria-current="page"' : '' ?>><?= $escape($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="product-admin-nav-tools">
        <span>Nastavení:</span>
        <a href="<?= $escape($categoryLink) ?>">Kategorie</a>
        <?php if ($newSubcategoryUrl !== ''): ?><a href="<?= $escape($newSubcategoryUrl) ?>">＋ Podkategorie</a><?php endif; ?>
        <a href="<?= $escape($menuLink) ?>">Menu</a>
      </div>
    </section>
