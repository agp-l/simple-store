<?php if ($canEditContent): ?>
<section class="content-admin-panel" aria-label="Správa <?= $page === 'post' ? 'článku' : 'stránky' ?>">
    <?php if ($canEditContent && !$editing): ?>
      <p class="inline-entry"><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . $route . '?edit=1') ?>"><?= \SimpleStore\Rendering\Icon::render('edit') ?> Upravit <?= $kind ?> přímo na stránce</a></p>
    <?php endif; ?>
    <?php if ($editing): ?>
      <div class="inline-toolbar" id="content-toolbar">
        <div><strong><?= \SimpleStore\Rendering\Icon::render($page === 'post' ? 'book' : 'document') ?> <?= $page === 'post' ? 'Článek' : 'Stránka' ?> <span class="product-edit-state<?= !empty($content['published']) ? ' is-public' : '' ?>"><?= !empty($content['published']) ? 'Zveřejněno' : 'Skrytý koncept' ?></span></strong></div>
        <span class="inline-status" id="content-editor-status" role="status" aria-live="polite">Revize <?= (int) $content['revision_number'] ?></span>
        <button type="button" class="inline-small" data-content-action="publish" data-value="<?= $content['published'] ? '0' : '1' ?>"><?= \SimpleStore\Rendering\Icon::render('eye') ?> <?= $content['published'] ? 'Skrýt' : 'Publikovat' ?></button>
        <button type="button" class="inline-small" data-content-action="media-library"><?= \SimpleStore\Rendering\Icon::render('image') ?> Fotografie</button>
        <?php $contentTransferUrl = $siteRoot . 'admin/?' . http_build_query(['section' => 'content-import', 'key' => $content['document_key'], 'language' => $language], '', '&', PHP_QUERY_RFC3986); ?>
        <a class="inline-small" data-content-transfer href="<?= \SimpleStore\Navigation\PanelUrl::escape($contentTransferUrl . '&download=1') ?>"><?= \SimpleStore\Rendering\Icon::render('download') ?> Export JSON</a>
        <a class="inline-small" data-content-transfer href="<?= \SimpleStore\Navigation\PanelUrl::escape($contentTransferUrl) ?>"><?= \SimpleStore\Rendering\Icon::render('upload') ?> Import JSON</a>

        <?php if ($content['published']): ?><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . $route) ?>"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit bez úprav</a><?php endif; ?>
        <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($page === 'post' ? $siteRoot . 'admin/?section=contents&type=post' : $siteRoot . 'admin/?section=contents') ?>"><?= $page === 'post' ? 'Správa blogu' : 'Všechny stránky' ?></a>
      </div>
      <div class="inline-settings" aria-label="Nastavení dokumentu">
        <span>Adresa: <span class="inline-slug"<?= $editable('slug', $content['slug']) ?>><?= $escape($content['slug']) ?></span></span>
        <?php if ($page === 'page'): ?>
          <label>Horní odkazy <select data-content-select="visible_in_menu"><option value="0" <?= !$content['visible_in_menu'] ? 'selected' : '' ?>>Nezobrazovat</option><option value="1" <?= $content['visible_in_menu'] ? 'selected' : '' ?>>Zobrazovat</option></select></label>
          <label>Pořadí v menu <input type="number" min="0" max="65535" value="<?= (int) $content['menu_order'] ?>" data-content-input="menu_order"></label>
        <?php endif; ?>
      </div>
      <?php if ($page === 'post'):
        $articleCover = \SimpleStore\Content\ContentBody::cover($content['body']);
        $articleCoverSetting = \SimpleStore\Content\ContentBody::coverSetting($content['body']);
        $articleCoverUrl = \SimpleStore\Media\MediaPath::variant($articleCover, 'card');
        if (str_starts_with($articleCoverUrl, 'images/')) $articleCoverUrl = $basePath . $articleCoverUrl;
      ?>
      <section class="content-cover-settings" aria-label="Náhled článku">
        <?php if ($articleCover !== ''): ?><img src="<?= $escape($articleCoverUrl) ?>" alt="Náhledový obrázek článku"><?php else: ?><span class="content-cover-empty"><?= \SimpleStore\Rendering\Icon::render('image') ?></span><?php endif; ?>
        <div><strong>Náhledový obrázek</strong><p>Zobrazí se na kartě článku v blogu a v doporučeních pod produkty. Obsah článku se tím nemění.</p>
          <p><?= $articleCoverSetting === null ? 'Automaticky: první fotografie v článku.' : ($articleCoverSetting === '' ? 'Karta bez obrázku.' : 'Vlastní náhled vybraný z knihovny.') ?></p>
          <div class="media-actions"><button type="button" class="inline-small" data-content-action="cover-image" data-value="<?= $escape($articleCover) ?>">Vybrat nebo nahrát obrázek</button>
          <button type="button" class="inline-small" data-content-action="cover-auto">Použít první fotografii</button><button type="button" class="inline-small" data-content-action="cover-none">Bez obrázku</button></div>
        </div>
      </section>
      <?php endif; ?>
      <?php if ($page === 'post'): $picked = in_array($content['document_key'], $catalogPostKeys, true); ?>
      <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>" class="inline-settings">
        <input type="hidden" name="action" value="catalog-post"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="key" value="<?= $escape($content['document_key']) ?>"><input type="hidden" name="expected" value="<?= $escape(json_encode($catalogPostKeys, JSON_THROW_ON_ERROR)) ?>">
        <button class="inline-small" name="operation" value="<?= $picked ? 'remove' : 'add' ?>" <?= !$picked && !$content['published'] ? 'disabled' : '' ?>><?= \SimpleStore\Rendering\Icon::render('book') ?> <?= $picked ? 'Odebrat z Kam dál?' : 'Zobrazit v Kam dál?' ?></button>
        <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . $language . '#kategorie') ?>">Výběr článků pod produkty →</a>
      </form>
      <?php endif; ?>
      <details class="inline-delete-product"><summary><?= \SimpleStore\Rendering\Icon::render('delete') ?> Odstranit <?= $page === 'post' ? 'článek' : 'stránku' ?></summary>
        <p>Smazání odstraní <?= $page === 'post' ? 'článek' : 'stránku' ?> a všechny revize v tomto jazyce. Ostatní překlady a nahrané fotografie zůstanou zachované.</p>
        <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>">
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
