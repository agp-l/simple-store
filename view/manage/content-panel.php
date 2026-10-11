<?php if ($canEditContent): ?>
<section class="content-admin-panel" aria-label="Správa <?= $page === 'post' ? 'článku' : 'stránky' ?>">
    <?php if ($canEditContent && !$editing): ?>
      <p class="inline-entry"><a href="<?= $escape($siteRoot . $route . '?edit=1') ?>"><?= \SimpleStore\Rendering\Icon::render('edit') ?> Upravit <?= $kind ?> přímo na stránce</a></p>
    <?php endif; ?>
    <?php if ($editing): ?>
      <div class="inline-toolbar" id="content-toolbar">
        <div><strong><?= \SimpleStore\Rendering\Icon::render($page === 'post' ? 'book' : 'document') ?> <?= $page === 'post' ? 'Článek' : 'Stránka' ?> <span class="product-edit-state<?= !empty($content['published']) ? ' is-public' : '' ?>"><?= !empty($content['published']) ? 'Zveřejněno' : 'Skrytý koncept' ?></span></strong></div>
        <span class="inline-status" id="content-editor-status" role="status" aria-live="polite">Revize <?= (int) $content['revision_number'] ?></span>
        <button type="button" class="inline-small" data-content-action="publish" data-value="<?= $content['published'] ? '0' : '1' ?>"><?= \SimpleStore\Rendering\Icon::render('eye') ?> <?= $content['published'] ? 'Skrýt' : 'Publikovat' ?></button>
        <button type="button" class="inline-small" data-content-action="media-library"><?= \SimpleStore\Rendering\Icon::render('image') ?> Fotografie</button>

        <?php if ($content['published']): ?><a href="<?= $escape($siteRoot . $route) ?>"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit bez úprav</a><?php endif; ?>
        <a href="<?= $escape($page === 'post' ? $siteRoot . $language . '/blog?manage=1' : $siteRoot . 'admin.php?section=contents') ?>"><?= $page === 'post' ? 'Správa blogu' : 'Všechny stránky' ?></a>
      </div>
      <div class="inline-settings" aria-label="Nastavení dokumentu">
        <span>Adresa: <span class="inline-slug"<?= $editable('slug', $content['slug']) ?>><?= $escape($content['slug']) ?></span></span>
        <?php if ($page === 'page'): ?>
          <label>Horní odkazy <select data-content-select="visible_in_menu"><option value="0" <?= !$content['visible_in_menu'] ? 'selected' : '' ?>>Nezobrazovat</option><option value="1" <?= $content['visible_in_menu'] ? 'selected' : '' ?>>Zobrazovat</option></select></label>
          <label>Pořadí v menu <input type="number" min="0" max="65535" value="<?= (int) $content['menu_order'] ?>" data-content-input="menu_order"></label>
        <?php endif; ?>
      </div>
      <details class="inline-delete-product"><summary><?= \SimpleStore\Rendering\Icon::render('delete') ?> Odstranit <?= $page === 'post' ? 'článek' : 'stránku' ?></summary>
        <p>Smazání odstraní <?= $page === 'post' ? 'článek' : 'stránku' ?> a všechny revize v tomto jazyce. Ostatní překlady a nahrané fotografie zůstanou zachované.</p>
        <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>">
          <input type="hidden" name="action" value="delete-content"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
          <input type="hidden" name="key" value="<?= $escape($content['document_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
          <input type="hidden" name="type" value="<?= $escape($page) ?>"><input type="hidden" name="revision" value="<?= (int) $content['revision_number'] ?>">
          <label><input type="checkbox" name="confirm" value="1" required> Rozumím, že tento obsah a jeho revize už nepůjdou obnovit.</label>
          <button type="submit" class="inline-small"><?= \SimpleStore\Rendering\Icon::render('delete') ?> Smazat <?= $page === 'post' ? 'článek' : 'stránku' ?></button>
        </form>
      </details>
    <?php endif; ?>
</section>
<?php endif; ?>
