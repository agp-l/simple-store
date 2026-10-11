<?php if (($categoryTipsScope ?? '') !== '' && $canManageCatalog): ?>
<details class="homepage-editor">
  <summary>Náš tip — pořadí doporučení v této kategorii</summary>
  <p>Tipy přidáš přímo v detailu produktu. Pořadí měň přetažením; ukládá se automaticky. Zbytek nabídky se doplní dalšími produkty.</p>
  <ol class="homepage-picks" data-product-selection data-scope="<?= $escape($categoryTipsScope) ?>" data-language="<?= $escape($language) ?>" data-csrf="<?= $escape($adminCsrf) ?>" data-endpoint="<?= $escape($siteRoot . 'admin.php') ?>">
    <?php foreach ($categoryTipsSelected as $selected): ?>
    <li data-key="<?= $escape($selected['product_key']) ?>"><button type="button" class="selection-drag" aria-label="Přesunout <?= $escape($selected['name']) ?>" title="Přetáhni nebo použij Alt + šipku nahoru/dolů">⠿</button><div class="homepage-pick-info"><strong><?= $escape($selected['name']) ?></strong><?php if (empty($selected['published'])): ?><small>Skrytý nebo odstraněný produkt</small><?php endif; ?></div><?php if ($selected['slug'] !== ''): ?><a href="<?= $escape($siteRoot . $language . '/produkt/' . $selected['slug'] . '?edit=1') ?>">Upravit</a><?php endif; ?></li>
    <?php endforeach; ?>
  </ol>
  <p class="selection-order-status" role="status" aria-live="polite"></p>
</details>
<?php endif; ?>
