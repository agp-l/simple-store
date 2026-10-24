<?php if ($catalogPosts !== []): ?>
<div class="catalog-stories">
  <div class="catalog-stories-heading"><h2>Inspirace na cesty</h2><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . $language . '/blog') ?>">Všechny články <?= \SimpleStore\Rendering\Icon::render('arrow-right') ?></a></div>
  <div class="catalog-story-grid">
    <?php foreach ($catalogPosts as $story):
      $storyCover = '';
      foreach (\SimpleStore\Content\ContentBody::decode($story['body']) as $block) {
          if ($block['type'] === 'image') { $storyCover = \SimpleStore\Media\MediaPath::variant($block['body'], 'card'); break; }
      }
      if (str_starts_with($storyCover, 'images/')) $storyCover = $basePath . $storyCover;
    ?>
    <article class="catalog-story">
      <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . $language . '/blog/' . rawurlencode($story['slug'])) ?>">
        <?php if ($storyCover !== ''): ?><img src="<?= $escape($storyCover) ?>" alt="" loading="lazy" decoding="async"><?php else: ?><span class="catalog-story-placeholder" aria-hidden="true"><?= \SimpleStore\Rendering\Icon::render('book') ?></span><?php endif; ?>
        <div class="catalog-story-copy"><h3><?= $escape($story['title']) ?></h3><?php if (($story['summary'] ?? '') !== ''): ?><p><?= $escape($story['summary']) ?></p><?php endif; ?><span class="catalog-story-read">Číst příběh <?= \SimpleStore\Rendering\Icon::render('arrow-right') ?></span></div>
      </a>
    </article>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php if ($canManageCatalog): ?>
<details class="catalog-story-settings"><summary><?= \SimpleStore\Rendering\Icon::render('edit') ?> Vybrat články do tohoto boxu</summary>
  <p>Nejvýše tři články. Přidávej je tlačítkem „Zobrazit v Kam dál?“ v editaci článku. Dokud výběr neupravíš, zobrazují se tři nejnovější zveřejněné články.</p>
  <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . $language . '/blog?manage=1') ?>">Otevřít správu blogu →</a>
  <?php foreach ($catalogPostKeys as $index => $key): $pickedTitle = 'Skrytý nebo odstraněný článek'; foreach ($catalogPosts as $story) if ($story['document_key'] === $key) $pickedTitle = $story['title']; ?>
  <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>">
    <input type="hidden" name="action" value="catalog-post"><input type="hidden" name="csrf" value="<?= $escape($adminCsrf) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="key" value="<?= $escape($key) ?>"><input type="hidden" name="expected" value="<?= $escape(json_encode($catalogPostKeys, JSON_THROW_ON_ERROR)) ?>">
    <strong><?= $escape($pickedTitle) ?></strong>
    <button class="inline-small" name="operation" value="up" aria-label="Posunout výš" <?= $index === 0 ? 'disabled' : '' ?>>↑</button><button class="inline-small" name="operation" value="down" aria-label="Posunout níž" <?= $index === count($catalogPostKeys) - 1 ? 'disabled' : '' ?>>↓</button><button class="inline-small" name="operation" value="remove">Odebrat</button>
  </form>
  <?php endforeach; ?>
</details>
<?php endif; ?>
