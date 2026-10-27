<?php
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$mediaLink = static fn (array $query): string => \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?' . http_build_query(['section' => 'media'] + $query, '', '&', PHP_QUERY_RFC3986));
$mediaOptions ??= \SimpleStore\Media\MediaBrowse::options(['folder' => $mediaFolder, 'q' => $mediaSearch]);
$folderView = $mediaOptions['view'] === 'folders';
$activeDirectory = $mediaListing['active'] ?? null;
$activeOwner = $activeDirectory['owner'] ?? null;
$browseQuery = ['folder' => $mediaFolder, 'owner' => $mediaOptions['owner'], 'view' => $mediaOptions['view'], 'sort' => $mediaOptions['sort'], 'q' => $mediaSearch, 'parent_q' => $mediaOptions['parent_q']];
$mediaPageLink = static fn (array $changes): string => $mediaLink(array_replace($browseQuery, $changes));
$ownerUrl = $activeOwner === null ? '' : $basePath . $activeOwner['language'] . ($activeOwner['type'] === 'product' ? '/produkt' : ($activeOwner['type'] === 'post' ? '/blog' : '')) . '/' . rawurlencode($activeOwner['slug']) . '?edit=1';
$mediaTitle = \SimpleStore\Media\MediaCatalog::GROUPS[$mediaFolder] ?? $mediaFolders[$mediaFolder] ?? 'Knihovna';
$mediaTitle = $activeDirectory['title'] ?? $mediaTitle;
$targetFolder = isset($mediaFolders[$mediaFolder]) ? $mediaFolder : '';
$folderOptions = static function (string $selected = '') use ($mediaFolders, $escape): void { ?>
  <option value="">Bez vlastní složky</option><?php foreach ($mediaFolders as $id => $label): ?><option value="<?= $escape($id) ?>" <?= $id === $selected ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach;
};
?>
<div class="panel-intro"><div><p class="panel-eyebrow">Obsah & fotografie</p><h1>Knihovna médií</h1><a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl.'?section=product-removal') ?>">Úklid odstraněných produktů</a><p>Jedno místo pro fotografie produktů, stránek i blogu. Nahraj, uspořádej a použij obrázek tam, kde ho potřebuješ.</p></div>
  <a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=contents') ?>"><?= \SimpleStore\Rendering\Icon::render('document') ?> Stránky a články</a>
