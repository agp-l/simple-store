<?php $isBlog = ($filterType ?? 'page') === 'post'; $contentType = $isBlog ? 'post' : 'page'; ?>
<div class="content-workspace">
      <div class="panel-intro">
        <div><p class="panel-eyebrow">Obsah webu</p><h1><?= $isBlog ? 'Články na blogu' : 'Stránky webu' ?></h1>
          <p><?= $isBlog ? 'Příběhy, návody a inspirace. Uprav obsah, vyber náhled a publikuj, až bude vše připravené.' : 'Přehled informačních stránek a rozpracovaných konceptů. Texty upravíš přímo v náhledu stránky.' ?></p></div>
        <div class="panel-quick">
          <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=content-import&type=' . $contentType) ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('upload') ?> Import JSON</a>
          <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="create-content"><input type="hidden" name="type" value="<?= $contentType ?>"><input type="hidden" name="language" value="<?= $escape($site['default_language']) ?>"><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('plus') ?> <?= $isBlog ? 'Nový článek' : 'Nová stránka' ?></button></form>
          <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($basePath . $site['default_language']) ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('external') ?> Na web</a>
          <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($basePath . $site['default_language'] . '/blog') ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('external') ?> Blog</a>
        </div>
      </div>
      <nav class="content-type-tabs" aria-label="Typ obsahu"><a <?= !$isBlog ? 'aria-current="page"' : '' ?> href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=contents&type=page') ?>"><?= \SimpleStore\Rendering\Icon::render('document') ?> Stránky</a><a <?= $isBlog ? 'aria-current="page"' : '' ?> href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=contents&type=post') ?>"><?= \SimpleStore\Rendering\Icon::render('book') ?> Články</a><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=media') ?>"><?= \SimpleStore\Rendering\Icon::render('image') ?> Knihovna médií</a></nav>
      <section class="panel-panel panel-list" aria-labelledby="panel-list-title">
        <?php if (($_GET['deleted'] ?? '') === '1'): ?><p role="status">Obsah byl odstraněn.</p><?php endif; ?>
        <h2 id="panel-list-title"><?= $isBlog ? 'Najít článek' : 'Najít stránku' ?></h2>
        <form class="panel-form content-filters" method="get" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>">
          <input type="hidden" name="section" value="contents"><input type="hidden" name="type" value="<?= $contentType ?>">
          <label>Hledat podle názvu nebo adresy
            <input name="q" type="search" maxlength="200" value="<?= $escape($filterSearch) ?>" placeholder="Název nebo část adresy">
          </label>
          <div class="panel-fields-two">
            <label>Stav
              <select name="status">
                <option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>Vše</option>
                <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Koncepty</option>
                <option value="published" <?= $filterStatus === 'published' ? 'selected' : '' ?>>Publikováno</option>
              </select>
            </label>
          </div>
          <?php if (count($site['languages']) > 1): ?>
            <label>Jazyk
              <select name="language">
                <option value="" <?= $filterLanguage === '' ? 'selected' : '' ?>>Všechny jazyky</option>
                <?php foreach ($site['languages'] as $code): ?><option value="<?= $escape($code) ?>" <?= $filterLanguage === $code ? 'selected' : '' ?>><?= $escape($code) ?></option><?php endforeach; ?>
              </select>
            </label>
          <?php endif; ?>
          <div class="panel-form-actions"><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit</button>
            <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=contents&type=' . $contentType) ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('filter') ?> Zrušit filtry</a></div>
        </form>
        <h2>Výsledky <span><?= count($documents) ?> na stránce</span></h2>
        <?php if ($documents === []): ?><p class="panel-empty">Nic neodpovídá filtrům. Zkus jiný název nebo vytvoř nový obsah.</p><?php endif; ?>
        <?php foreach ($documents as $document): ?>
          <?php $link = $basePath . $document['language'] . ($isBlog ? '/blog' : '') . '/' . rawurlencode($document['slug']) . '?edit=1'; ?>
          <div class="panel-document-row">
            <?php $listCover = \SimpleStore\Media\MediaPath::variant(\SimpleStore\Content\ContentBody::cover($document['body'] ?? ''), 'thumb'); ?>
            <a class="content-row-preview" href="<?= \SimpleStore\Navigation\PanelUrl::escape($link) ?>" aria-label="Upravit <?= $escape($document['title']) ?>"><?php if ($listCover !== ''): ?><img src="<?= $escape(str_starts_with($listCover, 'images/') ? $basePath . $listCover : $listCover) ?>" alt="" loading="lazy"><?php else: ?><?= \SimpleStore\Rendering\Icon::render($isBlog ? 'book' : 'document') ?><?php endif; ?></a>
            <div class="panel-document">
              <strong><?= $escape($document['title']) ?></strong>
              <span><?= $escape($document['language']) ?> · /<?= $escape($document['slug']) ?></span>
              <small><span class="content-state <?= $document['published'] ? 'is-published' : 'is-draft' ?>"><?= $document['published'] ? 'Publikováno' : 'Koncept' ?></span><?= !$isBlog && $document['published'] && empty($document['visible_in_menu']) ? ' · mimo menu' : '' ?> · revize <?= (int) $document['revision_number'] ?></small>
              <?php if (($document['summary'] ?? '') !== ''): ?><p class="content-row-summary"><?= $escape($document['summary']) ?></p><?php endif; ?>
            </div>
            <div class="panel-document-actions">
              <a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?' . http_build_query(['section' => 'media', 'type' => $contentType, 'key' => $document['document_key'], 'language' => $document['language']], '', '&', PHP_QUERY_RFC3986)) ?>"><?= \SimpleStore\Rendering\Icon::render('image') ?> Fotografie</a>
              <a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=content-import&key=' . $document['document_key'] . '&language=' . $document['language'] . '&download=1') ?>" aria-label="Export JSON: <?= $escape($document['title']) ?>"><?= \SimpleStore\Rendering\Icon::render('download') ?> JSON</a>
              <a class="panel-icon-link panel-button" href="<?= \SimpleStore\Navigation\PanelUrl::escape($link) ?>" aria-label="Upravit <?= $escape($document['title']) ?>"><?= \SimpleStore\Rendering\Icon::render('edit') ?> Upravit</a>
              <details class="panel-document-delete"><summary aria-label="Smazat <?= $escape($document['title']) ?>" class="panel-action panel-action--danger"><?= \SimpleStore\Rendering\Icon::render('delete') ?> Smazat</summary>
                <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
                  <input type="hidden" name="action" value="delete-content"><input type="hidden" name="return_admin" value="1">
                  <input type="hidden" name="type" value="<?= $contentType ?>">
                  <input type="hidden" name="key" value="<?= $escape($document['document_key']) ?>">
                  <input type="hidden" name="language" value="<?= $escape($document['language']) ?>">
                  <input type="hidden" name="revision" value="<?= (int) $document['revision_number'] ?>">
                  <p>Odstranit tento obsah a všechny jeho revize v tomto jazyce? Překlady a fotografie zůstanou zachované.</p>
                  <label class="panel-check"><input type="checkbox" name="confirm" value="1" required> Potvrzuji smazání obsahu</label>
                  <button type="submit" class="panel-action panel-action--danger panel-button"><?= \SimpleStore\Rendering\Icon::render('delete') ?> Smazat obsah</button>
                </form>
              </details>
            </div>
          </div>
          <?php $missingLanguages = array_values(array_diff($site['languages'], $translations[$document['document_key']] ?? [])); ?>
          <?php if ($missingLanguages !== []): ?>
            <form class="panel-translate" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>">
              <input type="hidden" name="action" value="create-translation"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
              <input type="hidden" name="key" value="<?= $escape($document['document_key']) ?>"><input type="hidden" name="source_language" value="<?= $escape($document['language']) ?>">
              <label>Nový překlad <select name="language"><?php foreach ($missingLanguages as $code): ?><option value="<?= $escape($code) ?>"><?= $escape($code) ?></option><?php endforeach; ?></select></label>
              <button type="submit" class="panel-action"><?= \SimpleStore\Rendering\Icon::render('plus') ?> Vytvořit</button>
            </form>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($previousUrl !== '' || $nextUrl !== ''): ?>
          <nav class="panel-quick" aria-label="Další obsah">
            <?php if ($previousUrl !== ''): ?><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($previousUrl) ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('arrow-left') ?> Předchozí</a><?php endif; ?>
            <?php if ($nextUrl !== ''): ?><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($nextUrl) ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('arrow-right') ?> Další</a><?php endif; ?>
          </nav>
        <?php endif; ?>
      </section>

</div>
