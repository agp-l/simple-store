<?php
// Only actions tied to the current list belong beside the catalog.
$catalogPath = $basePath . $language . ($currentCategory === null ? '' : '/kategorie-produktu/' . $currentCategory['path']);
?>
<?php if ($homepageEditing): ?>
  <nav class="catalog-context-actions" aria-label="Náhled úvodní stránky">
    <a href="<?= $escape($basePath . $language . '#produkty') ?>"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit výsledek na úvodní stránce</a>
  </nav>
<?php elseif ($managingCatalog): ?>
  <nav class="catalog-context-actions" aria-label="Náhled katalogu">
    <a href="<?= $escape($catalogPath . ($currentCategory === null ? '?all=1' : '') . '#produkty') ?>"><?= \SimpleStore\Rendering\Icon::render('eye') ?> <?= $currentCategory === null ? 'Zobrazit zveřejněné produkty' : 'Zobrazit zveřejněné produkty této kategorie' ?></a>
  </nav>
<?php elseif ($currentCategory !== null): ?>
  <nav class="catalog-context-actions" aria-label="Správa aktuální kategorie">
    <a href="<?= $escape($catalogPath . '?manage=1#produkty') ?>"><?= \SimpleStore\Rendering\Icon::render('edit') ?> Spravovat produkty této kategorie</a>
  </nav>
<?php endif; ?>
