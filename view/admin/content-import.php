<div class="panel-intro">
  <div><p class="panel-eyebrow">Obsah webu</p><h1>Import a export stránek a článků</h1>
    <p>Stáhni dokument jako JSON, uprav ho třeba pomocí AI a nahraj zpět. Můžeš také vytvořit nový koncept.</p></div>
  <div class="panel-quick">
    <a class="panel-icon-link" href="<?= $escape($adminUrl . '?section=contents') ?>">Stránky</a>
    <a class="panel-icon-link" href="<?= $escape($basePath . $site['default_language'] . '/blog?manage=1') ?>">Články</a>
    <a class="panel-icon-link" href="<?= $escape($adminUrl . '?section=product-import') ?>">Import produktů</a>
  </div>
</div>
<section class="panel-panel">
  <?php if ($importError !== ''): ?><p class="panel-error" role="alert"><?= $escape($importError) ?></p><?php endif; ?>
  <?php if ($exportUrl !== ''): ?><p><a class="panel-action panel-icon-link" href="<?= $escape($exportUrl) ?>"><?= \SimpleStore\Rendering\Icon::render('download') ?> Stáhnout aktuální JSON</a></p><?php endif; ?>
  <form class="panel-form" method="post" enctype="multipart/form-data" action="<?= $escape($adminUrl . '?section=content-import') ?>">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <label>Způsob importu
      <select name="mode">
        <option value="new" <?= $importMode === 'new' ? 'selected' : '' ?>>Nový koncept</option>
        <option value="update" <?= $importMode === 'update' ? 'selected' : '' ?>>Aktualizovat existující stránku nebo článek</option>
      </select>
    </label>
    <p class="panel-help">Nový koncept bude skrytý. Aktualizace uloží novou revizi dokumentu určeného exportem a zachová jeho historii.</p>
    <label>Soubor JSON (nejvýše 4 MiB)<input type="file" name="json_file" accept=".json,application/json"></label>
    <label for="content-json">Nebo vlož JSON přímo</label>
    <textarea id="content-json" name="json" rows="20" spellcheck="false"><?= $escape($importJson) ?></textarea>
    <p class="panel-help">Vybraný soubor nahradí text ve formuláři. Fotografie se přenášejí jako odkazy; při přesunu na jiný web je nahraj také do jeho knihovny.</p>
    <button class="panel-action panel-button" name="action" value="content-import-preview"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit náhled importu</button>
    <?php if ($importPreview !== null): ?>
      <div class="content-import-preview">
        <h2><?= $escape($importPreview['title']) ?></h2>
        <dl>
          <dt>Typ a jazyk</dt><dd><?= $importPreview['type'] === 'post' ? 'Článek' : 'Stránka' ?> · <?= $escape($importPreview['language']) ?></dd>
          <dt>Adresa</dt><dd><?= $escape($basePath . $importPreview['language'] . ($importPreview['type'] === 'post' ? '/blog' : '') . '/' . $importPreview['slug']) ?></dd>
          <dt>Viditelnost po uložení</dt><dd><?= $importPreview['published'] ? 'Publikováno – změny budou vidět návštěvníkům' : 'Skrytý koncept' ?></dd>
          <?php if ($importPreview['type'] === 'page'): ?><dt>Horní odkazy</dt><dd><?= $importPreview['visible_in_menu'] ? 'Zobrazovat' : 'Nezobrazovat' ?> · pořadí <?= (int) $importPreview['menu_order'] ?></dd><?php endif; ?>
          <dt>Úvod</dt><dd><?= nl2br($escape($importPreview['summary'])) ?></dd>
        </dl>
        <details open><summary>Obsah dokumentu</summary>
          <?php foreach (\SimpleStore\Content\ContentBody::decode($importPreview['body']) as $block): ?>
            <p><strong><?= $escape($block['heading'] !== '' ? $block['heading'] : \SimpleStore\Content\ContentBody::TYPES[$block['type']]) ?></strong></p>
            <pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= $escape($block['body']) ?></pre>
          <?php endforeach; ?>
        </details>
      </div>
      <p>Po změně JSON nebo způsobu importu znovu zobraz náhled.</p>
      <input type="hidden" name="preview_token" value="<?= $escape($importToken) ?>">
      <button class="panel-action panel-button" name="action" value="content-import-save"><?= \SimpleStore\Rendering\Icon::render('save') ?> <?= $importMode === 'new' ? 'Vytvořit koncept' : 'Uložit novou revizi' ?></button>
    <?php endif; ?>
  </form>
</section>
