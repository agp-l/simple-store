<?php
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$mediaLink = static fn (array $query): string => \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?' . http_build_query(['section' => 'media'] + $query, '', '&', PHP_QUERY_RFC3986));
$mediaTitle = \SimpleStore\Media\MediaCatalog::GROUPS[$mediaFolder] ?? $mediaFolders[$mediaFolder] ?? 'Knihovna';
$targetFolder = isset($mediaFolders[$mediaFolder]) ? $mediaFolder : '';
$folderOptions = static function (string $selected = '') use ($mediaFolders, $escape): void { ?>
  <option value="">Bez vlastní složky</option><?php foreach ($mediaFolders as $id => $label): ?><option value="<?= $escape($id) ?>" <?= $id === $selected ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach;
};
?>
<div class="panel-intro"><div><p class="panel-eyebrow">Obsah & fotografie</p><h1>Knihovna médií</h1><p>Jedno místo pro fotografie produktů, stránek i blogu. Nahraj, uspořádej a použij obrázek tam, kde ho potřebuješ.</p></div>
  <a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=contents') ?>"><?= \SimpleStore\Rendering\Icon::render('document') ?> Stránky a články</a>
</div>
<?php if ($mediaError !== ''): ?><p class="panel-error" role="alert"><?= $escape($mediaError) ?></p><?php endif; ?>
<?php if ($mediaNotice !== ''): ?><p class="library-notice" role="status"><?= $escape($mediaNotice) ?></p><?php endif; ?>
<div class="library-workspace">
  <aside class="library-sidebar" aria-label="Složky médií"><h2>Knihovna</h2>
    <nav><?php foreach (\SimpleStore\Media\MediaCatalog::GROUPS as $id => $label): ?><a href="<?= $mediaLink(['folder' => $id]) ?>" <?= $mediaFolder === $id ? 'aria-current="page"' : '' ?>><?= \SimpleStore\Rendering\Icon::render($id === 'all' ? 'image' : 'folder') ?><span><?= $escape($label) ?></span></a><?php endforeach; ?></nav>
    <h2>Moje složky <span><?= count($mediaFolders) ?></span></h2>
    <nav><?php foreach ($mediaFolders as $id => $label): ?><a href="<?= $mediaLink(['folder' => $id]) ?>" <?= $mediaFolder === $id ? 'aria-current="page"' : '' ?>><?= \SimpleStore\Rendering\Icon::render('folder') ?><span><?= $escape($label) ?></span></a><?php endforeach; ?></nav>
    <?php if ($mediaFolders === []): ?><p>Vytvoř si složky například pro sezónu, dodavatele nebo připravovaný článek.</p><?php endif; ?>
    <details class="library-tool"><summary>+ Nová složka</summary><form method="post" action="<?= $mediaLink([]) ?>" class="panel-form">
      <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="media-library-folder">
      <label>Název <input name="name" maxlength="120" required placeholder="Například Podzimní výpravy"></label>
      <label>Umístit do <select name="folder"><option value="">Hlavní úroveň</option><?php foreach ($mediaFolders as $id => $label): ?><option value="<?= $escape($id) ?>"><?= $escape($label) ?></option><?php endforeach; ?></select></label>
      <button class="panel-action panel-button">Vytvořit složku</button></form></details>
    <p class="library-hint">Složky slouží k organizaci. Zařazení ani přejmenování nemění adresy obrázků nebo jejich použití na webu.</p>
  </aside>
  <div class="library-main">
    <section class="library-upload panel-panel"><div><span class="library-feature-icon"><?= \SimpleStore\Rendering\Icon::render('upload') ?></span><h2>Nahrát fotografie</h2><p>Automaticky připravíme velký obrázek, kartu a miniaturu. Fotografie zůstane v knihovně, dokud ji sám nevybereš v obsahu.</p></div>
      <form method="post" action="<?= $mediaLink([]) ?>" enctype="multipart/form-data" class="panel-form" data-library-upload>
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="media-library-upload">
        <label>Vybrat obrázky <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></label>
        <div class="library-form-row"><label>Cílová složka <select name="folder"><?php $folderOptions($targetFolder); ?></select></label><button class="panel-action panel-button">Nahrát fotografie</button></div>
        <small>JPG, PNG, WebP · až 20 souborů · každý do 12 MiB / 20 Mpx. Hosting: soubor <?= $escape(ini_get('upload_max_filesize')) ?>, celý požadavek <?= $escape(ini_get('post_max_size')) ?>.</small>
      </form>
    </section>
    <section class="panel-panel library-results" aria-labelledby="library-title">
      <div class="library-heading"><div><p class="panel-eyebrow">Přehled fotografií</p><h2 id="library-title"><?= $escape($mediaTitle) ?></h2><p><?= (int) $mediaListing['total'] ?> obrázků<?= $mediaSearch !== '' ? ' odpovídá hledání' : '' ?> · náhledy se nezapočítávají zvlášť</p></div>
        <form method="get" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>" class="library-search"><input type="hidden" name="section" value="media"><input type="hidden" name="folder" value="<?= $escape($mediaFolder) ?>"><label class="sr-only" for="library-q">Hledat obrázek</label><input id="library-q" name="q" type="search" maxlength="200" placeholder="Hledat podle názvu…" value="<?= $escape($mediaSearch) ?>"><button class="panel-action">Hledat</button></form>
      </div>
      <?php if ($targetFolder !== ''): ?><details class="library-tool"><summary>Upravit tuto složku</summary><form method="post" action="<?= $mediaLink([]) ?>" class="panel-form library-form-row"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="media-library-folder"><input type="hidden" name="folder_id" value="<?= $escape($targetFolder) ?>"><label>Název <input name="name" maxlength="120" required value="<?= $escape($mediaState['folders'][$targetFolder]['name']) ?>"></label><label>Nadřazená složka <select name="folder"><?php $folderOptions($mediaState['folders'][$targetFolder]['parent']); ?></select></label><button class="panel-action">Uložit složku</button></form></details><?php endif; ?>
      <form id="media-selection" method="post" action="<?= $mediaLink([]) ?>" class="library-selection">
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
        <label><input type="checkbox" data-library-select-all> Vybrat zobrazené</label><span data-library-selection-count aria-live="polite">0 vybráno</span>
        <label><span class="sr-only">Cílová složka</span><select name="folder"><?php $folderOptions($targetFolder); ?></select></label>
        <button name="action" value="media-library-move" class="panel-action" data-library-requires-selection>Zařadit do složky</button>
        <button name="action" value="media-library-export" class="panel-action" data-library-requires-selection><?= \SimpleStore\Rendering\Icon::render('download') ?> Export vybraných</button>
      </form>
      <p data-library-status class="library-copy-status" role="status" aria-live="polite"></p>
      <div class="library-grid">
      <?php foreach ($mediaListing['files'] as $file): ?>
        <article class="library-card"><div class="library-card-image"><label class="library-card-select"><input form="media-selection" type="checkbox" name="paths[]" value="<?= $escape($file['path']) ?>" aria-label="Vybrat <?= $escape($file['label']) ?>"></label>
          <a href="<?= $escape($basePath . $file['path']) ?>" target="_blank" rel="noopener" aria-label="Otevřít obrázek <?= $escape($file['label']) ?>"><img loading="lazy" decoding="async" src="<?= $escape($basePath . $file['thumb']) ?>" alt="<?= $escape($file['label']) ?>"></a></div>
          <div class="library-card-body"><strong title="<?= $escape($file['label']) ?>"><?= $escape($file['label']) ?></strong><small><?= $escape($mediaFolders[$file['folder']] ?? 'Bez vlastní složky') ?> · <?= (int) ceil($file['bytes'] / 1024) ?> kB</small>
            <details><summary>Adresa a velikosti</summary><label>Adresa obrázku <input readonly data-library-url value="<?= $escape($basePath . $file['path']) ?>"></label>
              <?php foreach (['path' => 'Velký obrázek', 'card' => 'Karta', 'thumb' => 'Miniatura'] as $size => $label): ?><button type="button" class="library-copy" data-library-copy="<?= $escape($basePath . $file[$size]) ?>">Kopírovat: <?= $label ?></button><?php endforeach; ?>
            </details><button type="button" class="library-copy library-copy-primary" data-library-copy="<?= $escape($basePath . $file['path']) ?>"><?= \SimpleStore\Rendering\Icon::render('copy') ?> Kopírovat adresu</button>
          </div></article>
      <?php endforeach; ?>
      </div>
      <?php if ($mediaListing['files'] === []): ?><div class="library-empty"><?= \SimpleStore\Rendering\Icon::render('image') ?><h3>Zatím žádné obrázky</h3><p>Nahraj fotografie nebo zkus jinou složku a hledání.</p></div><?php endif; ?>
      <nav class="library-pagination" aria-label="Stránkování médií">
        <?php if ($mediaOffset > 0): ?><a class="panel-icon-link" href="<?= $mediaLink(['folder' => $mediaFolder, 'q' => $mediaSearch, 'offset' => max(0, $mediaOffset - 48)]) ?>">← Předchozí</a><?php endif; ?>
        <?php if ($mediaOffset + 48 < $mediaListing['total']): ?><a class="panel-icon-link" href="<?= $mediaLink(['folder' => $mediaFolder, 'q' => $mediaSearch, 'offset' => $mediaOffset + 48]) ?>">Další →</a><?php endif; ?>
      </nav>
    </section>
    <section class="panel-panel library-backup"><div><p class="panel-eyebrow">Zálohy & přenos</p><h2>Export a import složek</h2><p>ZIP obsahuje fotografie a strom složek. Při importu se miniatury vytvoří znovu; stejné snímky a složky se neduplikují. Přiřazení k produktům a článkům se nemění.</p></div>
      <div class="library-backup-columns"><form method="post" action="<?= $mediaLink([]) ?>" class="panel-form"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="media-library-export"><h3>Stáhnout kolekci</h3>
        <div class="library-folder-checks"><label><input type="checkbox" name="folders[]" value="all"> Celá knihovna</label><?php foreach (\SimpleStore\Media\MediaCatalog::GROUPS + $mediaFolders as $id => $label): if ($id === 'all') continue; ?><label><input type="checkbox" name="folders[]" value="<?= $escape($id) ?>"> <?= $escape($label) ?></label><?php endforeach; ?></div>
        <small>Včetně podsložek. Nejvýše 200 obrázků / 100 MiB v jednom exportu.</small><button class="panel-action panel-button">Stáhnout ZIP</button></form>
        <form method="post" action="<?= $mediaLink([]) ?>" enctype="multipart/form-data" class="panel-form"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="media-library-import"><h3>Obnovit kolekci</h3><label>ZIP vytvořený exportem <input type="file" name="archive" accept=".zip,application/zip" required></label><small>Nejvýše 100 MiB, podle limitu hostingu. Nové fotografie dostanou vlastní bezpečné adresy. Import neobnovuje stránky ani produkty; jejich zálohy jsou samostatné.</small><button class="panel-action panel-button">Importovat ZIP</button></form>
      </div>
    </section>
  </div>
</div>
<script src="<?= $escape($basePath) ?>assets/media-library.js?v=<?= filemtime(__DIR__ . '/../../assets/media-library.js') ?>" defer></script>
