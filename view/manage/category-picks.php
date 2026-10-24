<?php if (($categoryTipsScope ?? '') !== '' && $canManageCatalog): ?>
<details class="homepage-editor">
  <summary>Náš tip — pořadí doporučení v této kategorii</summary>
  <p>Tipy přidáš přímo v detailu produktu. Pořadí měň přetažením; ukládá se automaticky. Při náhodném řazení se tipy zobrazí první. Při řazení podle ceny, hmotnosti, názvu nebo novosti rozhoduje zvolený způsob řazení.</p>
  <ol class="homepage-picks" data-product-selection data-scope="<?= $escape($categoryTipsScope) ?>" data-language="<?= $escape($language) ?>" data-csrf="<?= $escape($adminCsrf) ?>" data-endpoint="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>">
    <?php foreach ($categoryTipsSelected as $selected): ?>
    <li data-key="<?= $escape($selected['product_key']) ?>"><button type="button" class="selection-drag" aria-label="Přesunout <?= $escape($selected['name']) ?>" title="Přetáhni nebo použij Alt + šipku nahoru/dolů">⠿</button><div class="homepage-pick-info"><strong><?= $escape($selected['name']) ?></strong><?php if (empty($selected['published'])): ?><small>Skrytý nebo odstraněný produkt</small><?php endif; ?></div><?php if ($selected['slug'] !== ''): ?><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . $language . '/produkt/' . $selected['slug'] . '?edit=1') ?>">Upravit</a><?php endif; ?>
      <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>">
        <input type="hidden" name="csrf" value="<?= $escape($adminCsrf) ?>"><input type="hidden" name="action" value="product-selection">
        <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="scope" value="<?= $escape($categoryTipsScope) ?>">
        <input type="hidden" name="key" value="<?= $escape($selected['product_key']) ?>"><input type="hidden" name="return_category" value="1">
        <button name="operation" value="remove">Odebrat</button>
      </form>
    </li>
    <?php endforeach; ?>
  </ol>
  <p class="selection-order-status" role="status" aria-live="polite"></p>
</details>
<?php endif; ?>