</div>
<?php if ($mediaError !== ''): ?><p class="panel-error" role="alert"><?= $escape($mediaError) ?></p><?php endif; ?>
<?php if ($mediaNotice !== ''): ?><p class="library-notice" role="status"><?= $escape($mediaNotice) ?></p><?php endif; ?>
<div class="library-workspace">
  <details class="library-folder-panel" data-library-folder-panel open><summary>Složky a organizace knihovny <span>⌄</span></summary><aside class="library-sidebar" aria-label="Složky médií"><h2>Knihovna</h2>
    <nav><?php foreach (\SimpleStore\Media\MediaCatalog::GROUPS as $id => $label): ?><a href="<?= $mediaLink(['folder' => $id]) ?>" <?= $mediaFolder === $id ? 'aria-current="page"' : '' ?>><?= \SimpleStore\Rendering\Icon::render($id === 'all' ? 'image' : 'folder') ?><span><?= $escape($label) ?></span><?php if (isset($mediaListing['counts'][$id])): ?><small class="library-nav-count"><?= (int) $mediaListing['counts'][$id] ?></small><?php endif; ?></a><?php endforeach; ?></nav>
    <h2>Moje složky <span><?= count($mediaFolders) ?></span></h2>
    <nav><?php foreach ($mediaFolders as $id => $label): ?><a href="<?= $mediaLink(['folder' => $id]) ?>" <?= $mediaFolder === $id ? 'aria-current="page"' : '' ?>><?= \SimpleStore\Rendering\Icon::render('folder') ?><span><?= $escape($label) ?></span></a><?php endforeach; ?></nav>
    <?php if ($mediaFolders === []): ?><p>Vytvoř si složky například pro sezónu, dodavatele nebo připravovaný článek.</p><?php endif; ?>
    <details class="library-tool"><summary>+ Nová složka</summary><form method="post" action="<?= $mediaLink([]) ?>" class="panel-form">
      <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="media-library-folder">
      <label>Název <input name="name" maxlength="120" required placeholder="Například Podzimní výpravy"></label>
      <label>Umístit do <select name="folder"><option value="">Hlavní úroveň</option><?php foreach ($mediaFolders as $id => $label): ?><option value="<?= $escape($id) ?>"><?= $escape($label) ?></option><?php endforeach; ?></select></label>
      <button class="panel-action panel-button">Vytvořit složku</button></form></details>
    <p class="library-hint">Složky slouží k organizaci. Zařazení ani přejmenování nemění adresy obrázků nebo jejich použití na webu.</p>
  </aside></details>
  <div class="library-main">
    <section class="panel-panel library-results" aria-labelledby="library-title">
      <nav class="library-breadcrumbs" aria-label="Umístění v knihovně"><a href="<?= $mediaLink(['folder' => 'all']) ?>">Knihovna</a>
        <?php if ($mediaFolder !== 'all'): ?><span aria-hidden="true">/</span><?php if ($mediaOptions['owner'] !== ''): ?><a href="<?= $mediaLink(['folder' => $mediaFolder, 'view' => 'folders', 'sort' => $mediaOptions['sort'], 'q' => $mediaOptions['parent_q']]) ?>"><?= $escape(\SimpleStore\Media\MediaCatalog::GROUPS[$mediaFolder]) ?></a><span aria-hidden="true">/</span><?php endif; ?><span aria-current="page"><?= $escape($mediaTitle) ?></span><?php endif; ?>
      </nav>
      <div class="library-heading"><div><p class="panel-eyebrow"><?= $folderView ? 'Přehled složek' : 'Přehled fotografií' ?></p><h2 id="library-title"><?= $escape($mediaTitle) ?></h2><p><?= $folderView ? 'Složky:' : 'Obrázky:' ?> <?= (int) $mediaListing['total'] ?><?= $mediaSearch !== '' ? ' odpovídá hledání' : '' ?><?= $folderView ? ' · otevři složku a zobraz fotografie' : ' · miniatury se nezapočítávají zvlášť' ?></p></div>
        <div class="library-heading-actions"><a class="panel-icon-link" href="#library-upload" data-library-expand="library-upload"><?= \SimpleStore\Rendering\Icon::render('upload') ?> Nahrát</a><a class="panel-icon-link" href="#library-backup" data-library-expand="library-backup"><?= \SimpleStore\Rendering\Icon::render('download') ?> Zálohy</a></div>
      </div>
      <?php if ($activeDirectory !== null): ?><div class="library-owner-header">
        <img src="<?= $escape($basePath . $activeDirectory['thumb']) ?>" alt="">
        <div><p><?= $activeOwner === null ? 'Původní obsah už nemá aktuální verzi. Nahrané fotografie jsou stále dostupné.' : 'Fotografie nahrané k tomuto ' . ($activeOwner['type'] === 'product' ? 'produktu' : 'dokumentu') . '. Další sdílené snímky můžeš vybrat z celé knihovny.' ?></p>
          <div class="media-actions"><a class="panel-icon-link" href="<?= $mediaLink(['folder' => $mediaFolder, 'view' => 'folders', 'sort' => $mediaOptions['sort'], 'q' => $mediaOptions['parent_q']]) ?>">← Zpět na složky</a>
            <?php if ($activeOwner !== null): ?><a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($ownerUrl) ?>"><?= \SimpleStore\Rendering\Icon::render('edit') ?> Upravit <?= $activeOwner['type'] === 'product' ? 'produkt' : 'obsah' ?></a><button type="button" class="panel-action panel-button" data-media-open><?= \SimpleStore\Rendering\Icon::render('plus') ?> Přidat fotografie</button><?php endif; ?>
            <form method="post" action="<?= $mediaLink([]) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="media-library-export"><input type="hidden" name="owners[]" value="<?= $escape($activeDirectory['scope']) ?>"><button class="panel-action">Stáhnout fotografie</button></form>
          </div>
        </div>
      </div><?php endif; ?>
      <div class="library-controls">
        <form method="get" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>" class="library-search"><input type="hidden" name="section" value="media"><input type="hidden" name="folder" value="<?= $escape($mediaFolder) ?>"><input type="hidden" name="owner" value="<?= $escape($mediaOptions['owner']) ?>"><input type="hidden" name="view" value="<?= $escape($mediaOptions['view']) ?>"><input type="hidden" name="parent_q" value="<?= $escape($mediaOptions['parent_q']) ?>">
          <label class="sr-only" for="library-q">Hledat produkt nebo obrázek</label><input id="library-q" name="q" type="search" maxlength="200" placeholder="<?= $folderView ? 'Název produktu, stránky nebo fotografie…' : 'Hledat fotografii nebo produkt…' ?>" value="<?= $escape($mediaSearch) ?>">
          <label class="sr-only" for="library-sort">Řazení</label><select id="library-sort" name="sort"><?php foreach (\SimpleStore\Media\MediaBrowse::SORTS as $value => $label): ?><option value="<?= $escape($value) ?>" <?= $mediaOptions['sort'] === $value ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select><button class="panel-action">Hledat</button>
          <?php if ($mediaSearch !== ''): ?><a class="panel-icon-link" href="<?= $mediaPageLink(['q' => '', 'offset' => 0]) ?>">Zrušit hledání</a><?php endif; ?>
        </form>
        <?php if (isset(\SimpleStore\Media\MediaOwners::TYPES[$mediaFolder]) && $mediaOptions['owner'] === ''): ?><nav class="library-view-switch" aria-label="Zobrazení médií"><a href="<?= $mediaPageLink(['view' => 'folders', 'offset' => 0]) ?>" <?= $folderView ? 'aria-current="page"' : '' ?>><?= \SimpleStore\Rendering\Icon::render('folder') ?> Složky</a><a href="<?= $mediaPageLink(['view' => 'photos', 'offset' => 0]) ?>" <?= !$folderView ? 'aria-current="page"' : '' ?>><?= \SimpleStore\Rendering\Icon::render('image') ?> Obrázky</a></nav><?php endif; ?>
      </div>
      <?php if ($targetFolder !== ''): ?><details class="library-tool"><summary>Upravit tuto složku</summary><form method="post" action="<?= $mediaLink([]) ?>" class="panel-form library-form-row"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="media-library-folder"><input type="hidden" name="folder_id" value="<?= $escape($targetFolder) ?>"><label>Název <input name="name" maxlength="120" required value="<?= $escape($mediaState['folders'][$targetFolder]['name']) ?>"></label><label>Nadřazená složka <select name="folder"><?php $folderOptions($mediaState['folders'][$targetFolder]['parent']); ?></select></label><button class="panel-action">Uložit složku</button></form></details><?php endif; ?>
      <form id="media-selection" method="post" action="<?= $mediaLink([]) ?>" class="library-selection">
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
        <label><input type="checkbox" data-library-select-all> Vybrat zobrazené <?= $folderView ? 'složky' : 'obrázky' ?></label><span data-library-selection-count aria-live="polite">0 vybráno</span><button type="button" class="library-clear-selection" data-library-clear-selection hidden>Zrušit výběr</button>
        <label><span class="sr-only">Cílová složka</span><select name="folder"><?php $folderOptions($targetFolder); ?></select></label>
        <button name="action" value="media-library-move" class="panel-action" data-library-requires-selection><?= $folderView ? 'Zařadit fotografie' : 'Zařadit do složky' ?></button>
        <button name="action" value="media-library-export" class="panel-action" data-library-requires-selection><?= \SimpleStore\Rendering\Icon::render('download') ?> Export vybraných</button>
      </form>
      <p data-library-status class="library-copy-status" role="status" aria-live="polite"></p>
      <?php if ($folderView): ?><p class="library-selection-help">Výběr složky zahrne všechny její fotografie. Zařazení do vlastní složky zachová jejich vazbu na původní produkt i adresy.</p>
      <div class="library-directory-grid">
        <?php foreach ($mediaListing['directories'] as $directory): ?>
        <article class="library-directory" data-library-directory="<?= $escape($directory['scope']) ?>">
          <label class="library-card-select"><input form="media-selection" type="checkbox" name="owners[]" value="<?= $escape($directory['scope']) ?>" data-photo-count="<?= (int) $directory['count'] ?>" aria-label="Vybrat složku <?= $escape($directory['title']) ?>"></label>
          <a href="<?= $mediaLink(['folder' => $directory['group'], 'owner' => $directory['key'], 'sort' => $mediaOptions['sort'], 'parent_q' => $mediaSearch]) ?>" class="library-directory-open">
            <div class="library-directory-art"><span class="library-directory-tab"><?= \SimpleStore\Rendering\Icon::render('folder') ?></span><img src="<?= $escape($basePath . $directory['thumb']) ?>" alt="" loading="lazy" decoding="async"></div>
            <div class="library-directory-body"><strong><?= $escape($directory['title']) ?></strong><span>Fotografie: <?= (int) $directory['count'] ?> · <?= $escape(number_format($directory['bytes'] / 1048576, 1, ',', ' ')) ?> MiB</span>
              <small><?= $directory['owner'] === null ? 'Původní obsah odstraněn · ' . $escape(substr($directory['key'], 0, 8)) : ($directory['owner']['published'] ? 'Publikováno' : 'Koncept') ?></small><b>Otevřít složku <?= \SimpleStore\Rendering\Icon::render('arrow-right') ?></b>
            </div>
          </a>
        </article>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="library-grid">
      <?php foreach ($mediaListing['files'] as $file): ?>
        <article class="library-card" data-library-file><div class="library-card-image"><label class="library-card-select"><input form="media-selection" type="checkbox" name="paths[]" value="<?= $escape($file['path']) ?>" aria-label="Vybrat <?= $escape($file['label']) ?>"></label>
          <a href="<?= $escape($basePath . $file['path']) ?>" target="_blank" rel="noopener" data-library-preview="<?= $escape($basePath . $file['card']) ?>" data-preview-title="<?= $escape($file['label']) ?>" data-preview-original="<?= $escape($basePath . $file['path']) ?>" aria-label="Otevřít obrázek <?= $escape($file['label']) ?>"><img loading="lazy" decoding="async" src="<?= $escape($basePath . $file['thumb']) ?>" alt="<?= $escape($file['label']) ?>"></a></div>
          <div class="library-card-body"><strong title="<?= $escape($file['label']) ?>"><?= $escape($file['label']) ?></strong><small><?= $escape(($file['owner_title'] ?? '') ?: ($mediaFolders[$file['folder']] ?? 'Bez vlastní složky')) ?> · <?= (int) ceil($file['bytes'] / 1024) ?> kB</small>
            <details><summary>Adresa a velikosti</summary><label>Adresa obrázku <input readonly data-library-url value="<?= $escape($basePath . $file['path']) ?>"></label>
              <?php foreach (['path' => 'Velký obrázek', 'card' => 'Karta', 'thumb' => 'Miniatura'] as $size => $label): ?><button type="button" class="library-copy" data-library-copy="<?= $escape($basePath . $file[$size]) ?>">Kopírovat: <?= $label ?></button><?php endforeach; ?>
            </details><button type="button" class="library-copy library-copy-primary" data-library-copy="<?= $escape($basePath . $file['path']) ?>"><?= \SimpleStore\Rendering\Icon::render('copy') ?> Kopírovat adresu</button>
          </div></article>
      <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($mediaListing['total'] === 0): ?><div class="library-empty"><?= \SimpleStore\Rendering\Icon::render('image') ?><h3><?= $folderView ? 'Žádné složky neodpovídají výběru' : 'Zatím žádné obrázky' ?></h3><p>Nahraj fotografie nebo zkus jinou složku a hledání.</p></div><?php endif; ?>
      <nav class="library-pagination" aria-label="Stránkování médií">
        <?php if ($mediaOffset > 0): ?><a class="panel-icon-link" href="<?= $mediaPageLink(['offset' => max(0, $mediaOffset - 48)]) ?>">← Předchozí</a><?php endif; ?>
        <?php if ($mediaOffset + 48 < $mediaListing['total']): ?><a class="panel-icon-link" href="<?= $mediaPageLink(['offset' => $mediaOffset + 48]) ?>">Další →</a><?php endif; ?>
        <?php if ($mediaListing['total'] > 0): ?><span><?= $mediaOffset + 1 ?>–<?= min($mediaOffset + 48, $mediaListing['total']) ?> z <?= (int) $mediaListing['total'] ?></span><?php endif; ?>
      </nav>
    </section>
    <details class="panel-panel library-transfer" id="library-upload"><summary><span class="library-feature-icon"><?= \SimpleStore\Rendering\Icon::render('upload') ?></span><span><strong>Nahrát do společné knihovny</strong><small>Nové fotografie a automatické miniatury</small></span><span aria-hidden="true"><?= \SimpleStore\Rendering\Icon::render('plus') ?></span></summary><div class="library-upload"><div><span class="library-feature-icon"><?= \SimpleStore\Rendering\Icon::render('upload') ?></span><h2>Nahrát fotografie</h2><p>Automaticky připravíme velký obrázek, kartu a miniaturu. Fotografie zůstane v knihovně, dokud ji sám nevybereš v obsahu.</p></div>
      <form method="post" action="<?= $mediaLink([]) ?>" enctype="multipart/form-data" class="panel-form" data-library-upload>
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="media-library-upload">
        <label>Vybrat obrázky <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></label>
        <div class="library-form-row"><label>Cílová složka <select name="folder"><?php $folderOptions($targetFolder); ?></select></label><button class="panel-action panel-button">Nahrát fotografie</button></div>
        <small>JPG, PNG, WebP · až 20 souborů · každý do 12 MiB / 20 Mpx. Hosting: soubor <?= $escape(ini_get('upload_max_filesize')) ?>, celý požadavek <?= $escape(ini_get('post_max_size')) ?>.</small>
      </form>
    </div></details>
    <details class="panel-panel library-backup library-transfer" id="library-backup"><summary><span class="library-feature-icon"><?= \SimpleStore\Rendering\Icon::render('download') ?></span><span><strong>Export a import složek</strong><small>Zálohy fotografií a přenos mezi knihovnami</small></span><span aria-hidden="true"><?= \SimpleStore\Rendering\Icon::render('plus') ?></span></summary><div><div><p class="panel-eyebrow">Zálohy & přenos</p><h2>Export a import složek</h2><p>ZIP obsahuje fotografie a strom složek. Při importu se miniatury vytvoří znovu; stejné snímky a složky se neduplikují. Přiřazení k produktům a článkům se nemění.</p></div>
      <div class="library-backup-columns"><form method="post" action="<?= $mediaLink([]) ?>" class="panel-form"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="media-library-export"><h3>Stáhnout kolekci</h3>
        <div class="library-folder-checks"><label><input type="checkbox" name="folders[]" value="all"> Celá knihovna</label><?php foreach (\SimpleStore\Media\MediaCatalog::GROUPS + $mediaFolders as $id => $label): if ($id === 'all') continue; ?><label><input type="checkbox" name="folders[]" value="<?= $escape($id) ?>"> <?= $escape($label) ?></label><?php endforeach; ?></div>
        <small>Včetně podsložek. Nejvýše 200 obrázků / 100 MiB v jednom exportu.</small><button class="panel-action panel-button">Stáhnout ZIP</button></form>
        <form method="post" action="<?= $mediaLink([]) ?>" enctype="multipart/form-data" class="panel-form"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="media-library-import"><h3>Obnovit kolekci</h3><label>ZIP vytvořený exportem <input type="file" name="archive" accept=".zip,application/zip" required></label><small>Nejvýše 100 MiB, podle limitu hostingu. Nové fotografie dostanou vlastní bezpečné adresy. Import neobnovuje stránky ani produkty; jejich zálohy jsou samostatné.</small><button class="panel-action panel-button">Importovat ZIP</button></form>
      </div>
    </div></details>
  </div>
