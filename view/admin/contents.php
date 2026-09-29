      <div class="panel-intro">
        <div><p class="panel-eyebrow">Obsah webu</p><h1>Stránky a články</h1>
          <p>Klikni na dokument a upravuj jeho skutečnou stránku. Každá změna vytvoří novou revizi.</p></div>
        <div class="panel-quick"><a href="<?= $escape($adminUrl . '?section=products') ?>">Produkty</a></div>
      </div>
      <div class="panel-grid">
        <section class="panel-panel panel-list" aria-labelledby="panel-list-title">
          <h2 id="panel-list-title">Dokumenty <span><?= count($documents) ?></span></h2>
          <?php if ($documents === []): ?><p class="panel-empty">Zatím nic nenajdeš. Vytvoř první stránku nebo článek.</p><?php endif; ?>
          <?php foreach ($documents as $document): ?>
            <?php $link = $basePath . $document['language'] . ($document['type'] === 'post' ? '/blog' : '') . '/' . rawurlencode($document['slug']) . '?edit=1'; ?>
            <a class="panel-document" href="<?= $escape($link) ?>">
              <strong><?= $escape($document['title']) ?> ↗</strong>
              <span><?= $document['type'] === 'post' ? 'Článek' : 'Stránka' ?> · <?= $escape($document['language']) ?> · <?= $escape($document['slug']) ?></span>
              <small><?= $document['published'] ? 'Publikováno' : 'Koncept' ?> · revize <?= (int) $document['revision_number'] ?></small>
            </a>
            <?php $missingLanguages = array_values(array_diff($site['languages'], $translations[$document['document_key']])); ?>
            <?php if ($missingLanguages !== []): ?>
              <form class="panel-translate" method="post" action="<?= $escape($adminUrl) ?>">
                <input type="hidden" name="action" value="create-translation"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
                <input type="hidden" name="key" value="<?= $escape($document['document_key']) ?>"><input type="hidden" name="source_language" value="<?= $escape($document['language']) ?>">
                <label>Nový překlad <select name="language"><?php foreach ($missingLanguages as $code): ?><option value="<?= $escape($code) ?>"><?= $escape($code) ?></option><?php endforeach; ?></select></label>
                <button type="submit">Vytvořit</button>
              </form>
            <?php endif; ?>
          <?php endforeach; ?>
        </section>
        <section class="panel-panel" aria-labelledby="panel-create-title">
          <div class="panel-section-heading"><p class="panel-eyebrow">Nový koncept</p><h2 id="panel-create-title">Začít přímo na stránce</h2></div>
          <p>Předvyplněný text, seznam, tabulku i obrázek můžeš přepsat nebo odebrat. Nový dokument uvidíš jen ty, dokud ho nezveřejníš.</p>
          <?php foreach (['page' => 'stránku', 'post' => 'článek'] as $type => $label): ?>
            <form class="panel-start-product panel-create-content" method="post" action="<?= $escape($adminUrl) ?>">
              <input type="hidden" name="action" value="create-content"><input type="hidden" name="type" value="<?= $type ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
              <?php if (count($site['languages']) === 1): ?>
                <input type="hidden" name="language" value="<?= $escape($site['default_language']) ?>">
              <?php else: ?>
                <label>Jazyk <select name="language"><?php foreach ($site['languages'] as $code): ?><option value="<?= $escape($code) ?>"><?= $escape($code) ?></option><?php endforeach; ?></select></label>
              <?php endif; ?>
              <button class="panel-button" type="submit">＋ Vytvořit <?= $label ?></button>
            </form>
          <?php endforeach; ?>
        </section>
      </div>