</div>
<dialog class="library-preview" data-library-preview-dialog aria-labelledby="library-preview-title"><div class="library-preview-head"><h2 id="library-preview-title"></h2><button type="button" data-library-preview-close aria-label="Zavřít náhled">×</button></div><img data-library-preview-image alt=""><div class="library-preview-actions"><button type="button" class="panel-action" data-library-preview-previous>← Předchozí</button><span data-library-preview-position></span><button type="button" class="panel-action" data-library-preview-next>Další →</button><a data-library-preview-original target="_blank" rel="noopener">Otevřít velký obrázek</a><button type="button" class="panel-action" data-library-preview-copy>Kopírovat adresu</button></div><p data-library-preview-status role="status"></p></dialog>
<?php if ($activeOwner !== null): ?>
<script type="application/json" id="media-page-config"><?= json_encode(['endpoint' => $adminUrl, 'basePath' => $basePath, 'csrf' => $csrf,
    'type' => $activeOwner['type'], 'key' => $activeOwner['key'], 'language' => $activeOwner['language'], 'revision' => (int) $activeOwner['revision_number'],
    'defaultMode' => $activeOwner['type'] === 'product' ? 'gallery-add' : 'section-add-image', 'reloadAfterUpload' => true, 'files' => []], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<?php require __DIR__ . '/../media/picker.php'; ?>
<?php endif; ?>
<script src="<?= $escape($basePath) ?>assets/media-library.js?v=<?= filemtime(__DIR__ . '/../../assets/media-library.js') ?>" defer></script>
